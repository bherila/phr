<?php

namespace Tests\Feature\PHR;

use App\Models\PhrEob;
use App\Models\PhrEobLine;
use App\Models\PhrOfficeVisit;
use App\Models\PhrPatient;
use App\Models\User;
use App\Services\PHR\Import\DeltaDentalEobImporter;
use App\Services\PHR\Import\DeltaDentalEobVisitReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeltaDentalEobVisitReconcilerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_dental_visit_with_cdt_codes_and_is_idempotent(): void
    {
        $user = User::factory()->create();
        $patient = PhrPatient::create([
            'owner_user_id' => $user->id,
            'display_name' => 'Synthetic Patient',
        ]);
        $eob = PhrEob::create([
            'patient_id' => $patient->id,
            'user_id' => $user->id,
            'import_source' => DeltaDentalEobImporter::IMPORT_SOURCE,
            'external_id' => 'synthetic:delta:1',
            'claim_fingerprint' => hash('sha256', 'synthetic-delta-1'),
            'claim_number' => '20300102000001',
            'claim_type' => 'dental',
            'provider_name' => 'EXAMPLE DENTIST',
            'submission_date' => '2030-01-03',
        ]);
        foreach ([
            ['D0120', 'Periodic oral evaluation'],
            ['D1110', 'Adult cleaning'],
        ] as $index => [$code, $description]) {
            PhrEobLine::create([
                'eob_id' => $eob->id,
                'patient_id' => $patient->id,
                'line_number' => $index + 1,
                'procedure_code' => $code,
                'code_type' => 'CDT',
                'description' => $description,
                'service_start' => '2030-01-02',
                'service_end' => '2030-01-02',
            ]);
        }

        $reconciler = app(DeltaDentalEobVisitReconciler::class);
        self::assertSame([
            'candidates' => 1,
            'claims' => 1,
            'created' => 1,
            'matched' => 0,
            'updated' => 0,
            'links' => 1,
        ], $reconciler->reconcile($patient, true));
        self::assertDatabaseCount('phr_office_visits', 0);

        self::assertSame(1, $reconciler->reconcile($patient)['created']);
        $visit = PhrOfficeVisit::sole();
        self::assertSame('dental examination and cleaning', $visit->visit_type);
        self::assertSame('Dentistry', $visit->provider_specialty);
        self::assertSame(['D0120', 'D1110'], collect($visit->cpt_codes)->pluck('code')->all());
        self::assertDatabaseCount('phr_office_visit_eobs', 1);

        $rerun = $reconciler->reconcile($patient);
        self::assertSame(0, $rerun['created']);
        self::assertSame(1, $rerun['matched']);
        self::assertSame(0, $rerun['updated']);
        self::assertSame(0, $rerun['links']);
    }
}
