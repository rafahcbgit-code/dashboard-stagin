<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'idx_notification_logs_user_org_type_sent';

    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            if (! DB::select('SHOW INDEX FROM `notification_logs` WHERE Key_name = ?', [self::INDEX])) {
                DB::statement('ALTER TABLE `notification_logs` ADD INDEX `'.self::INDEX.'` '
                    .'(`user_id`, `organization_id`, `notification_type`, `sent_at`, `id`), '
                    .'ALGORITHM=INPLACE, LOCK=NONE');
            }

            return;
        }

        Schema::table('notification_logs', function (Blueprint $table) {
            $table->index(['user_id', 'organization_id', 'notification_type', 'sent_at', 'id'], self::INDEX);
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            if (DB::select('SHOW INDEX FROM `notification_logs` WHERE Key_name = ?', [self::INDEX])) {
                DB::statement('ALTER TABLE `notification_logs` DROP INDEX `'.self::INDEX.'`, '
                    .'ALGORITHM=INPLACE, LOCK=NONE');
            }

            return;
        }

        Schema::table('notification_logs', function (Blueprint $table) {
            $table->dropIndex(self::INDEX);
        });
    }
};
