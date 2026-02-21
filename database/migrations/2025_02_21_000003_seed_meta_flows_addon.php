<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

return new class extends Migration
{
    public function up(): void
    {
        // Only insert if the addon doesn't already exist
        $exists = DB::table('addons')->where('name', 'Meta Flows')->exists();

        if (!$exists) {
            DB::table('addons')->insert([
                'uuid'        => Uuid::uuid4()->toString(),
                'category'    => 'Automation',
                'name'        => 'Meta Flows',
                'logo'        => 'meta-flows.png',
                'description' => 'Create and send interactive WhatsApp Flows powered by Meta.',
                'metadata'    => null,
                'status'      => 1,    // available / published
                'is_active'   => true, // enabled globally by default
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('addons')->where('name', 'Meta Flows')->delete();
    }
};
