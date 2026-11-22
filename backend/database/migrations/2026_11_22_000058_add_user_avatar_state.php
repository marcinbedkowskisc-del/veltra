<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE users
                ADD COLUMN avatar_type varchar(8) NOT NULL DEFAULT 'INITIALS',
                ADD COLUMN avatar_library_id varchar(24),
                ADD COLUMN avatar_photo_key uuid,
                ADD CONSTRAINT users_avatar_state_check CHECK (
                    (avatar_type = 'INITIALS' AND avatar_library_id IS NULL AND avatar_photo_key IS NULL)
                    OR (avatar_type = 'LIBRARY' AND avatar_library_id IS NOT NULL AND avatar_photo_key IS NULL)
                    OR (avatar_type = 'UPLOAD' AND avatar_library_id IS NULL AND avatar_photo_key IS NOT NULL)
                ),
                ADD CONSTRAINT users_avatar_library_id_check CHECK (
                    avatar_library_id IS NULL OR avatar_library_id ~ '^(portrait-(00[1-9]|0[1-3][0-9]|040)|abstract-(00[1-9]|01[0-9]|02[0-4]))$'
                ),
                ADD CONSTRAINT users_avatar_photo_key_unique UNIQUE (avatar_photo_key);
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE users
                DROP CONSTRAINT users_avatar_photo_key_unique,
                DROP CONSTRAINT users_avatar_library_id_check,
                DROP CONSTRAINT users_avatar_state_check,
                DROP COLUMN avatar_photo_key,
                DROP COLUMN avatar_library_id,
                DROP COLUMN avatar_type;
            SQL);
    }
};
