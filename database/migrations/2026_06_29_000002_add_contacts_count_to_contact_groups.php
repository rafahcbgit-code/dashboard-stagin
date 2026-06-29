<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('contact_groups', 'contacts_count')) {
            Schema::table('contact_groups', function (Blueprint $table) {
                $table->unsignedInteger('contacts_count')->default(0)->after('created_by');
                $table->index(['organization_id', 'deleted_at', 'contacts_count'], 'idx_contact_groups_org_count');
            });
        }

        if (!$this->indexExists('contact_contact_group', 'idx_ccg_group_contact')) {
            Schema::table('contact_contact_group', function (Blueprint $table) {
                $table->index(['contact_group_id', 'contact_id'], 'idx_ccg_group_contact');
            });
        }

        $this->dropTriggers();

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER contact_group_member_insert_count
            AFTER INSERT ON contact_contact_group
            FOR EACH ROW
            BEGIN
                IF EXISTS (SELECT 1 FROM contacts WHERE id = NEW.contact_id AND deleted_at IS NULL) THEN
                    UPDATE contact_groups
                    SET contacts_count = contacts_count + 1
                    WHERE id = NEW.contact_group_id;
                END IF;
            END
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER contact_group_member_delete_count
            AFTER DELETE ON contact_contact_group
            FOR EACH ROW
            BEGIN
                IF EXISTS (SELECT 1 FROM contacts WHERE id = OLD.contact_id AND deleted_at IS NULL) THEN
                    UPDATE contact_groups
                    SET contacts_count = GREATEST(contacts_count - 1, 0)
                    WHERE id = OLD.contact_group_id;
                END IF;
            END
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER contact_soft_delete_group_count
            AFTER UPDATE ON contacts
            FOR EACH ROW
            BEGIN
                IF OLD.deleted_at IS NULL AND NEW.deleted_at IS NOT NULL THEN
                    UPDATE contact_groups
                    INNER JOIN contact_contact_group
                        ON contact_groups.id = contact_contact_group.contact_group_id
                    SET contact_groups.contacts_count = GREATEST(contact_groups.contacts_count - 1, 0)
                    WHERE contact_contact_group.contact_id = NEW.id;
                ELSEIF OLD.deleted_at IS NOT NULL AND NEW.deleted_at IS NULL THEN
                    UPDATE contact_groups
                    INNER JOIN contact_contact_group
                        ON contact_groups.id = contact_contact_group.contact_group_id
                    SET contact_groups.contacts_count = contact_groups.contacts_count + 1
                    WHERE contact_contact_group.contact_id = NEW.id;
                END IF;
            END
        SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->dropTriggers();

        if (Schema::hasColumn('contact_groups', 'contacts_count')) {
            Schema::table('contact_groups', function (Blueprint $table) {
                $table->dropIndex('idx_contact_groups_org_count');
                $table->dropColumn('contacts_count');
            });
        }

        if ($this->indexExists('contact_contact_group', 'idx_ccg_group_contact')) {
            Schema::table('contact_contact_group', function (Blueprint $table) {
                $table->dropIndex('idx_ccg_group_contact');
            });
        }
    }

    private function dropTriggers(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS contact_group_member_insert_count');
        DB::unprepared('DROP TRIGGER IF EXISTS contact_group_member_delete_count');
        DB::unprepared('DROP TRIGGER IF EXISTS contact_soft_delete_group_count');
    }

    private function indexExists(string $table, string $index): bool
    {
        $indexes = DB::select("SHOW INDEXES FROM `{$table}` WHERE Key_name = ?", [$index]);

        return count($indexes) > 0;
    }
};
