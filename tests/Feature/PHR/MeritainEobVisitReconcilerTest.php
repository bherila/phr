<?php

namespace Tests\Feature\PHR;

use App\Models\PhrEob;
use App\Models\PhrEobLine;
use App\Models\PhrOfficeVisit;
use App\Models\PhrPatient;
use App\Models\User;
use App\Services\PHR\Import\MeritainEobVisitReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeritainEobVisitReconcilerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_consolidates_same_provider_date_claims_and_is_idempotent(): void
    {
        [$patient, $user] = $this->patient();
        $first = $this->eob($patient, $user, 'SYNTH-001', '2030-02-03', 'Example Clinic');
        $second = $this->eob($patient, $user, 'SYNTH-002', '2030-02-04', 'EXAMPLE CLINIC');
        $this->line($first, '99214', '2030-02-01');
        $this->line($first, '36415', '2030-02-01', 2);
        $this->line($second, '99213', '2030-02-01');

        $reconciler = app(MeritainEobVisitReconciler::class);
        self::assertSame([
            'candidates' => 1,
            'claims' => 2,
            'created' => 1,
            'matched' => 0,
            'updated' => 0,
            'links' => 2,
        ], $reconciler->reconcile($patient, true));
        self::assertDatabaseCount('phr_office_visits', 0);

        self::assertSame(1, $reconciler->reconcile($patient)['created']);
        $visit = PhrOfficeVisit::sole();
        self::assertSame('2030-02-01', $visit->visit_date?->format('Y-m-d'));
        self::assertSame(['99213', '99214'], collect($visit->cpt_codes)->pluck('code')->all());
        self::assertDatabaseCount('phr_office_visit_eobs', 2);

        $rerun = $reconciler->reconcile($patient);
        self::assertSame(0, $rerun['created']);
        self::assertSame(1, $rerun['matched']);
        self::assertSame(0, $rerun['updated']);
        self::assertSame(0, $rerun['links']);
        self::assertDatabaseCount('phr_office_visits', 1);
        self::assertDatabaseCount('phr_office_visit_eobs', 2);
    }

    public function test_it_enriches_a_single_existing_visit_on_the_same_date_without_replacing_its_source(): void
    {
        [$patient, $user] = $this->patient();
        $visit = PhrOfficeVisit::create([
            'patient_id' => $patient->id,
            'user_id' => $user->id,
            'source_document_id' => null,
            'visit_date' => '2030-03-01',
            'provider_name' => 'Clinical Note Provider, MD',
        ]);
        $eob = $this->eob($patient, $user, 'SYNTH-003', '2030-03-02', 'Billing Provider');
        $this->line($eob, '99215', '2030-03-01');

        $result = app(MeritainEobVisitReconciler::class)->reconcile($patient);

        self::assertSame(0, $result['created']);
        self::assertSame(1, $result['matched']);
        self::assertSame(1, $result['updated']);
        self::assertSame(1, $result['links']);
        self::assertSame('99215', PhrOfficeVisit::findOrFail($visit->id)->cpt_codes[0]['code']);
        self::assertDatabaseCount('phr_office_visits', 1);
        self::assertDatabaseHas('phr_office_visit_eobs', [
            'office_visit_id' => $visit->id,
            'eob_id' => $eob->id,
        ]);
    }

    /** @return array{PhrPatient, User} */
    private function patient(): array
    {
        $user = User::factory()->create();
        $patient = PhrPatient::create([
            'owner_user_id' => $user->id,
            'display_name' => 'Synthetic Patient',
        ]);

        return [$patient, $user];
    }

    private function eob(PhrPatient $patient, User $user, string $claim, string $processed, string $provider): PhrEob
    {
        return PhrEob::create([
            'patient_id' => $patient->id,
            'user_id' => $user->id,
            'import_source' => 'meritain_eob',
            'external_id' => 'synthetic:'.$claim,
            'claim_fingerprint' => hash('sha256', $claim),
            'claim_number' => $claim,
            'claim_type' => 'medical',
            'provider_name' => $provider,
            'processed_date' => $processed,
        ]);
    }

    private function line(PhrEob $eob, string $code, string $date, int $lineNumber = 1): PhrEobLine
    {
        return PhrEobLine::create([
            'eob_id' => $eob->id,
            'patient_id' => $eob->patient_id,
            'line_number' => $lineNumber,
            'procedure_code' => $code,
            'code_type' => 'cpt',
            'service_start' => $date,
            'service_end' => $date,
        ]);
    }
}
