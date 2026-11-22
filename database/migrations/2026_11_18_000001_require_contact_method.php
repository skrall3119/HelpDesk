<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Existing records are preserved; new/changed contacts must have a method.
        DB::statement("ALTER TABLE public.contacts ADD CONSTRAINT contacts_method_required CHECK (nullif(btrim(email), '') IS NOT NULL OR nullif(btrim(phone), '') IS NOT NULL) NOT VALID");
    }

    public function down(): void
    {
        throw new RuntimeException('Use a reviewed forward migration.');
    }
};
