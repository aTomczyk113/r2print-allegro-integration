<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->insert([
            ['group' => 'shop', 'name' => 'allegro_enabled', 'payload' => json_encode(false)],
            ['group' => 'shop', 'name' => 'allegro_sandbox', 'payload' => json_encode(true)],
            ['group' => 'shop', 'name' => 'allegro_client_id', 'payload' => json_encode(null)],
            ['group' => 'shop', 'name' => 'allegro_client_secret', 'payload' => json_encode(null)],
            ['group' => 'shop', 'name' => 'allegro_client_id_sandbox', 'payload' => json_encode(null)],
            ['group' => 'shop', 'name' => 'allegro_client_secret_sandbox', 'payload' => json_encode(null)],
            ['group' => 'shop', 'name' => 'allegro_access_token', 'payload' => json_encode(null)],
            ['group' => 'shop', 'name' => 'allegro_refresh_token', 'payload' => json_encode(null)],
            ['group' => 'shop', 'name' => 'allegro_token_expires_at', 'payload' => json_encode(null)],
            ['group' => 'shop', 'name' => 'allegro_access_token_sandbox', 'payload' => json_encode(null)],
            ['group' => 'shop', 'name' => 'allegro_refresh_token_sandbox', 'payload' => json_encode(null)],
            ['group' => 'shop', 'name' => 'allegro_token_expires_at_sandbox', 'payload' => json_encode(null)],
            ['group' => 'shop', 'name' => 'allegro_last_event_id', 'payload' => json_encode(null)],
            ['group' => 'shop', 'name' => 'allegro_pending_device_code', 'payload' => json_encode(null)],
            ['group' => 'shop', 'name' => 'allegro_pending_device_code_sandbox', 'payload' => json_encode(null)],
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('group', 'shop')->whereIn('name', [
            'allegro_enabled', 'allegro_sandbox',
            'allegro_client_id', 'allegro_client_secret',
            'allegro_client_id_sandbox', 'allegro_client_secret_sandbox',
            'allegro_access_token', 'allegro_refresh_token', 'allegro_token_expires_at',
            'allegro_access_token_sandbox', 'allegro_refresh_token_sandbox', 'allegro_token_expires_at_sandbox',
            'allegro_last_event_id',
            'allegro_pending_device_code', 'allegro_pending_device_code_sandbox',
        ])->delete();
    }
};
