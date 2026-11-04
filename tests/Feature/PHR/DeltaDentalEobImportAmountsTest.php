<?php

namespace Tests\Feature\PHR;

use App\Models\PhrDocument;
use App\Models\PhrEob;
use App\Models\PhrEobLine;
use App\Models\PhrPatient;
use App\Services\PHR\Import\DeltaDentalEobImporter;
use App\Services\PHR\Import\EobPdfTextExtractor;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Tests\TestCase;
use Tests\Unit\Services\PHR\DeltaDentalClaimAmountsTest;

final class DeltaDentalEobImportAmountsTest extends TestCase
{
    private string $directory;

    private PhrPatient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(PhrDocument::STORAGE_DISK);
        $owner = $this->createUser();
        $this->patient = PhrPatient::query()->create(['owner_user_id' => $owner->id, 'display_name' => 'Synthetic Patient']);
        $this->directory = sys_get_temp_dir().'/phr-delta-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
        file_put_contents($this->directory.'/claims.csv', <<<'CSV'
Claim Number,Date of Service,Submission Date,Member Name,Dentist,Phone,Procedure,Procedure Code,Accepted Fee,Claim Deductible,Delta Dental Pays,You Pay
20300102000001,2030-01-02,2030-01-03,Synthetic Patient,EXAMPLE DENTIST,(555) 010-0200,Evaluation,D0120,178.00,30.00,140.00,38.00
20300102000001,2030-01-02,2030-01-03,Synthetic Patient,EXAMPLE DENTIST,(555) 010-0200,Cleaning,D1110,46.00,5.00,40.00,6.00
20300102000001,2030-01-02,2030-01-03,Synthetic Patient,EXAMPLE DENTIST,(555) 010-0200,Imaging,D0274,77.00,15.00,60.00,17.00
CSV);
        file_put_contents($this->directory.'/Delta EOB 20300102000001.pdf', '%PDF-1.4 synthetic');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_the_directory_import_persists_true_amounts_and_their_provenance(): void
    {
        $this->pdfText(DeltaDentalClaimAmountsTest::pdf());
        $importer = app(DeltaDentalEobImporter::class);
        $dryRun = $importer->importDirectory($this->patient, $this->patient->owner_user_id, $this->directory, true);
        $this->assertSame(1, $dryRun['imported']);
        $this->assertSame(0, PhrEob::query()->count());
        $result = $importer->importDirectory($this->patient, $this->patient->owner_user_id, $this->directory);
        $this->assertSame(0, $result['failures']);
        $this->assertSame(1, $result['imported']);
        $eob = PhrEob::query()->sole();
        $this->assertSame('178.00', $eob->total_accepted_fee);
        $this->assertSame(['55.00', '46.00', '77.00'], $eob->lines()->orderBy('line_number')->pluck('accepted_fee')->all());
        $this->assertTrue($eob->parsed_data['amount_validation']['first_row_derived']);
        $first = $eob->lines()->orderBy('line_number')->firstOrFail();
        $this->assertSame('178.00', $first->parsed_data['source_row']['Accepted Fee']);
        $this->assertSame('55.00', $first->parsed_data['amount_derivation']['amounts']['accepted_fee']['derived_amount']);
        $repeat = $importer->importDirectory($this->patient, $this->patient->owner_user_id, $this->directory);
        $this->assertSame(1, $repeat['skipped']);
        $this->assertSame(1, PhrEob::query()->count());
    }

    public function test_a_mismatch_is_flagged_before_creating_documents_or_claims_even_in_a_dry_run(): void
    {
        $this->pdfText(str_replace('$178.00', '$179.00', DeltaDentalClaimAmountsTest::pdf()));
        foreach ([true, false] as $dryRun) {
            $result = app(DeltaDentalEobImporter::class)->importDirectory($this->patient, $this->patient->owner_user_id, $this->directory, $dryRun);
            $this->assertSame(1, $result['failures']);
            $this->assertSame(0, $result['imported']);
            $this->assertStringContainsString('do not match', $result['warnings'][0]);
        }
        $this->assertSame(0, PhrEob::query()->count());
        $this->assertSame(0, PhrEobLine::query()->count());
        $this->assertSame(0, PhrDocument::query()->count());
    }

    private function pdfText(string $text): void
    {
        $this->mock(EobPdfTextExtractor::class, function (MockInterface $mock) use ($text): void {
            $mock->shouldReceive('extract')->andReturn($text);
        });
    }
}
