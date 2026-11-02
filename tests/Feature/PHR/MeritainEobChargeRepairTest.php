<?php

namespace Tests\Feature\PHR;

use App\Models\PhrDocument;
use App\Models\PhrEob;
use App\Models\PhrPatient;
use App\Services\PHR\Import\MeritainEobFingerprint;
use App\Services\PHR\Import\MeritainEobImporter;
use App\Services\PHR\Import\MeritainEobParser;
use Tests\TestCase;
use Tests\Unit\Services\PHR\MeritainEobParserTest;

/** Claims imported before the parser kept charges printed left of their column. All values are synthetic. */
final class MeritainEobChargeRepairTest extends TestCase
{
    private PhrPatient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $owner = $this->createUser();
        $this->patient = PhrPatient::query()->create(['owner_user_id' => $owner->id, 'display_name' => 'Synthetic patient']);
    }

    public function test_a_repair_restores_the_charges_and_the_same_pdf_is_still_recognised(): void
    {
        $claim = $this->claimImportedByTheOldParser();
        $unrelated = $claim->lines()->orderBy('line_number')->get()->map->only(['plan_payment', 'copay_applied', 'patient_responsibility'])->all();

        $this->artisan('phr:eobs:repair-charges', ['--dry-run' => true, '--patient' => $this->patient->id])
            ->expectsOutput('claims repaired: 1')->expectsOutput('lines repaired: 2')->assertSuccessful();
        $this->assertSame(2, $claim->lines()->whereNull('total_charges')->count(), 'a dry run writes nothing');

        $this->artisan('phr:eobs:repair-charges', ['--patient' => $this->patient->id])
            ->expectsOutput('claims repaired: 1')->expectsOutput('lines repaired: 2')->expectsOutput('claims skipped: 0')->assertSuccessful();

        $lines = $claim->lines()->orderBy('line_number')->get();
        $this->assertEqualsWithDelta(-1234.56, (float) $lines[0]->total_charges, 0.001);
        $this->assertEqualsWithDelta(-12.34, (float) $lines[1]->total_charges, 0.001);
        $this->assertSame($unrelated, $lines->map->only(['plan_payment', 'copay_applied', 'patient_responsibility'])->all());

        $parsedAgain = (new MeritainEobParser)->parse(MeritainEobParserTest::reversalText(), 'EOB_SYNTHETIC.pdf');
        $this->assertSame((new MeritainEobFingerprint)->fromParsed($parsedAgain), $claim->refresh()->claim_fingerprint, 'importing the PDF again finds this claim');
        $this->assertSame(MeritainEobParser::PARSER_VERSION, $claim->parser_version);

        $this->artisan('phr:eobs:repair-charges')->expectsOutput('lines repaired: 0')->assertSuccessful();
    }

    public function test_a_claim_whose_fingerprint_does_not_describe_it_is_left_alone(): void
    {
        $claim = $this->claimImportedByTheOldParser();
        $claim->forceFill(['claim_fingerprint' => str_repeat('a', 64)])->save();

        $this->artisan('phr:eobs:repair-charges')->expectsOutput('lines repaired: 0')->expectsOutput('claims skipped: 1')->assertSuccessful();

        $this->assertSame(2, $claim->lines()->whereNull('total_charges')->count());
        $this->assertSame(str_repeat('a', 64), $claim->refresh()->claim_fingerprint);
    }

    public function test_a_line_with_no_charge_printed_stays_empty_and_a_stored_charge_is_never_replaced(): void
    {
        $claim = $this->claimImportedByTheOldParser(static function (array $parsed): array {
            $parsed['lines'][0]['raw_text'] = '99B               04-25-22';
            $parsed['lines'][1]['total_charges'] = '-99.99';

            return $parsed;
        });

        $this->artisan('phr:eobs:repair-charges')->expectsOutput('lines repaired: 0')->assertSuccessful();

        $lines = $claim->lines()->orderBy('line_number')->get();
        $this->assertNull($lines[0]->total_charges);
        $this->assertEqualsWithDelta(-99.99, (float) $lines[1]->total_charges, 0.001);
    }

    public function test_it_stays_inside_the_named_patient_and_prints_no_amounts(): void
    {
        $claim = $this->claimImportedByTheOldParser();
        $other = PhrPatient::query()->create(['owner_user_id' => $this->patient->owner_user_id, 'display_name' => 'Other patient']);

        $this->artisan('phr:eobs:repair-charges', ['--patient' => $other->id])->expectsOutput('lines repaired: 0')->assertSuccessful();
        $this->assertSame(2, $claim->lines()->whereNull('total_charges')->count());

        $this->artisan('phr:eobs:repair-charges', ['--patient' => $this->patient->id])
            ->doesntExpectOutputToContain('1234.56')->doesntExpectOutputToContain('SYN0001')->assertSuccessful();
    }

    /** @param (callable(array<string, mixed>): array<string, mixed>)|null $alter */
    private function claimImportedByTheOldParser(?callable $alter = null): PhrEob
    {
        $parsed = (new MeritainEobParser)->parse(MeritainEobParserTest::reversalText(), 'EOB_SYNTHETIC.pdf');
        foreach (array_keys($parsed['lines']) as $index) {
            $parsed['lines'][$index]['total_charges'] = null;
        }
        $parsed = $alter === null ? $parsed : $alter($parsed);
        $document = PhrDocument::query()->create([
            'patient_id' => $this->patient->id, 'user_id' => $this->patient->owner_user_id, 'uploaded_by_user_id' => $this->patient->owner_user_id,
            'title' => 'Synthetic EOB', 'document_type' => 'other', 'original_filename' => 'synthetic.pdf', 'storage_disk' => PhrDocument::STORAGE_DISK,
            'storage_path' => 'synthetic/eob.pdf', 'mime_type' => 'application/pdf', 'byte_size' => 1, 'file_hash' => hash('sha256', 'synthetic'), 'source' => 'manual_upload',
        ]);
        $importer = app(MeritainEobImporter::class);
        $importer->persistClaim($this->patient, $parsed, $document->id, 'synthetic-external', (new MeritainEobFingerprint)->fromParsed($parsed), MeritainEobParserTest::reversalText());

        return PhrEob::query()->where('patient_id', $this->patient->id)->sole();
    }
}
