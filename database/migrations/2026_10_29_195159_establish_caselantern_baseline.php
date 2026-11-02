<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'pgsql_migrator';

    public function up(): void
    {
        $connection = DB::connection($this->getConnection());

        $expectedTables = [
            'clients',
            'contacts',
            'tenants',
            'ticket_activities',
            'ticket_comments',
            'tickets',
            'users',
        ];

        $existingTables = array_map(
            static fn (object $table): string => $table->tablename,
            $connection->select(
                "SELECT tablename
                 FROM pg_catalog.pg_tables
                 WHERE schemaname = 'public'
                   AND tablename <> 'migrations'
                 ORDER BY tablename"
            ),
        );

        if ($existingTables !== []) {
            if ($existingTables !== $expectedTables) {
                throw new RuntimeException(
                    'Existing tables differ from the CaseLantern baseline. Stop and review the schema.'
                );
            }

            if (getenv('CASELANTERN_ADOPT_BASELINE') !== '1') {
                throw new RuntimeException(
                    'Existing schema detected. Compare it with the SQL baseline before explicitly adopting it.'
                );
            }

            return;
        }

        $sql = file_get_contents(
            database_path('migrations/001_initial.sql')
        );

        if ($sql === false) {
            throw new RuntimeException('Cannot read the baseline SQL file.');
        }

        $sql = trim($sql);

        if (! str_starts_with($sql, 'BEGIN;')
            || ! str_ends_with($sql, 'COMMIT;')) {
            throw new RuntimeException(
                'Unexpected baseline SQL transaction boundaries.'
            );
        }

        $connection->unprepared(substr($sql, 6, -7));
    }

    public function down(): void
    {
        throw new RuntimeException(
            'The adopted baseline cannot be rolled back automatically. Use a forward migration.'
        );
    }
};
