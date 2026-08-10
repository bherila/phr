<?php

namespace Tests\Feature\PHR;

use App\Models\PhrEob;
use App\Models\PhrEobLine;
use App\Models\PhrPatient;
use App\Models\PhrProcedure;
use App\Models\User;
use App\Services\PHR\Import\MeritainEobAllergyProcedureReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeritainEobAllergyProcedureReconcilerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_consolidates_claims_by_date_provider_and_code_and_is_idempotent(): void
    {
        [$patient, $user] = $this->patient();
        $first = $this->eob($patient, $user, 'SYNTH-001', '2030-02-03');
        $second = $this->eob($patient, $user, 'SYNTH-002', '2030-02-04');
        $this->line($first, '95117', '2030-02-01');
        $this->line($second, '95117', '2030-02-01');
        $this->line($second, '95165', '2030-02-01', 2);

        $reconciler = app(MeritainEobAllergyProcedureReconciler::class);
        self::assertSame([
            'candidates' => 2,
            'claims' => 3,
            'created' => 2,
            'matched' => 0,
            'links' => 3,
        ], $reconciler->reconcile($patient, true));
        self::assertDatabaseCount('phr_procedures', 0);

        self::assertSame(2, $reconciler->reconcile($patient)['created']);
        self::assertDatabaseCount('phr_procedures', 2);
        self::assertDatabaseCount('phr_procedure_eobs', 3);
        $administration = PhrProcedure::where('cpt_code', '95117')->sole();
        self::assertSame('2030-02-01', $administration->performed_on?->format('Y-m-d'));
        self::assertSame('completed', $administration->status);

        $rerun = $reconciler->reconcile($patient);
        self::assertSame(0, $rerun['created']);
        self::assertSame(2, $rerun['matched']);
        self::assertSame(0, $rerun['links']);
        self::assertDatabaseCount('phr_procedures', 2);
        self::assertDatabaseCount('phr_procedure_eobs', 3);
    }

    public function test_it_links_an_existing_procedure_without_replacing_its_source_or_notes(): void
    {
        [$patient, $user] = $this->patient();
        $procedure = PhrProcedure::create([
            'patient_id' => $patient->id,
            'user_id' => $user->id,
            'name' => 'Existing allergy administration',
            'cpt_code' => '95117',
            'performed_on' => '2030-03-01',
            'status' => 'completed',
            'notes' => 'Existing evidence note.',
        ]);
        $eob = $this->eob($patient, $user, 'SYNTH-003', '2030-03-02');
        $this->line($eob, '95117', '2030-03-01');

        $result = app(MeritainEobAllergyProcedureReconciler::class)->reconcile($patient);

        self::assertSame(0, $result['created']);
        self::assertSame(1, $result['matched']);
        self::assertSame(1, $result['links']);
        self::assertSame('Existing evidence note.', $procedure->refresh()->notes);
        self::assertDatabaseCount('phr_procedures', 1);
        self::assertDatabaseHas('phr_procedure_eobs', [
            'procedure_id' => $procedure->id,
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

    private function eob(PhrPatient $patient, User $user, string $claim, string $processed): PhrEob
    {
        return PhrEob::create([
            'patient_id' => $patient->id,
            'user_id' => $user->id,
            'import_source' => 'meritain_eob',
            'external_id' => 'synthetic:'.$claim,
            'claim_fingerprint' => hash('sha256', $claim),
            'claim_number' => $claim,
            'claim_type' => 'medical',
            'provider_name' => 'Synthetic Allergy Clinic',
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
