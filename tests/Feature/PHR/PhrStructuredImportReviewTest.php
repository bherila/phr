<?php

namespace Tests\Feature\PHR;

use App\Models\PhrCondition;
use App\Models\PhrImmunization;
use App\Models\PhrLabResult;
use App\Models\PhrPatient;
use App\Models\PhrPatientVital;
use App\Models\User;
use App\Services\PHR\Import\PhrCcdaImporter;
use App\Services\PHR\Import\PhrFhirImporter;
use App\Services\PHR\Import\PhrStructuredDataImporter;
use App\Support\PHR\PhrReviewStatus;
use Tests\Support\PhrCcdaSyntheticDocument;
use Tests\TestCase;

/**
 * Records a C-CDA, FHIR or MyChart file writes directly are unreviewed: they land
 * pending review, and a re-import that changes one reopens its review, while one
 * that leaves it as it is keeps the person's decision.
 */
class PhrStructuredImportReviewTest extends TestCase
{
    public function test_fhir_rows_land_pending_review_and_an_unchanged_reimport_keeps_decisions(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $importer = app(PhrFhirImporter::class);

        $first = $importer->importBundle($this->bundle('Hypertension', 13.2), $patient, $owner->id);
        self::assertSame(3, $first->created);
        foreach ([PhrCondition::class, PhrLabResult::class, PhrPatientVital::class] as $model) {
            self::assertSame(PhrReviewStatus::PENDING, $model::query()->where('patient_id', $patient->id)->sole()->review_status, $model);
        }

        // The person confirms one row and rejects another.
        PhrCondition::query()->where('patient_id', $patient->id)->update(['review_status' => PhrReviewStatus::CONFIRMED]);
        PhrPatientVital::query()->where('patient_id', $patient->id)->update(['review_status' => PhrReviewStatus::REJECTED]);

        $again = $importer->importBundle($this->bundle('Hypertension', 13.2), $patient, $owner->id);
        self::assertSame(['created' => 0, 'updated' => 0, 'unchanged' => 3], ['created' => $again->created, 'updated' => $again->updated, 'unchanged' => $again->unchanged]);
        self::assertSame(PhrReviewStatus::CONFIRMED, PhrCondition::query()->where('patient_id', $patient->id)->sole()->review_status);
        self::assertSame(PhrReviewStatus::REJECTED, PhrPatientVital::query()->where('patient_id', $patient->id)->sole()->review_status);

        // A change in the source reopens review of that record only.
        $changed = $importer->importBundle($this->bundle('Essential hypertension', 13.2), $patient, $owner->id);
        self::assertSame(['created' => 0, 'updated' => 1, 'unchanged' => 2], ['created' => $changed->created, 'updated' => $changed->updated, 'unchanged' => $changed->unchanged]);
        $condition = PhrCondition::query()->where('patient_id', $patient->id)->sole();
        self::assertSame('Essential hypertension', $condition->name);
        self::assertSame(PhrReviewStatus::PENDING, $condition->review_status);
        self::assertSame(PhrReviewStatus::REJECTED, PhrPatientVital::query()->where('patient_id', $patient->id)->sole()->review_status);
    }

    public function test_an_identical_reimport_is_unchanged_despite_import_bookkeeping_and_decimal_precision(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $importer = app(PhrFhirImporter::class);
        $bundle = ['resourceType' => 'Bundle', 'type' => 'collection', 'entry' => [
            // More decimal places than the column keeps.
            ['resource' => ['resourceType' => 'Observation', 'id' => 'lab-precise', 'category' => [['coding' => [['code' => 'laboratory']]]], 'code' => ['text' => 'Synthetic ratio'], 'effectiveDateTime' => '2026-01-15T10:00:00-08:00', 'valueQuantity' => ['value' => 0.123456789012, 'unit' => '1']]],
            // A document's import time is set afresh on every import.
            ['resource' => ['resourceType' => 'DocumentReference', 'id' => 'doc-1', 'description' => 'Synthetic note', 'date' => '2026-01-15T10:00:00-08:00', 'content' => [['attachment' => ['contentType' => 'text/plain', 'title' => 'Synthetic note']]]]],
        ]];

        $first = $importer->importBundle($bundle, $patient, $owner->id);
        self::assertSame(2, $first->created, 'the lab result and the document');
        self::assertSame(1, $first->documents);
        $this->travel(1)->minutes();
        $again = $importer->importBundle($bundle, $patient, $owner->id);

        self::assertSame(['created' => 0, 'updated' => 0], ['created' => $again->created, 'updated' => $again->updated], 'nothing the source holds changed');
        self::assertSame(2, $again->unchanged);
    }

    public function test_ccda_rows_land_pending_review(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();

        $result = app(PhrCcdaImporter::class)->importXml(PhrCcdaSyntheticDocument::xml(), $patient, $owner->id);

        self::assertGreaterThan(0, $result->created);
        self::assertGreaterThan(0, PhrImmunization::query()->where('patient_id', $patient->id)->count());
        foreach ([PhrImmunization::class, PhrCondition::class] as $model) {
            self::assertSame([], $model::query()->where('patient_id', $patient->id)->where('review_status', '!=', PhrReviewStatus::PENDING)->pluck('id')->all(), $model);
        }
    }

    public function test_a_reviewed_ai_proposal_still_lands_as_reviewed(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();

        // Accepting an AI import proposal is the review, so its rows are confirmed.
        app(PhrStructuredDataImporter::class)->importPayload($patient, $owner->id, 'phr_problem_list', [['name' => 'Synthetic reviewed condition']], ['import_source' => 'genai', 'external_id' => 'proposal-1']);

        self::assertSame(PhrReviewStatus::CONFIRMED, PhrCondition::query()->where('patient_id', $patient->id)->sole()->review_status);
    }

    /** @return array{0: User, 1: PhrPatient} */
    private function ownerAndPatient(): array
    {
        $owner = $this->createUser();

        return [$owner, PhrPatient::query()->create(['owner_user_id' => $owner->id, 'display_name' => 'Synthetic Patient', 'relationship' => 'self'])];
    }

    /** @return array<string, mixed> */
    private function bundle(string $condition, float $hemoglobin): array
    {
        return [
            'resourceType' => 'Bundle',
            'type' => 'collection',
            'entry' => [
                ['resource' => ['resourceType' => 'Observation', 'id' => 'lab-1', 'category' => [['coding' => [['code' => 'laboratory']]]], 'code' => ['text' => 'Hemoglobin'], 'effectiveDateTime' => '2026-01-15T10:00:00-08:00', 'valueQuantity' => ['value' => $hemoglobin, 'unit' => 'g/dL']]],
                ['resource' => ['resourceType' => 'Observation', 'id' => 'vital-1', 'category' => [['coding' => [['code' => 'vital-signs']]]], 'code' => ['text' => 'Heart Rate'], 'effectiveDateTime' => '2026-01-15T10:05:00-08:00', 'valueQuantity' => ['value' => 72, 'unit' => 'beats/min']]],
                ['resource' => ['resourceType' => 'Condition', 'id' => 'condition-1', 'code' => ['text' => $condition], 'clinicalStatus' => ['text' => 'active'], 'verificationStatus' => ['text' => 'confirmed']]],
            ],
        ];
    }
}
