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
    Schema::create('organizations', function (Blueprint $t) { $t->id(); $t->string('name')->nullable(); $t->text('address')->nullable(); $t->text('metadata')->nullable(); $t->timestamps(); $t->softDeletes(); });
    Schema::create('users', function (Blueprint $t) { $t->id(); $t->string('first_name'); $t->string('last_name'); $t->softDeletes(); });
    Schema::create('teams', function (Blueprint $t) { $t->id(); $t->integer('organization_id'); $t->integer('user_id'); $t->string('role')->default('admin'); $t->softDeletes(); });
    Schema::create('contacts', function (Blueprint $t) { $t->id(); $t->string('uuid')->nullable(); $t->integer('organization_id'); $t->softDeletes(); });
    Schema::create('chat_tickets', function (Blueprint $t) { $t->id(); $t->integer('contact_id'); $t->integer('organization_id'); $t->integer('assigned_to')->nullable(); $t->string('status'); $t->timestamp('created_at')->nullable(); $t->timestamp('updated_at')->nullable(); });
    Schema::create('chats', function (Blueprint $t) { $t->id(); $t->integer('contact_id'); $t->integer('organization_id'); $t->integer('user_id')->nullable(); $t->string('type'); $t->string('status'); $t->text('metadata')->nullable(); $t->timestamp('created_at'); });
    Schema::create('chat_ticket_logs', function (Blueprint $t) { $t->id(); $t->integer('contact_id'); $t->string('description'); $t->unsignedBigInteger('trigger_chat_id')->nullable(); $t->timestamp('created_at'); $t->timestamp('updated_at')->nullable(); });
    Schema::create('chat_logs', function (Blueprint $t) { $t->id(); $t->integer('contact_id'); $t->string('entity_type'); $t->integer('entity_id'); $t->timestamps(); });
    Schema::create('campaign_logs', function (Blueprint $t) { $t->id(); $t->integer('chat_id')->nullable(); });
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
        ['contact_id' => 1, 'entity_type' => 'chat', 'entity_id' => 1, 'created_at' => '2026-10-26 09:00:00'],
        ['contact_id' => 1, 'entity_type' => 'chat', 'entity_id' => 2, 'created_at' => '2026-10-26 09:01:00'],
        ['contact_id' => 1, 'entity_type' => 'ticket', 'entity_id' => 1, 'created_at' => '2026-10-26 09:01:00'],
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

it('preserves all-day schedules through the last minute for automation and reports', function () {
    DB::table('organizations')->where('id', 1)->update(['metadata' => json_encode([
        'enable_working_hours' => true, 'timezone' => 'UTC', 'working_hours' => [
            ['day' => 'Monday', 'active' => true, 'from' => '00:00', 'to' => '23:59'],
        ],
    ])]);
    Carbon::setTestNow(Carbon::parse('2026-10-26 23:59:30', 'UTC'));
    expect((new WorkingHoursService())->isWithinWorkingHours(1))->toBeTrue();
    reportMessage('2026-10-26 23:58:00', 'inbound');
    reportMessage('2026-10-26 23:59:30', 'outbound', 1);
    $rows = collect((new ReportService(1))->agentReports(null, null, true))->keyBy('agent_name');
    expect($rows['Alice A']['avg_first_response_time'])->toBe('2m');
    Carbon::setTestNow();
});

