<?php

use App\Services\ReportService;
use App\Services\WorkingHoursService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(Tests\TestCase::class);

beforeEach(function () {
    config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
    DB::purge('sqlite');
    Schema::create('organizations', function (Blueprint $t) { $t->id(); $t->text('metadata')->nullable(); $t->softDeletes(); });
    Schema::create('users', function (Blueprint $t) { $t->id(); $t->string('first_name'); $t->string('last_name'); $t->softDeletes(); });
    Schema::create('teams', function (Blueprint $t) { $t->id(); $t->integer('organization_id'); $t->integer('user_id'); $t->string('role')->default('admin'); $t->softDeletes(); });
    Schema::create('contacts', function (Blueprint $t) { $t->id(); $t->string('uuid')->nullable(); $t->integer('organization_id'); $t->softDeletes(); });
    Schema::create('chat_tickets', function (Blueprint $t) { $t->id(); $t->integer('contact_id'); $t->integer('organization_id'); $t->integer('assigned_to')->nullable(); $t->string('status'); $t->timestamp('created_at')->nullable(); $t->timestamp('updated_at')->nullable(); });
    Schema::create('chats', function (Blueprint $t) { $t->id(); $t->integer('contact_id'); $t->integer('organization_id'); $t->integer('user_id')->nullable(); $t->string('type'); $t->string('status'); $t->timestamp('created_at'); });
    Schema::create('chat_ticket_logs', function (Blueprint $t) { $t->id(); $t->integer('contact_id'); $t->string('description'); $t->timestamp('created_at'); $t->timestamp('updated_at')->nullable(); });
    Schema::create('chat_logs', function (Blueprint $t) { $t->id(); $t->integer('contact_id'); $t->string('entity_type'); $t->integer('entity_id'); $t->timestamps(); });
    DB::table('organizations')->insert(['id' => 1, 'metadata' => '{}']);
    DB::table('users')->insert([['id'=>1,'first_name'=>'Alice','last_name'=>'A'],['id'=>2,'first_name'=>'Bob','last_name'=>'B']]);
    DB::table('teams')->insert([['id'=>1,'organization_id'=>1,'user_id'=>1],['id'=>2,'organization_id'=>1,'user_id'=>2]]);
    DB::table('contacts')->insert(['id'=>1,'uuid'=>'contact-1','organization_id'=>1]);
    DB::table('chat_tickets')->insert(['id'=>1,'contact_id'=>1,'organization_id'=>1,'assigned_to'=>2,'status'=>'closed','created_at'=>'2026-10-26 08:00:00','updated_at'=>'2026-10-27 11:00:00']);
});

function reportMessage(string $time, string $type, ?int $user = null, string $status = 'sent'): void {
    DB::table('chats')->insert(['contact_id'=>1,'organization_id'=>1,'user_id'=>$user,'type'=>$type,'status'=>$status,'created_at'=>$time]);
}
function reportEvent(string $time, string $description): void {
    DB::table('chat_ticket_logs')->insert(['contact_id'=>1,'description'=>'Conversation was moved from '.$description,'created_at'=>$time]);
}

it('attributes responses to their sender across close, reopen, reassignment and failed sends', function () {
    reportMessage('2026-10-26 09:00:00','inbound');
    reportMessage('2026-10-26 09:01:00','outbound',1,'failed');
    reportMessage('2026-10-26 09:02:00','outbound',1);
    reportMessage('2026-10-26 09:05:00','inbound');
    reportMessage('2026-10-26 09:06:00','outbound',2);
    reportEvent('2026-10-26 09:07:00','opened to closed');
    reportMessage('2026-10-27 10:00:00','inbound');
    reportEvent('2026-10-27 10:00:01','closed to open');
    reportMessage('2026-10-27 10:03:00','outbound',2);
    reportEvent('2026-10-27 11:00:00','opened to closed');
    $rows = collect((new ReportService(1))->agentReports(Carbon::parse('2026-10-26'), Carbon::parse('2026-10-27 23:59:59')))->keyBy('agent_name');
    expect($rows['Alice A']['avg_first_response_time'])->toBe('2m')
        ->and($rows['Alice A']['avg_customer_waiting_time'])->toBe('2m')
        ->and($rows['Bob B']['avg_first_response_time'])->toBe('3m')
        ->and($rows['Bob B']['avg_customer_waiting_time'])->toBe('2m')
        ->and($rows['Bob B']['closed_conversations_count'])->toBe(1);
    $today = collect((new ReportService(1))->agentReports(Carbon::parse('2026-10-27'), Carbon::parse('2026-10-27 23:59:59')))->keyBy('agent_name');
    expect($today['Alice A']['avg_first_response_time'])->toBe('-')
        ->and($today['Bob B']['avg_first_response_time'])->toBe('3m');
});

it('counts same second replies and excludes unanswered and automatic messages', function () {
    reportMessage('2026-10-26 09:00:00','inbound');
    reportMessage('2026-10-26 09:00:00','outbound',null);
    reportMessage('2026-10-26 09:00:00','outbound',1);
    reportMessage('2026-10-26 09:05:00','inbound');
    $rows = collect((new ReportService(1))->agentReports())->keyBy('agent_name');
    expect($rows['Alice A']['avg_first_response_time'])->toBe('0s')
        ->and($rows['Alice A']['avg_customer_waiting_time'])->toBe('0s')
        ->and($rows['Bob B']['avg_customer_waiting_time'])->toBe('-');
});

