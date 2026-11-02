<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Queries read context_json->patient_id. MariaDB and SQLite can do that on a
     * text column, but PostgreSQL needs a JSON one. `json` keeps the stored text
     * exactly as written.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE genai_import_jobs ALTER COLUMN context_json TYPE json USING context_json::json');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE genai_import_jobs ALTER COLUMN context_json TYPE text USING context_json::text');
        }
    }
};
