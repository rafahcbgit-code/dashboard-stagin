<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const OLD_INDEX = 'idx_notification_logs_user_org_type_sent';
    private const INDEXES = [
        'notification_logs_inbox_order' => ['user_id', 'organization_id', 'sent_at', 'id', 'notification_type'],
        'notification_logs_popup_order' => ['user_id', 'organization_id', 'clicked', 'sent_at', 'id', 'notification_type'],
    ];

    public function up(): void
    {
        $this->changeIndexes(self::INDEXES, [self::OLD_INDEX]);
    }

    public function down(): void
    {
        $this->changeIndexes([
            self::OLD_INDEX => ['user_id', 'organization_id', 'notification_type', 'sent_at', 'id'],
        ], array_keys(self::INDEXES));
    }

    private function changeIndexes(array $add, array $drop): void
    {
        $existing = array_column(Schema::getIndexes('notification_logs'), 'name');
        $add = array_diff_key($add, array_flip($existing));
        $drop = array_values(array_intersect($drop, $existing));
        if ($add === [] && $drop === []) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            $clauses = [];
            foreach ($add as $name => $columns) {
                $clauses[] = 'ADD INDEX `'.$name.'` (`'.implode('`, `', $columns).'`)';
            }
            foreach ($drop as $name) {
                $clauses[] = 'DROP INDEX `'.$name.'`';
            }
            DB::statement('ALTER TABLE `notification_logs` '.implode(', ', $clauses).', ALGORITHM=INPLACE, LOCK=NONE');
            return;
        }

        Schema::table('notification_logs', function (Blueprint $table) use ($add, $drop): void {
            foreach ($add as $name => $columns) {
                $table->index($columns, $name);
            }
            foreach ($drop as $name) {
                $table->dropIndex($name);
            }
        });
    }
};
