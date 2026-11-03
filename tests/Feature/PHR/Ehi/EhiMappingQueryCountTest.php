<?php

namespace Tests\Feature\PHR\Ehi;

use App\Models\PhrEhiPackage;
use App\Models\PhrEhiProposal;
use App\Models\PhrEhiSource;
use App\Models\PhrPatient;
use App\Models\User;
use App\Services\PHR\Import\Ehi\EhiBindingService;
use App\Services\PHR\Import\Ehi\EhiProposalService;
use App\Services\PHR\Import\Ehi\Mapping\EhiDocumentMapping;
use App\Services\PHR\Import\Ehi\Mapping\EhiImmunizationMapping;
use App\Services\PHR\Import\Ehi\Mapping\EhiMapping;
use App\Services\PHR\Import\Ehi\Mapping\EhiMappingContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\EhiClinicalPackage;
use Tests\Support\EhiIntakeFixtures;
use Tests\TestCase;

/**
 * What a mapping costs per row, over real intake and projection. A row may not add
 * a query of its own: a preview has a time limit, and the patient-wide budget check
 * alone is about a dozen queries. Pages still cost queries, so these stay under one.
 */
class EhiMappingQueryCountTest extends TestCase
{
    use EhiIntakeFixtures;

    private const int FEW = 8;

