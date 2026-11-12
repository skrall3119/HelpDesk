<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('CaseLantern requires PostgreSQL.');
        }

        $sql = file_get_contents(__DIR__.'/003_bearer_authentication.sql');
        if ($sql === false) {
            throw new RuntimeException('Cannot read authentication migration SQL.');
        }
        DB::unprepared($sql);
    }

    public function down(): void
    {
        throw new RuntimeException('Use a reviewed forward migration for authentication changes.');
    }
};
