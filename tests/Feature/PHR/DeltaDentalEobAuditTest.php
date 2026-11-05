<?php

namespace Tests\Feature\PHR;

use App\Models\PhrDocument;
use App\Models\PhrEob;
use App\Models\PhrEobLine;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Services\PHR\Import\DeltaDentalEobAudit;
use App\Services\PHR\Import\EobPdfTextExtractor;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use RuntimeException;
use Tests\Support\UsesObjectStoreDisks;
use Tests\TestCase;

final class DeltaDentalEobAuditTest extends TestCase
{
    use UsesObjectStoreDisks;

    private User $owner;

    private PhrPatient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useObjectStoreDisks();
        $this->owner = $this->createUser();
        $this->patient = PhrPatient::query()->create(['owner_user_id' => $this->owner->id, 'display_name' => 'Synthetic patient']);
    }

    protected function tearDown(): void
    {
        $this->tearDownObjectStoreDisks();
        parent::tearDown();
    }

    public function test_the_old_inflated_header_is_flagged_even_though_its_line_sum_matches(): void
    {
        $claim = $this->claim([178, 46, 77], 301, true);
        $report = $this->audit()->run($this->patient, details: true);
        $this->assertSame(1, $report['counts']['scanned']);
        $this->assertSame(1, $report['counts']['legacy_csv_first_row_unverified']);
        $this->assertSame(0, $report['counts']['line_totals_mismatch']);
        $this->assertSame([['public_id' => $this->publicId($claim), 'flags' => ['legacy_csv_first_row_unverified']]], $report['records']);
    }

    public function test_arithmetic_and_first_line_signature_are_distinct_from_csv_suspicion(): void
    {
        $this->claim([178, 46, 77], 178, true);
        $report = $this->audit()->run($this->patient);
        $this->assertSame(1, $report['counts']['line_totals_mismatch']);
        $this->assertSame(1, $report['counts']['first_line_equals_header_total']);
        $this->assertSame(1, $report['counts']['legacy_csv_first_row_unverified']);
        $this->assertSame(1, $report['counts']['flagged']);
    }

    public function test_a_corrected_first_line_and_a_verified_import_are_not_legacy_suspects(): void
    {
        $corrected = $this->claim([55, 46, 77], 178, true);
        $first = $corrected->lines()->orderBy('line_number')->firstOrFail();
        $first->update(['parsed_data' => ['source_row' => $this->sourceRow(178)]]);
        $verified = $this->claim([178, 46, 77], 301, true);
        $verified->update(['parsed_data' => ['source_csv' => 'synthetic.csv', 'amount_validation' => ['source' => 'authoritative_claim_pdf']]]);
        $single = $this->claim([178], 178, true);
        $this->assertNotNull($single);
        $report = $this->audit()->run($this->patient);
        $this->assertSame(3, $report['counts']['scanned']);
        $this->assertSame(0, $report['counts']['flagged']);
    }

    public function test_missing_provenance_and_missing_amounts_remain_explicitly_unverified(): void
    {
        $claim = $this->claim([178, 46], 224, true);
        $claim->lines()->firstOrFail()->update(['parsed_data' => null]);
        $claim->update(['parsed_data' => null]);
        $claim->lines()->orderBy('line_number')->firstOrFail()->update(['patient_responsibility' => null]);
        $report = $this->audit()->run($this->patient);
        $this->assertSame(1, $report['counts']['legacy_csv_unverified']);
        $this->assertSame(1, $report['counts']['incomplete_amounts']);
    }

    public function test_independent_pdf_totals_confirm_the_old_first_row_error_without_writing(): void
    {
        $claim = $this->claim([178, 46, 77], 301, true);
        $this->sourcePdf($claim);
        $this->pdfText($this->pdf());
        $audit = $this->audit();
        $writes = [];
        DB::listen(static function (QueryExecuted $query) use (&$writes): void {
            if (preg_match('/^\s*(?:insert|update|delete|replace)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        $before = $claim->refresh()->getAttributes();
        $report = $audit->run($this->patient, verifyPdfs: true);
        $this->assertSame(1, $report['counts']['pdf_totals_mismatch']);
        $this->assertSame(0, $report['counts']['legacy_csv_first_row_unverified']);
        $this->assertSame([], $writes);
        $this->assertSame($before, $claim->refresh()->getAttributes());
        $this->assertSame(['178.00', '46.00', '77.00'], $claim->lines()->orderBy('line_number')->pluck('accepted_fee')->all());
    }

    public function test_pdf_totals_can_match_true_lines_without_claiming_per_line_verification(): void
    {
        $claim = $this->claim([55, 46, 77], 178, true);
        $this->sourcePdf($claim);
        $this->pdfText($this->pdf());
        $report = $this->audit()->run($this->patient, verifyPdfs: true);
        $this->assertSame(1, $report['counts']['pdf_totals_match']);
        $this->assertSame(0, $report['counts']['flagged']);
    }

    public function test_changed_or_missing_pdf_bytes_and_unknown_layouts_are_unavailable_not_clean(): void
    {
        $first = $this->claim([55, 46, 77], 178);
        $document = $this->sourcePdf($first);
        Storage::disk(PhrDocument::STORAGE_DISK)->put($document->storage_path, '%PDF-1.4 tampered!');
        $second = $this->claim([55, 46, 77], 178);
        $this->sourcePdf($second);
        $this->mock(EobPdfTextExtractor::class, static function (MockInterface $mock): void {
            $mock->shouldReceive('extractBounded')->once()->andReturn('Synthetic unfamiliar layout');
        });
        $this->claim([55, 46, 77], 178);
        $report = $this->audit()->run($this->patient, verifyPdfs: true);
        $this->assertSame(3, $report['counts']['pdf_unavailable']);
        $this->assertSame(0, $report['counts']['pdf_totals_match']);
    }

    public function test_other_patients_and_other_import_sources_are_excluded_and_foreign_lines_are_not_read(): void
    {
        $claim = $this->claim([55, 46, 77], 178);
        $other = PhrPatient::query()->create(['owner_user_id' => $this->owner->id, 'display_name' => 'Other synthetic patient']);
        $foreign = $this->claim([178, 46], 224, true, $other);
        $otherSource = $this->claim([178, 46], 224, true);
        $otherSource->update(['import_source' => 'other_importer']);
        $foreignLine = $foreign->lines()->firstOrFail();
        $foreignLine->update(['eob_id' => $claim->id, 'line_number' => 99]);
        $claim->update(['source_document_id' => $this->sourcePdf($foreign)->id]);
        $this->mock(EobPdfTextExtractor::class, static function (MockInterface $mock): void {
            $mock->shouldNotReceive('extractBounded');
        });
        $report = $this->audit()->run($this->patient, verifyPdfs: true, details: true);
        $this->assertSame(1, $report['counts']['scanned']);
        $this->assertSame(1, $report['counts']['line_patient_mismatch']);
        $this->assertSame(1, $report['counts']['pdf_unavailable']);
        $this->assertSame($this->publicId($claim), $report['records'][0]['public_id']);
    }

    public function test_command_output_is_counts_only_by_default_and_never_echoes_parser_errors(): void
    {
        $claim = $this->claim([178, 46, 77], 301, true);
        $this->sourcePdf($claim);
        $this->mock(EobPdfTextExtractor::class, static function (MockInterface $mock): void {
            $mock->shouldReceive('extractBounded')->andThrow(new RuntimeException('Synthetic private claim 20300102000001 amount 178.00 private-file.pdf'));
        });
        Artisan::call('phr:eobs:audit-delta-dental', ['--patient' => $this->patient->id, '--actor' => $this->owner->id, '--verify-pdfs' => true, '--json' => true]);
        $output = Artisan::output();
        $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(1, $decoded['counts']['flagged']);
        $this->assertSame([], $decoded['records']);
        foreach (['20300102000001', '178.00', 'private-file.pdf', 'Synthetic private', $this->publicId($claim)] as $private) {
            $this->assertStringNotContainsString($private, $output);
        }
    }

    public function test_a_viewer_can_audit_but_an_actor_without_patient_access_is_refused(): void
    {
        $this->claim([178], 178);
        $viewer = $this->createUser();
        PhrPatientUserAccess::query()->create(['patient_id' => $this->patient->id, 'user_id' => $viewer->id, 'access_level' => 'viewer']);
        $this->artisan('phr:eobs:audit-delta-dental', ['--patient' => $this->patient->id, '--actor' => $viewer->id])
            ->expectsOutput('scanned: 1')->assertSuccessful();
        $stranger = $this->createUser();
        $this->expectException(ModelNotFoundException::class);
        Artisan::call('phr:eobs:audit-delta-dental', ['--patient' => $this->patient->id, '--actor' => $stranger->id]);
    }

    public function test_missing_public_id_is_reported_without_assigning_one(): void
    {
        $claim = $this->claim([178, 46], 224, true);
        DB::table('phr_native_record_identities')->where('patient_id', $this->patient->id)
            ->where('record_table', 'phr_eobs')->where('record_id', $claim->id)->delete();
        $before = DB::table('phr_native_record_identities')->count();
        $report = $this->audit()->run($this->patient, details: true);
        $this->assertSame([], $report['records']);
        $this->assertSame(1, $report['counts']['details_omitted']);
        $this->assertSame($before, DB::table('phr_native_record_identities')->count());
    }

    public function test_oversized_pdfs_and_missing_claim_identity_are_not_extracted(): void
    {
        $claim = $this->claim([55], 55);
        $this->sourcePdf($claim)->update(['byte_size' => 20 * 1024 * 1024 + 1]);
        $unnamed = $this->claim([55], 55);
        $this->sourcePdf($unnamed);
        $unnamed->update(['claim_number' => null]);
        $this->mock(EobPdfTextExtractor::class, static function (MockInterface $mock): void {
            $mock->shouldNotReceive('extractBounded');
        });
        $this->assertSame(2, $this->audit()->run($this->patient, verifyPdfs: true)['counts']['pdf_unavailable']);
    }

    public function test_detail_output_and_line_materialization_are_bounded(): void
    {
        for ($i = 0; $i < 101; $i++) {
            $this->claim([], 0);
        }
        $report = $this->audit()->run($this->patient, details: true);
        $this->assertSame(101, $report['counts']['scanned']);
        $this->assertCount(100, $report['records']);
        $this->assertSame(1, $report['counts']['details_omitted']);
        $claim = $this->claim([], 0);
        DB::table('phr_eob_lines')->insert(array_map(fn (int $line): array => [
            'eob_id' => $claim->id, 'patient_id' => $this->patient->id, 'line_number' => $line, 'procedure_code' => 'D0120',
        ], range(1, 1001)));
        $this->assertSame(1, $this->audit()->run($this->patient)['counts']['line_limit_exceeded']);
    }

    /** @param list<int> $amounts */
    private function claim(array $amounts, int $total, bool $legacy = false, ?PhrPatient $patient = null): PhrEob
    {
        $patient ??= $this->patient;
        $claim = PhrEob::query()->create([
            'patient_id' => $patient->id, 'user_id' => $this->owner->id,
            'import_source' => 'delta_dental_eob', 'external_id' => 'synthetic-'.bin2hex(random_bytes(6)),
            'claim_type' => 'dental', 'claim_number' => '20300102000001',
            'total_accepted_fee' => $total, 'total_deductible_applied' => 0,
            'total_carrier_payment' => $total, 'total_plan_payment' => $total, 'total_patient_responsibility' => 0,
            'parser_version' => $legacy ? 'delta-dental-csv-v1' : 'agent-api-v1',
            'parsed_data' => $legacy ? ['source_csv' => 'synthetic.csv'] : null,
        ]);
        foreach ($amounts as $index => $amount) {
            PhrEobLine::query()->create([
                'eob_id' => $claim->id, 'patient_id' => $patient->id, 'line_number' => $index + 1, 'procedure_code' => 'D0120',
                'accepted_fee' => $amount, 'deductible_applied' => 0, 'carrier_payment' => $amount, 'plan_payment' => $amount, 'patient_responsibility' => 0,
                'parsed_data' => $legacy ? ['source_row' => $this->sourceRow($amount)] : null,
            ]);
        }

        return $claim;
    }

    /** @return array<string, string> */
    private function sourceRow(int $amount): array
    {
        return ['Accepted Fee' => number_format($amount, 2, '.', ''), 'Claim Deductible' => '0.00', 'Delta Dental Pays' => number_format($amount, 2, '.', ''), 'You Pay' => '0.00'];
    }

    private function sourcePdf(PhrEob $claim): PhrDocument
    {
        $bytes = '%PDF-1.4 synthetic';
        $key = 'audit/'.bin2hex(random_bytes(6)).'.pdf';
        Storage::disk(PhrDocument::STORAGE_DISK)->put($key, $bytes);
        $document = PhrDocument::query()->create([
            'patient_id' => $claim->patient_id, 'user_id' => $this->owner->id, 'document_type' => 'insurance', 'source' => 'manual_upload',
            'storage_disk' => PhrDocument::STORAGE_DISK, 'storage_path' => $key, 'mime_type' => 'application/pdf', 'byte_size' => strlen($bytes), 'file_hash' => hash('sha256', $bytes),
        ]);
        $claim->update(['source_document_id' => $document->id]);

        return $document;
    }

    private function publicId(PhrEob $claim): string
    {
        return (string) DB::table('phr_native_record_identities')->where('patient_id', $claim->patient_id)
            ->where('record_table', 'phr_eobs')->where('record_id', $claim->id)->value('native_id');
    }

    private function audit(): DeltaDentalEobAudit
    {
        return app(DeltaDentalEobAudit::class);
    }

    private function pdfText(string $text): void
    {
        $this->mock(EobPdfTextExtractor::class, static function (MockInterface $mock) use ($text): void {
            $mock->shouldReceive('extractBounded')->andReturn($text);
        });
    }

    private function pdf(): string
    {
        return "Claim Number: 20300102000001\nCost Breakdown\nAccepted Fee: $178.00\nClaim Deductible: $0.00\nDelta Dental Pays: $178.00\nYou Pay: $0.00\n";
    }
}
