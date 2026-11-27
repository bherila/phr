<?php

namespace Tests\Feature\PHR;

use App\Models\PhrCondition;
use App\Models\PhrDocument;
use App\Models\PhrEob;
use App\Models\PhrEpisode;
use App\Models\PhrMedication;
use App\Models\PhrMedicationFill;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Services\PHR\Review\ClinicalReviewDecisions;
use App\Support\AgentApi\AgentClinicalRecordVersion;
use App\Support\PHR\PhrReviewStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ClinicalBatchReviewTest extends TestCase
{
    /** @return array<string, array{string, class-string<Model>, array<string, mixed>}> */
    public static function records(): array
    {
        $cases = [];
        foreach (ClinicalReviewControllerTest::reviewableResources() as $name => [$segment, $key, $model, $attributes]) {
            $cases[$name] = [$segment, $model, $attributes];
        }
        $cases['documents'] = ['documents', PhrDocument::class, ['title' => 'Synthetic note', 'source' => 'manual_upload', 'file_path' => 'synthetic.txt', 'original_filename' => 'synthetic.txt', 'mime_type' => 'text/plain', 'file_size' => 0]];
        $cases['eobs'] = ['eobs', PhrEob::class, ['claim_type' => 'medical', 'import_source' => 'synthetic', 'external_id' => 'synthetic-eob']];
        $cases['episodes'] = ['episodes', PhrEpisode::class, ['title' => 'Synthetic episode']];
        $cases['medication-fills'] = ['medication-fills', PhrMedicationFill::class, ['drug_as_printed' => 'Synthetic medication', 'filled_on' => '2026-11-01', 'source_kind' => 'manual', 'quantity' => 1]];

        return $cases;
    }

    /**
     * @param  class-string<Model>  $model
     * @param  array<string, mixed>  $attributes
     */
    #[DataProvider('records')]
    public function test_the_batch_and_single_routes_apply_the_same_review_rules(string $segment, string $model, array $attributes): void
    {
        [$owner, $patient] = $this->patient();
        $record = $model::query()->create(['patient_id' => $patient->id, 'user_id' => $owner->id, 'review_status' => PhrReviewStatus::PENDING, ...$attributes])->refresh();
        $item = $this->item($segment, $record);
        $this->actingAs($owner)->postJson($this->url($patient), ['review_status' => 'confirmed', 'items' => [$item]])
            ->assertOk()->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertJsonPath('done', [['segment' => $segment, 'id' => $record->id]])->assertJsonPath('failed', []);
        self::assertSame('confirmed', $record->refresh()->getAttribute('review_status'));

        // The pre-approval version is stale on both paths, and cannot reject unseen content.
        $this->patchJson("/api/phr/patients/{$patient->id}/{$segment}/{$record->id}/review", ['review_status' => 'rejected', 'version' => $item['version']])->assertConflict();
        $this->postJson($this->url($patient), ['review_status' => 'rejected', 'items' => [$item]])
            ->assertOk()->assertJsonCount(1, 'failed')->assertJsonPath('done', []);
        self::assertSame('confirmed', $record->refresh()->getAttribute('review_status'));
        $this->postJson($this->url($patient), ['review_status' => 'rejected', 'items' => [$this->item($segment, $record)]])->assertOk()->assertJsonCount(1, 'done');
        self::assertSame('rejected', $record->refresh()->getAttribute('review_status'));
    }

    public function test_stale_missing_deleted_and_foreign_records_fail_without_blocking_other_records_or_leaking_data(): void
    {
        [$owner, $patient] = $this->patient();
        [, $other] = $this->patient();
        $good = $this->medication($patient);
        $stale = $this->medication($patient);
        $staleItem = $this->item('medications', $stale);
        $stale->update(['name' => 'Synthetic corrected name']);
        $foreign = $this->medication($other);
        $deleted = $this->medication($patient);
        $deletedItem = $this->item('medications', $deleted);
        $deleted->delete();
        $condition = PhrCondition::query()->create(['patient_id' => $patient->id, 'user_id' => $owner->id, 'name' => 'Synthetic condition', 'review_status' => PhrReviewStatus::PENDING])->refresh();
        $items = [$this->item('medications', $good), $staleItem, $this->item('medications', $foreign), $deletedItem, ['segment' => 'medications', 'id' => 999999, 'version' => str_repeat('a', 64)], $this->item('conditions', $condition)];
        $response = $this->actingAs($owner)->postJson($this->url($patient), ['review_status' => 'confirmed', 'items' => $items])->assertOk()
            ->assertJsonCount(2, 'done')->assertJsonCount(4, 'failed');
        $failures = collect($response->json('failed'))->keyBy('id');
        self::assertSame($failures[999999]['error'], $failures[$foreign->id]['error']);
        self::assertSame($failures[999999]['error'], $failures[$deleted->id]['error']);
        self::assertStringContainsString('changed since', $failures[$stale->id]['error']);
        self::assertSame('confirmed', $good->refresh()->review_status);
        self::assertSame('confirmed', $condition->refresh()->review_status);
        self::assertSame(PhrReviewStatus::PENDING, $foreign->refresh()->review_status);
        self::assertSame(PhrReviewStatus::PENDING, $stale->refresh()->review_status);
        $response->assertJsonMissingPath('version');
    }

    public function test_only_a_patient_owner_or_manager_can_review(): void
    {
        [$owner, $patient] = $this->patient();
        $record = $this->medication($patient);
        $body = ['review_status' => 'confirmed', 'items' => [$this->item('medications', $record)]];
        $this->postJson($this->url($patient), $body)->assertUnauthorized();
        $stranger = $this->createUser();
        $this->actingAs($stranger)->postJson($this->url($patient), $body)->assertNotFound();
        foreach (['viewer', 'manager'] as $level) {
            $actor = $this->createUser();
            PhrPatientUserAccess::query()->create(['patient_id' => $patient->id, 'user_id' => $actor->id, 'access_level' => $level, 'granted_by_user_id' => $owner->id, 'granted_at' => now()]);
            $response = $this->actingAs($actor)->postJson($this->url($patient), $body);
            if ($level === 'viewer') {
                $response->assertForbidden();
                self::assertSame(PhrReviewStatus::PENDING, $record->refresh()->review_status);
            } else {
                $response->assertOk()->assertJsonCount(1, 'done');
            }
        }
    }

    public function test_batches_are_bounded_versioned_and_cannot_write_other_fields_or_arbitrary_models(): void
    {
        [$owner, $patient] = $this->patient();
        $record = $this->medication($patient);
        $item = $this->item('medications', $record);
        $this->actingAs($owner);
        foreach ([
            ['review_status' => 'pending_review', 'items' => [$item]],
            ['review_status' => 'confirmed', 'items' => []],
            ['review_status' => 'confirmed', 'items' => [array_diff_key($item, ['version' => true])]],
            ['review_status' => 'confirmed', 'items' => [[...$item, 'version' => 'short']]],
            ['review_status' => 'confirmed', 'items' => [[...$item, 'segment' => 'users']]],
            ['review_status' => 'confirmed', 'items' => [[...$item, 'name' => 'Unseen edit']]],
            ['review_status' => 'confirmed', 'items' => [$item, $item]],
            ['review_status' => 'confirmed', 'items' => array_fill(0, ClinicalReviewDecisions::BATCH_LIMIT + 1, $item)],
        ] as $body) {
            $this->postJson($this->url($patient), $body)->assertUnprocessable();
        }
        self::assertSame(PhrReviewStatus::PENDING, $record->refresh()->review_status);
    }

    public function test_a_hundred_reviews_read_the_clinical_table_once_and_keep_model_save_hooks(): void
    {
        [$owner, $patient] = $this->patient();
        $items = [];
        for ($i = 0; $i < 100; $i++) {
            $items[] = $this->item('medications', $this->medication($patient));
        }
        $saved = 0;
        PhrMedication::saved(static function () use (&$saved): void {
            $saved++;
        });
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $this->actingAs($owner)->postJson($this->url($patient), ['review_status' => 'confirmed', 'items' => $items])->assertOk()->assertJsonCount(100, 'done')->assertJsonPath('failed', []);
            $reads = array_filter(DB::getQueryLog(), static fn (array $query): bool => str_starts_with(strtolower($query['query']), 'select') && str_contains($query['query'], '"phr_medications"'));
            self::assertCount(1, $reads);
            self::assertSame(100, $saved);
        } finally {
            DB::disableQueryLog();
        }
    }

    public function test_an_unexpected_write_failure_rolls_back_the_request_instead_of_reporting_false_success(): void
    {
        [$owner, $patient] = $this->patient();
        $first = $this->medication($patient);
        $second = $this->medication($patient);
        PhrMedication::updating(static function (PhrMedication $record) use ($second): void {
            if ($record->id === $second->id) {
                throw new RuntimeException('Synthetic write failure');
            }
        });
        $this->actingAs($owner)->postJson($this->url($patient), ['review_status' => 'confirmed', 'items' => [$this->item('medications', $first), $this->item('medications', $second)]])->assertStatus(500);
        self::assertSame(PhrReviewStatus::PENDING, $first->refresh()->review_status);
        self::assertSame(PhrReviewStatus::PENDING, $second->refresh()->review_status);
    }

    /** @return array{User, PhrPatient} */
    private function patient(): array
    {
        $owner = $this->createUser();

        return [$owner, PhrPatient::query()->create(['owner_user_id' => $owner->id, 'display_name' => 'Synthetic patient'])];
    }

    private function medication(PhrPatient $patient): PhrMedication
    {
        return PhrMedication::factory()->create(['patient_id' => $patient->id, 'user_id' => $patient->owner_user_id, 'review_status' => PhrReviewStatus::PENDING])->refresh();
    }

    /** @return array{segment: string, id: int, version: string} */
    private function item(string $segment, Model $record): array
    {
        return ['segment' => $segment, 'id' => (int) $record->getKey(), 'version' => app(AgentClinicalRecordVersion::class)->for($record)];
    }

    private function url(PhrPatient $patient): string
    {
        return "/api/phr/patients/{$patient->id}/review/records";
    }
}