    private const int MANY = 40;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpEhi();
        Storage::fake('phr_exports');
        Queue::fake();
    }

    protected function tearDown(): void
    {
        $this->tearDownEhi();
        parent::tearDown();
    }

    /** @return array<string, array{0: class-string<EhiMapping>}> */
    public static function mappings(): array
    {
        return [
            'immunizations' => [EhiImmunizationMapping::class],
            'documents' => [EhiDocumentMapping::class],
        ];
    }

    /** @param  class-string<EhiMapping>  $mapping */
    #[DataProvider('mappings')]
    public function test_query_count_does_not_grow_with_rows_on_a_first_preview(string $mapping): void
    {
        config(['phr.ehi_mappings' => [$mapping]]);

        self::assertSame($this->proposeQueries($mapping, self::FEW, false), $this->proposeQueries($mapping, self::MANY, false));
    }

    /** @param  class-string<EhiMapping>  $mapping */
    #[DataProvider('mappings')]
    public function test_query_count_does_not_grow_with_rows_once_they_have_records_and_links(string $mapping): void
    {
        config(['phr.ehi_mappings' => [$mapping]]);

        self::assertSame($this->proposeQueries($mapping, self::FEW, true), $this->proposeQueries($mapping, self::MANY, true));
    }

    /** @param  class-string<EhiMapping>  $mapping */
    #[DataProvider('mappings')]
    public function test_a_whole_preview_costs_only_what_writing_each_proposal_needs(string $mapping): void
    {
        config(['phr.ehi_mappings' => [$mapping]]);
        $few = $this->previewQueries(self::FEW);
        $many = $this->previewQueries(self::MANY);

        // A proposal's own writes and its digest lookup, not a budget check per row.
        self::assertLessThanOrEqual(6.0, ($many - $few) / (self::MANY - self::FEW));
    }

    /** @param  class-string<EhiMapping>  $mapping */
    private function proposeQueries(string $mapping, int $rows, bool $accepted): int
    {
        [$owner, $patient, $package] = $this->previewed($rows);
        if ($accepted) {
            foreach (PhrEhiProposal::query()->where('package_id', $package->id)->where('status', PhrEhiProposal::STATUS_PENDING)->whereIn('kind', [PhrEhiProposal::KIND_CREATE, PhrEhiProposal::KIND_POSSIBLE_DUPLICATE])->get() as $proposal) {
                app(EhiProposalService::class)->review($proposal, PhrEhiProposal::DECISION_ACCEPT, $owner->id, $proposal->preview_digest);
            }
        }
        $context = new EhiMappingContext($package->refresh(), $patient, PhrEhiSource::query()->where('patient_id', $patient->id)->sole());
        $instance = app($mapping);

        $queries = 0;
        DB::listen(static function () use (&$queries): void {
            $queries++;
        });
        $start = $queries;
        $drafts = [...$instance->propose($context)];
        self::assertGreaterThanOrEqual($rows, count($drafts));

        return $queries - $start;
    }

    private function previewQueries(int $rows): int
    {
        $owner = $this->createUser();
        $patient = $this->ehiPatient($owner);
        $package = $this->ehiIntakeDirectory($this->package($rows)->build(), $patient, (int) $owner->id, 'package-'.$rows)->package;
        $package = app(EhiBindingService::class)->bind($package, $patient, (int) $owner->id, null, 'Synthetic Clinic');
        $queries = 0;
        DB::listen(static function () use (&$queries): void {
            $queries++;
        });
        $start = $queries;
        app(EhiProposalService::class)->preview($package, $patient, (int) $owner->id);

        return $queries - $start;
    }

    /** @return array{0: User, 1: PhrPatient, 2: PhrEhiPackage} */
    private function previewed(int $rows): array
    {
        $owner = $this->createUser();
        $patient = $this->ehiPatient($owner);
        $package = $this->ehiIntakeDirectory($this->package($rows)->build(), $patient, (int) $owner->id, 'package-'.$rows.'-'.bin2hex(random_bytes(2)))->package;
        $package = app(EhiBindingService::class)->bind($package, $patient, (int) $owner->id, null, 'Synthetic Clinic');
        app(EhiProposalService::class)->preview($package, $patient, (int) $owner->id);

        return [$owner, $patient, $package->refresh()];
    }

    /** Doses and notes, each with its joined rows: contacts, text lines, and a document. */
    private function package(int $rows): EhiClinicalPackage
    {
        $key = EhiClinicalPackage::PATIENT_KEY;
        $package = EhiClinicalPackage::standard();
        for ($i = 0; $i < $rows; $i++) {
            $id = (string) (10000 + $i);
            $package = $package
                ->adding('IMMUNE', ['IMMUNE_ID' => $id, 'IMMUNZATN_ID' => '9', 'IMMUNZATN_ID_NAME' => "SYNTHETIC SCALE VACCINE {$i}", 'IMMUNE_DATE' => '2022-01-'.str_pad((string) ($i % 28 + 1), 2, '0', STR_PAD_LEFT), 'IMMNZTN_STATUS_C_NAME' => 'Given', 'IMM_HISTORIC_ADM_YN' => 'N'])
                ->adding('PAT_IMMUNIZATIONS', ['PAT_ID' => $key, 'LINE' => (string) (100 + $i), 'IMMUNE_ID' => $id])
                ->adding('PAT_ENC', ['PAT_ENC_CSN_ID' => "2{$id}", 'PAT_ID' => $key, 'CONTACT_DATE' => '2022-02-01'])
                ->adding('HNO_INFO', ['NOTE_ID' => "N{$id}", 'PAT_ENC_CSN_ID' => "2{$id}", 'IP_NOTE_TYPE_C_NAME' => 'Progress Notes', 'NOTE_DESC' => "Synthetic scale note {$i}", 'CREATE_INSTANT_DTTM' => '2022-02-01 10:00:00', 'UNSIGNED_YN' => 'N'])
                ->adding('NOTE_ENC_INFO', ['CONTACT_SERIAL_NUM' => "3{$id}", 'NOTE_ID' => "N{$id}", 'CONTACT_DATE_REAL' => '65000', 'CONTACT_DATE' => '2022-02-01'])
                ->adding('HNO_PLAIN_TEXT', ['NOTE_CSN_ID' => "3{$id}", 'LINE' => '1', 'NOTE_ID' => "N{$id}", 'NOTE_TEXT' => "Synthetic scale line {$i}"])
                ->adding('HNO_PLAIN_TEXT', ['NOTE_CSN_ID' => "3{$id}", 'LINE' => '2', 'NOTE_ID' => "N{$id}", 'NOTE_TEXT' => "Synthetic scale second line {$i}"]);
        }

        return $package;
    }
}
