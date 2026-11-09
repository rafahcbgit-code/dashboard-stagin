<?php

use App\Services\CampaignAudienceService;
use App\Jobs\ProcessCampaignMessagesJob;
use App\Jobs\ProcessCampaignChunkJob;
use App\Jobs\RetryCampaignLogJob;
use App\Jobs\ProcessSingleCampaignLogJob;
use App\Jobs\SendCampaignJob;
use App\Models\CampaignLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

uses(Tests\TestCase::class);

beforeEach(function () {
    config(['database.default'=>'sqlite','database.connections.sqlite.database'=>':memory:']);
    DB::purge('sqlite');
    Schema::create('contacts', function (Blueprint $table) {
        $table->id(); $table->integer('organization_id');
        foreach (['first_name','last_name','phone','email','metadata'] as $field) $table->string($field)->nullable();
        $table->boolean('is_subscribed')->nullable(); $table->boolean('is_blocked')->nullable(); $table->softDeletes();
    });
    Schema::create('contact_groups', function (Blueprint $table) { $table->id(); $table->string('uuid'); $table->integer('organization_id'); $table->softDeletes(); });
    Schema::create('contact_contact_group', function (Blueprint $table) { $table->integer('contact_id'); $table->integer('contact_group_id'); });
    Schema::create('contact_fields', function (Blueprint $table) { $table->id(); $table->integer('organization_id'); $table->string('name'); $table->softDeletes(); });
});

it('counts exclusions once and isolates the selected organization', function () {
    DB::table('contact_groups')->insert(['id'=>1,'uuid'=>'selected','organization_id'=>1]);
    foreach ([[1,0,'+123'],[1,1,'+124'],[0,0,'+125'],[0,1,'+126'],[1,0,''],[null,0,'+127']] as $index => [$subscribed,$blocked,$phone]) {
        DB::table('contacts')->insert(['id'=>$index+1,'organization_id'=>1,'is_subscribed'=>$subscribed,'is_blocked'=>$blocked,'phone'=>$phone]);
        DB::table('contact_contact_group')->insert(['contact_id'=>$index+1,'contact_group_id'=>1]);
    }
    DB::table('contacts')->insert(['id'=>7,'organization_id'=>2,'is_subscribed'=>1,'is_blocked'=>0,'phone'=>'+128']);
    DB::table('contact_contact_group')->insert(['contact_id'=>7,'contact_group_id'=>1]);
    DB::flushQueryLog();
    DB::enableQueryLog();
    $audience=app(CampaignAudienceService::class)->preview(1,'selected');
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();
    expect($queryCount)->toBeLessThanOrEqual(5);
    expect($audience['candidate_count'])->toBe(6)->and($audience['excluded_blocked'])->toBe(2)
        ->and($audience['excluded_unsubscribed'])->toBe(2)->and($audience['excluded_without_phone'])->toBe(1)
        ->and($audience['recipient_count'])->toBe(1)->and(array_column($audience['preview'],'id'))->toBe([1]);
    DB::table('contacts')->where('id',1)->update(['is_subscribed'=>0]);
    expect(app(CampaignAudienceService::class)->preview(1,'selected')['recipient_count'])->toBe(0);
});

it('reserves only the logs selected for dispatch', function () {
    config(['cache.stores.redis'=>['driver'=>'array']]);
    $job=new ProcessCampaignMessagesJob(1);
    $method=new ReflectionMethod($job,'reserveLogsForDispatch');
    $logs=collect(range(1,5))->map(fn ($id)=>(new CampaignLog)->forceFill(['id'=>$id]));
    expect($method->invoke($job,$logs,2)->pluck('id')->all())->toBe([1,2]);
    expect($method->invoke($job,$logs,2)->pluck('id')->all())->toBe([3,4]);
});

it('does not retry a cancelled campaign', function () {
    config(['cache.stores.redis'=>['driver'=>'array']]);
    Schema::create('campaigns', function (Blueprint $table) {
        $table->id(); $table->integer('organization_id'); $table->string('status'); $table->softDeletes();
    });
    Schema::create('campaign_logs', function (Blueprint $table) {
        $table->id(); $table->integer('campaign_id'); $table->string('status');
    });
    DB::table('campaigns')->insert(['id'=>1,'organization_id'=>1,'status'=>'cancelled']);
    DB::table('campaign_logs')->insert(['id'=>30,'campaign_id'=>1,'status'=>'failed']);
    (new RetryCampaignLogJob(1,30,0))->handle();
    expect(DB::table('campaign_logs')->where('id',30)->value('status'))->toBe('failed');
});

