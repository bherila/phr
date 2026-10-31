<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Maps every stored `phr_documents.source` into the allowed list.
 *
 * The browser parses `source` against PhrDocument::SOURCES, so a row holding
 * anything else (free-text labels carried over with migrated data, the retired
 * `cli`/`cli_pdf`, an unnormalized `fhir`) could not be opened. The model now
 * refuses such values; this repairs the rows written before it did.
 *
 * The table has no metadata or provenance column, so the original label is not
 * kept. Rows are updated by id and compared in PHP rather than with SQL
 * `NOT IN`, so a case-insensitive collation cannot hide a differently cased
 * value. `updated_at` is left alone: this is a relabel, not an edit, and a
 * native backup's record hash covers `updated_at`, so touching it would make a
 * backup taken before this migration conflict with the very rows it holds.
 * Withdrawn (soft-deleted) rows are included.
 *
 * The lists are frozen here on purpose: the result of a migration must not
 * change when the application's list does later.
 */
return new class extends Migration
{
    private const array SOURCES = [
        'manual_upload',
        'agent_upload',
        'genai_import',
        'fhir_import',
        'ccda_import',
        'mychart_zip',
        'ehi_tables',
    ];

    private const array ALIASES = [
        'genai' => 'genai_import',
        'fhir' => 'fhir_import',
        'ccda' => 'ccda_import',
        'mychart' => 'mychart_zip',
        'cli' => 'manual_upload',
        'cli_pdf' => 'manual_upload',
        'onedrive_manual_review' => 'manual_upload',
        'outlook_email_attachment' => 'manual_upload',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('phr_documents') || ! Schema::hasColumn('phr_documents', 'source')) {
            return;
        }

        DB::table('phr_documents')
            ->select(['id', 'source'])
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    $stored = $row->source;
                    if (is_string($stored) && in_array($stored, self::SOURCES, true)) {
                        continue;
                    }

                    DB::table('phr_documents')
                        ->where('id', $row->id)
                        ->update(['source' => $this->mapped($stored)]);
                }
            });
    }

    public function down(): void
    {
        // Intentionally a no-op. The original labels are not recorded anywhere,
        // and writing them back would only make those documents unreadable again.
    }

    private function mapped(mixed $stored): string
    {
        $value = is_string($stored) ? strtolower(trim($stored)) : '';
        if (in_array($value, self::SOURCES, true)) {
            return $value;
        }

        return self::ALIASES[$value] ?? 'manual_upload';
    }
};
