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

        $sql = file_get_contents(__DIR__.'/002_names_and_ticket_workflow.sql');

        if ($sql === false) {
            throw new RuntimeException('Cannot read CaseLantern workflow migration SQL.');
        }

        DB::unprepared($sql);
    }

    public function down(): void
    {
        throw new RuntimeException(
            'This migration combines names and completion states; use a reviewed forward migration instead of a lossy rollback.'
        );
    }
};