it('shares the sending lock with legacy and single-message paths', function () {
    config(['cache.stores.redis'=>['driver'=>'array']]);
    $cache=Illuminate\Support\Facades\Cache::store('redis');
    $held=$cache->lock('campaign:log:31:sending',660);
    expect($held->get())->toBeTrue();
    $single=new ProcessSingleCampaignLogJob(31,1,1,1,1,1,'contact');
    expect($single->retryUntil()->isFuture())->toBeTrue();
    $single->handle();
    $legacy=new SendCampaignJob();
    $log=(new CampaignLog)->forceFill(['id'=>31]);
    (new ReflectionMethod($legacy,'sendTemplateMessage'))->invoke($legacy,$log);
    (new ReflectionMethod($legacy,'sendRetryLogTemplateMessage'))->invoke($legacy,$log);
    expect($cache->lock('campaign:log:31:sending',660)->get())->toBeFalse();
    $held->release();
});

it('does not select an overlapping log held by another sending worker', function () {
    config(['cache.stores.redis'=>['driver'=>'array']]);
    Schema::create('campaign_logs', function (Blueprint $table) {
        $table->id(); $table->integer('campaign_id'); $table->string('status');
    });
    $cache = Illuminate\Support\Facades\Cache::store('redis');
    $held = $cache->lock('campaign:log:2:sending', 660);
    expect($held->get())->toBeTrue();
    DB::flushQueryLog();
    DB::enableQueryLog();
    (new ProcessCampaignChunkJob([1,2],1,1,1,1))->handle();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    $query = collect($queries)->first(fn ($query) => str_contains($query['query'], 'campaign_logs'));
    expect($query)->not->toBeNull()->and($query['bindings'])->toContain(1)->not->toContain(2);
    expect($cache->lock('campaign:log:1:sending', 660)->get())->toBeTrue();
    expect($cache->lock('campaign:log:2:sending', 660)->get())->toBeFalse();
    $held->release();
});

it('does not resend an ongoing record on a repeated queue delivery', function () {
    config(['cache.stores.redis'=>['driver'=>'array']]);
    Schema::create('campaign_logs', function (Blueprint $table) {
        $table->id(); $table->integer('campaign_id'); $table->string('status');
    });
    DB::table('campaign_logs')->insert(['id'=>20,'campaign_id'=>1,'status'=>'ongoing']);
    (new ProcessCampaignChunkJob([20],1,1,1,1))->handle();
    expect(DB::table('campaign_logs')->where('id',20)->value('status'))->toBe('ongoing');
});

it('creates logs across bounded batches and does not duplicate them when repeated', function () {
    Schema::create('campaign_logs', function (Blueprint $table) {
        $table->id(); $table->integer('campaign_id'); $table->integer('contact_id'); $table->string('status'); $table->timestamps();
    });
    foreach (array_chunk(range(1,1003),200) as $ids) {
        DB::table('contacts')->insert(array_map(fn ($id)=>['id'=>$id,'organization_id'=>1,'is_subscribed'=>1,'is_blocked'=>0,'phone'=>'+123'.$id],$ids));
    }
    DB::table('contacts')->where('id',1002)->update(['is_blocked'=>1]);
    DB::table('contacts')->where('id',1003)->update(['is_subscribed'=>0]);
    $campaign=(new App\Models\Campaign)->forceFill(['id'=>1,'organization_id'=>1,'contact_group_id'=>0]);
    $job=new App\Jobs\CreateCampaignLogsJob(1);
    $query=new ReflectionMethod($job,'getContactsForCampaign');
    $create=new ReflectionMethod($job,'createCampaignLogs');
    expect($create->invoke($job,$campaign,$query->invoke($job,$campaign)))->toBeTrue();
    expect(DB::table('campaign_logs')->count())->toBe(1001);
    expect($create->invoke($job,$campaign,$query->invoke($job,$campaign)))->toBeFalse();
    expect(DB::table('campaign_logs')->count())->toBe(1001);
});
