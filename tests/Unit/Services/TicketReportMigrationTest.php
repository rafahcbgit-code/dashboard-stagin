<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(Tests\TestCase::class);

it('applies and rolls back the report indexes and event link on an isolated database', function () {
    config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
    DB::purge('sqlite');
    Schema::create('chat_ticket_logs', function (Blueprint $table) {
        $table->id();
        $table->integer('contact_id');
        $table->timestamp('created_at')->nullable();
    });
    Schema::create('campaign_logs', function (Blueprint $table) {
        $table->id();
        $table->integer('chat_id')->nullable();
    });
    $indexes = require database_path('migrations/2026_10_27_000001_add_ticket_report_event_index.php');
    $link = require database_path('migrations/2026_10_27_000002_add_trigger_chat_to_ticket_logs.php');
    $indexes->up();
    $link->up();
    expect(Schema::hasColumn('chat_ticket_logs', 'trigger_chat_id'))->toBeTrue();
    $link->down();
    $indexes->down();
    expect(Schema::hasColumn('chat_ticket_logs', 'trigger_chat_id'))->toBeFalse();
});
