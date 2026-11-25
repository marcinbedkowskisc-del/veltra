<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('veltra_private.authorization_mutation_scopes')->insert([
            ['table_name' => 'activation_tokens', 'operation' => 'DELETE', 'scope_prefix' => 'identity.disable'],
            ['table_name' => 'password_reset_tokens', 'operation' => 'DELETE', 'scope_prefix' => 'identity.disable'],
        ]);
    }

    public function down(): void
    {
        throw new RuntimeException('Migracja jest tylko do przodu; wycofanie wymaga osobnej migracji.');
    }
};
