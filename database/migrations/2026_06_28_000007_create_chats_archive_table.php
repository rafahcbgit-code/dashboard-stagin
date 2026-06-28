<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('chats_archive')) {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE TABLE `chats_archive` (
                `id` BIGINT UNSIGNED NOT NULL,
                `uuid` CHAR(50) NOT NULL,
                `organization_id` INT NOT NULL,
                `wam_id` VARCHAR(128) NULL,
                `contact_id` INT NOT NULL,
                `user_id` BIGINT UNSIGNED NULL,
                `type` ENUM('inbound', 'outbound') NULL,
                `metadata` TEXT NOT NULL,
                `media_id` INT NULL,
                `status` VARCHAR(128) NOT NULL,
                `is_read` TINYINT(1) NOT NULL DEFAULT 0,
                `deleted_by` INT NULL,
                `deleted_at` DATETIME NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `archived_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `chats_archive_uuid_unique` (`uuid`),
                KEY `idx_chats_archive_contact_created` (`contact_id`, `created_at`),
                KEY `idx_chats_archive_org_created` (`organization_id`, `created_at`),
                KEY `idx_chats_archive_wam_org` (`organization_id`, `wam_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('chats_archive') && DB::table('chats_archive')->exists()) {
            throw new RuntimeException('chats_archive contains data and will not be dropped automatically.');
        }

        Schema::dropIfExists('chats_archive');
    }
};