it('uses the unified log to order a reply and close in the same second', function () {
    reportMessage('2026-10-26 09:00:00', 'inbound');
    reportMessage('2026-10-26 09:01:00', 'outbound', 1);
    reportEvent('2026-10-26 09:01:00', 'opened to closed');
    DB::table('chat_logs')->insert([
        ['contact_id' => 1, 'entity_type' => 'chat', 'entity_id' => 1],
        ['contact_id' => 1, 'entity_type' => 'chat', 'entity_id' => 2],
        ['contact_id' => 1, 'entity_type' => 'ticket', 'entity_id' => 1],
    ]);
    $rows = collect((new ReportService(1))->agentReports())->keyBy('agent_name');
    expect($rows['Alice A']['avg_first_response_time'])->toBe('1m');
});

it('checks overnight work against the previous day and inactive days', function () {
    DB::table('organizations')->where('id',1)->update(['metadata'=>json_encode(['enable_working_hours'=>true,'timezone'=>'UTC','working_hours'=>[
        ['day'=>'Monday','active'=>true,'from'=>'22:00','to'=>'06:00'],
        ['day'=>'Tuesday','active'=>false,'from'=>'09:00','to'=>'17:00'],
    ]])]);
    Carbon::setTestNow(Carbon::parse('2026-10-27 02:00:00','UTC'));
    expect((new WorkingHoursService())->isWithinWorkingHours(1))->toBeTrue();
    Carbon::setTestNow(Carbon::parse('2026-10-27 07:00:00','UTC'));
    expect((new WorkingHoursService())->isWithinWorkingHours(1))->toBeFalse();
    Carbon::setTestNow();
});

it('preserves the assignee and records only real manual status transitions', function () {
    $this->actingAs(\App\Models\User::find(1));
    session()->put('current_organization', 1);
    $controller = new \App\Http\Controllers\User\ChatTicketController();
    $controller->update(\Illuminate\Http\Request::create('/', 'PUT', ['status' => 'open']), 'contact-1');
    $controller->update(\Illuminate\Http\Request::create('/', 'PUT', ['status' => 'open']), 'contact-1');
    expect(DB::table('chat_tickets')->where('id', 1)->value('assigned_to'))->toBe(2)
        ->and(DB::table('chat_tickets')->where('id', 1)->value('status'))->toBe('open')
        ->and(DB::table('chat_ticket_logs')->count())->toBe(1)
        ->and(DB::table('chat_logs')->count())->toBe(1);
});

it('keeps assignment and avoids duplicate transition logs through the API', function () {
    $request = \Illuminate\Http\Request::create('/', 'PUT', ['organization_id' => 1, 'status' => 'open']);
    $request->setUserResolver(fn () => \App\Models\User::find(1));
    $controller = new \App\Http\Controllers\Api\TicketController();
    expect($controller->updateStatus($request, 'contact-1')->getStatusCode())->toBe(200);
    expect($controller->updateStatus($request, 'contact-1')->getStatusCode())->toBe(200)
        ->and(DB::table('chat_tickets')->where('id', 1)->value('assigned_to'))->toBe(2)
        ->and(DB::table('chat_ticket_logs')->count())->toBe(1)
        ->and(DB::table('chat_logs')->count())->toBe(1);
});

it('normalizes legacy schedules and falls back consistently for invalid schedules', function () {
    $service = new WorkingHoursService();
    DB::table('organizations')->where('id', 1)->update(['metadata' => json_encode(['timezone' => 'UTC', 'working_hours' => ['from' => '09:00', 'to' => '17:00']])]);
    Carbon::setTestNow(Carbon::parse('2026-10-26 10:00:00', 'UTC'));
    expect($service->getSchedule(1)['days'])->toHaveCount(7)->and($service->isWithinWorkingHours(1))->toBeTrue();
    DB::table('organizations')->where('id', 1)->update(['metadata' => json_encode(['enable_working_hours' => true, 'working_hours' => [['day' => 'Monday', 'active' => true, 'from' => null, 'to' => '17:00']]])]);
    expect($service->getSchedule(1))->toBeNull()->and($service->isWithinWorkingHours(1))->toBeTrue();
    Carbon::setTestNow();
});

it('applies a normalized business schedule to report response metrics', function () {
    DB::table('organizations')->where('id', 1)->update(['metadata' => json_encode([
        'timezone' => 'UTC', 'working_hours' => ['days' => ['mon' => ['from' => '09:00', 'to' => '17:00']]],
    ])]);
    reportMessage('2026-10-26 08:00:00', 'inbound');
    reportMessage('2026-10-26 09:30:00', 'outbound', 1);
    $rows = collect((new ReportService(1))->agentReports(null, null, true))->keyBy('agent_name');
    expect($rows['Alice A']['avg_first_response_time'])->toBe('30m');
});