it('can record ticket events and build reports before the optional link migration', function () {
    Schema::table('chat_ticket_logs', function (Blueprint $table) { $table->dropColumn('trigger_chat_id'); });
    $service = new \App\Services\ChatService(1);
    (new ReflectionMethod($service, 'logTicketEvent'))->invoke($service, 1, 'Conversation was moved from closed to open', 123);
    reportMessage('2026-10-26 09:00:00', 'inbound');
    reportMessage('2026-10-26 09:02:00', 'outbound', 1);
    $rows = collect((new ReportService(1))->agentReports())->keyBy('agent_name');
    expect(DB::table('chat_ticket_logs')->count())->toBe(1)
        ->and($rows['Alice A']['avg_first_response_time'])->toBe('2m');
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

it('does not create another first response when a reopen event is delayed', function () {
    reportEvent('2026-10-27 09:59:00', 'opened to closed');
    reportMessage('2026-10-27 10:00:00', 'inbound');
    reportMessage('2026-10-27 10:02:00', 'outbound', 1);
    reportEvent('2026-10-27 10:03:00', 'closed to open');
    DB::table('chat_ticket_logs')->where('id', 2)->update(['trigger_chat_id' => 1]);
    reportMessage('2026-10-27 10:04:00', 'inbound');
    reportMessage('2026-10-27 10:08:00', 'outbound', 1);
    $rows = collect((new ReportService(1))->agentReports())->keyBy('agent_name');
    expect($rows['Alice A']['avg_first_response_time'])->toBe('2m')
        ->and($rows['Alice A']['avg_customer_waiting_time'])->toBe('3m');
});

it('does not invent a reopened cycle for a message received while closed', function () {
    reportEvent('2026-10-26 09:00:00', 'opened to closed');
    reportMessage('2026-10-26 09:01:00', 'inbound');
    reportMessage('2026-10-26 09:03:00', 'outbound', 1);
    $rows = collect((new ReportService(1))->agentReports())->keyBy('agent_name');
    expect($rows['Alice A']['avg_first_response_time'])->toBe('-')
        ->and($rows['Alice A']['avg_customer_waiting_time'])->toBe('2m');
});

it('ignores old messages before ticket creation', function () {
    reportMessage('2026-10-25 09:00:00', 'inbound');
    reportMessage('2026-10-25 09:30:00', 'outbound', 1);
    reportMessage('2026-10-26 09:00:00', 'inbound');
    reportMessage('2026-10-26 09:02:00', 'outbound', 1);
    $rows = collect((new ReportService(1))->agentReports())->keyBy('agent_name');
    expect($rows['Alice A']['avg_first_response_time'])->toBe('2m');
});

it('does not count campaign messages as human responses and exposes unanswered conversations', function () {
    reportMessage('2026-10-26 09:00:00', 'inbound');
    reportMessage('2026-10-26 09:01:00', 'outbound', 1);
    DB::table('chats')->where('id', 2)->update(['metadata' => json_encode(['billing' => ['source' => 'campaign']])]);
    $rows = collect((new ReportService(1))->agentReports())->keyBy('agent_name');
    expect($rows['Alice A']['avg_first_response_time'])->toBe('-')
        ->and($rows['Bob B']['waiting_unanswered_count'])->toBe(1);
    reportMessage('2026-10-26 09:03:00', 'outbound', 2);
    $rows = collect((new ReportService(1))->agentReports())->keyBy('agent_name');
    expect($rows['Bob B']['avg_first_response_time'])->toBe('3m')
        ->and($rows['Bob B']['waiting_unanswered_count'])->toBe(0);
});

it('excludes a legacy campaign message identified by its campaign log', function () {
    reportMessage('2026-10-26 09:00:00', 'inbound');
    reportMessage('2026-10-26 09:01:00', 'outbound', 1);
    DB::table('campaign_logs')->insert(['chat_id' => 2]);
    reportMessage('2026-10-26 09:03:00', 'outbound', 2);
    $rows = collect((new ReportService(1))->agentReports())->keyBy('agent_name');
    expect($rows['Alice A']['avg_first_response_time'])->toBe('-')
        ->and($rows['Bob B']['avg_first_response_time'])->toBe('3m');
});

it('preserves unanswered customer wait when a ticket is closed and reopened manually', function () {
    reportMessage('2026-10-26 09:00:00', 'inbound');
    reportEvent('2026-10-26 09:01:00', 'opened to closed');
    reportEvent('2026-10-26 09:02:00', 'closed to open');
    reportMessage('2026-10-26 09:03:00', 'outbound', 1);
    $rows = collect((new ReportService(1))->agentReports())->keyBy('agent_name');
    expect($rows['Alice A']['avg_first_response_time'])->toBe('3m');
});

it('uses the working schedule active during each part of a waiting interval', function () {
    $old = ['timezone' => 'UTC', 'enable_working_hours' => true, 'working_hours' => [['day' => 'Monday', 'active' => true, 'from' => '09:00', 'to' => '17:00']]];
    $new = ['timezone' => 'UTC', 'enable_working_hours' => true, 'working_hours' => [['day' => 'Monday', 'active' => true, 'from' => '11:00', 'to' => '17:00']]];
    $new['working_hours_history'] = [['effective_at' => null, 'settings' => $old], ['effective_at' => '2026-10-26 10:00:00', 'settings' => $new]];
    DB::table('organizations')->where('id', 1)->update(['metadata' => json_encode($new)]);
    reportMessage('2026-10-26 09:00:00', 'inbound');
    reportMessage('2026-10-26 12:00:00', 'outbound', 1);
    $rows = collect((new ReportService(1))->agentReports(null, null, true))->keyBy('agent_name');
    expect($rows['Alice A']['avg_first_response_time'])->toBe('2h');
});

it('records the previous and new schedules when settings change', function () {
    $old = ['timezone' => 'UTC', 'enable_working_hours' => true, 'working_hours' => [['day' => 'Monday', 'active' => true, 'from' => '09:00', 'to' => '17:00']]];
    DB::table('organizations')->where('id', 1)->update(['metadata' => json_encode($old)]);
    session()->put('current_organization', 1);
    Carbon::setTestNow(Carbon::parse('2026-10-26 10:00:00', 'UTC'));
    $request = \App\Http\Requests\StoreProfileAddress::create('/', 'POST', [
        'organization_name' => 'Test', 'timezone' => 'UTC', 'enable_working_hours' => true,
        'working_hours' => [['day' => 'Monday', 'active' => true, 'from' => '11:00', 'to' => '17:00']],
    ]);
    (new \App\Http\Controllers\ProfileController())->updateOrganization($request);
    $stored = json_decode(DB::table('organizations')->where('id', 1)->value('metadata'), true);
    expect($stored['working_hours_history'])->toHaveCount(2)
        ->and($stored['working_hours_history'][0]['settings'])->toBe($old)
        ->and($stored['working_hours_history'][1]['effective_at'])->toBe('2026-10-26 10:00:00');
    Carbon::setTestNow();
});

it('starts waiting at the earliest unanswered inbound preceding ticket creation', function () {
    reportMessage('2026-10-26 07:56:00', 'inbound');
    reportMessage('2026-10-26 07:59:00', 'inbound');
    reportMessage('2026-10-26 08:01:00', 'outbound', 1);
    $rows = collect((new ReportService(1))->agentReports())->keyBy('agent_name');
    expect($rows['Alice A']['avg_first_response_time'])->toBe('5m');
});

it('finds an explicitly linked opening message even when assignment is delayed over five minutes', function () {
    reportMessage('2026-10-26 07:45:00', 'inbound');
    reportMessage('2026-10-26 08:01:00', 'outbound', 1);
    DB::table('chat_ticket_logs')->insert(['contact_id' => 1, 'description' => 'Conversation was opened', 'trigger_chat_id' => 1, 'created_at' => '2026-10-26 08:00:00']);
    $rows = collect((new ReportService(1))->agentReports())->keyBy('agent_name');
    expect($rows['Alice A']['avg_first_response_time'])->toBe('16m');
});

it('persists the webhook message link in ticket event logs', function () {
    $service = new \App\Services\ChatService(1);
    $method = new ReflectionMethod($service, 'logTicketEvent');
    $method->invoke($service, 1, 'Conversation was moved from closed to open', 123);
    expect(DB::table('chat_ticket_logs')->value('trigger_chat_id'))->toBe(123);
});

it('clears stale inferred reopening when a ticket is closed again', function () {
    reportEvent('2026-10-26 09:00:00', 'opened to closed');
    reportMessage('2026-10-26 09:01:00', 'inbound');
    reportEvent('2026-10-26 09:02:00', 'opened to closed');
    reportEvent('2026-10-26 09:03:00', 'closed to open');
    reportMessage('2026-10-26 09:04:00', 'outbound', 1);
    reportMessage('2026-10-26 09:05:00', 'inbound');
    reportMessage('2026-10-26 09:15:00', 'outbound', 1);
    $rows = collect((new ReportService(1))->agentReports())->keyBy('agent_name');
    expect($rows['Alice A']['avg_first_response_time'])->toBe('3m')
        ->and($rows['Alice A']['avg_customer_waiting_time'])->toBe('7m');
});

it('uses the provider timestamp for inbound waiting time', function () {
    reportMessage('2026-10-26 09:10:00', 'inbound');
    DB::table('chats')->where('id', 1)->update(['metadata' => json_encode(['timestamp' => Carbon::parse('2026-10-26 09:00:00', 'UTC')->timestamp])]);
    reportMessage('2026-10-26 09:12:00', 'outbound', 1);
    $rows = collect((new ReportService(1))->agentReports())->keyBy('agent_name');
    expect($rows['Alice A']['avg_first_response_time'])->toBe('12m');
});

it('applies business hours to the provider occurrence time rather than processing time', function () {
    DB::table('organizations')->where('id', 1)->update(['metadata' => json_encode([
        'timezone' => 'UTC', 'enable_working_hours' => true,
        'working_hours' => [['day' => 'Monday', 'active' => true, 'from' => '09:00', 'to' => '17:00']],
    ])]);
    reportMessage('2026-10-26 09:10:00', 'inbound');
    DB::table('chats')->where('id', 1)->update(['metadata' => json_encode(['timestamp' => Carbon::parse('2026-10-26 08:50:00', 'UTC')->timestamp])]);
    reportMessage('2026-10-26 09:20:00', 'outbound', 1);
    $rows = collect((new ReportService(1))->agentReports(null, null, true))->keyBy('agent_name');
    expect($rows['Alice A']['avg_first_response_time'])->toBe('20m');
});

it('selects the same canonical ticket as the assignment workflow', function () {
    DB::table('chat_tickets')->insert(['id' => 2, 'contact_id' => 1, 'organization_id' => 1, 'assigned_to' => 2, 'status' => 'closed', 'created_at' => '2026-10-27 08:00:00', 'updated_at' => '2026-10-27 11:00:00']);
    reportMessage('2026-10-26 09:00:00', 'inbound');
    reportMessage('2026-10-26 09:02:00', 'outbound', 1);
    $rows = collect((new ReportService(1))->agentReports())->keyBy('agent_name');
    expect($rows['Alice A']['avg_first_response_time'])->toBe('2m');
});

it('links a delayed reopen to its triggering message even beyond the report end', function () {
    reportMessage('2026-10-26 08:30:00', 'inbound');
    reportMessage('2026-10-26 08:34:00', 'outbound', 1);
    reportEvent('2026-10-26 08:59:00', 'opened to closed');
    reportMessage('2026-10-26 09:00:00', 'inbound');
    reportMessage('2026-10-26 09:02:00', 'outbound', 1);
    reportEvent('2026-10-27 09:00:00', 'closed to open');
    DB::table('chat_ticket_logs')->where('id', 2)->update(['trigger_chat_id' => 3]);
    $rows = collect((new ReportService(1))->agentReports(Carbon::parse('2026-10-26 00:00:00'), Carbon::parse('2026-10-26 23:59:59')))->keyBy('agent_name');
    expect($rows['Alice A']['avg_first_response_time'])->toBe('3m');
});
