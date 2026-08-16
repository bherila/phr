<?php

namespace App\Services\PHR\Import;

use App\GenAiProcessor\Models\GenAiImportJob;
use App\GenAiProcessor\Models\GenAiImportResult;
use UnexpectedValueException;

/** Typed persistence boundary for validated extraction proposals. */
final class PhrImportProposalDao
{
    /**
     * @param  array<array-key, mixed>  $data
     */
    public function createForJob(GenAiImportJob $job, array $data): int
    {
        if ($job->job_type === 'phr_document') {
            if (array_is_list($data)) {
                throw new UnexpectedValueException('The document import payload must be an object.');
            }

            $this->create($job, 0, $data);

            return 1;
        }

        $records = $this->records($data);
        $created = 0;
        foreach ($records as $index => $record) {
            if (! is_array($record) || array_is_list($record)) {
                continue;
            }

            $this->create($job, $index, $record);
            $created++;
        }

        if ($records !== [] && $created === 0) {
            throw new UnexpectedValueException('The import response contained no object proposals.');
        }

        return $created;
    }

    /** @param array<string, mixed> $data */
    private function create(GenAiImportJob $job, int|string $index, array $data): void
    {
        GenAiImportResult::query()->create([
            'job_id' => $job->id,
            'result_index' => (int) $index,
            'result_json' => json_encode($data, JSON_THROW_ON_ERROR),
            'status' => 'pending_review',
        ]);
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<int, mixed>
     */
    private function records(array $data): array
    {
        if (array_is_list($data)) {
            return $data;
        }

        foreach (['records', 'lab_results', 'vitals', 'office_visits', 'medications', 'immunizations', 'conditions', 'procedures', 'allergies'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                return array_is_list($data[$key]) ? $data[$key] : [$data[$key]];
            }
        }

        return [$data];
    }
}
