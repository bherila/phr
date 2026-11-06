<?php

namespace Tests\Support;

use App\Models\PhrPatient;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Independent expectations over declared synthetic identities/values; never publish clinical values. */
final class EhiCapacityOracle
{
    public function snapshot(PhrPatient $patient): array
    {
        $state = ['clinical' => [], 'lab_static' => [], 'decisions' => [], 'decided_proposals' => []];
        foreach (['phr_lab_results', 'phr_documents', 'phr_immunizations', 'phr_office_visits', 'phr_ehi_proposal_decisions'] as $table) {
            $rows = [];
            foreach (DB::table($table)->where('patient_id', $patient->id)->orderBy('id')->lazyById(100) as $row) {
                $data = (array) $row;
                $rows[(int) $row->id] = $this->hash($data);
                if ($table === 'phr_lab_results') {
                    unset($data['value'], $data['value_numeric'], $data['updated_at']);
                    $state['lab_static'][(int) $row->id] = $this->hash($data);
                }
            }
            if ($table === 'phr_ehi_proposal_decisions') {
                $state['decisions'] = $rows;
            } else {
                $state['clinical'][$table] = $rows;
            }
        }

        foreach (DB::table('phr_ehi_proposals')->where('patient_id', $patient->id)->whereIn('status', ['accepted', 'rejected', 'retained', 'linked'])->orderBy('id')->lazyById(100) as $row) {
            $data = (array) $row;
            unset($data['preview_generation'], $data['updated_at']);
            $state['decided_proposals'][(int) $row->id] = $this->hash($data);
        }

        return $state;
    }

    public function assertUnchanged(array $before, array $after): void
    {
        if ($before !== $after) {
            throw new RuntimeException('Synthetic replay changed clinical contents or decision history.');
        }
    }

    public function assertPriorPreserved(array $before, array $after, bool $changedLabs = false): void
    {
        foreach ($before['clinical'] as $table => $rows) {
            if ($table !== 'phr_lab_results' || ! $changedLabs) {
                if ($rows !== $after['clinical'][$table]) {
                    throw new RuntimeException('Unchanged synthetic clinical contents changed.');
                }
            }
        }
        $this->assertRowsPreserved($before['decisions'], $after['decisions']);
        $this->assertRowsPreserved($before['decided_proposals'], $after['decided_proposals']);
        $this->assertRowsPreserved($before['lab_static'], $after['lab_static']);
    }

    public function assertLabs(PhrPatient $patient, int $records, int $history, int $changed = 0, int $cycle = 0): void
    {
        $sourceId = DB::table('phr_ehi_sources')->where('patient_id', $patient->id)->sole()->id;
        $rejected = DB::table('phr_ehi_proposals')->where('patient_id', $patient->id)->where('mapping', 'lab_result')->where('status', 'rejected')->get();
        if ($rejected->count() !== 1 || (json_decode($rejected->first()->proposed_json, true)['attributes']['external_id'] ?? null) !== 'ORDER_RESULTS:100000:60000:1') {
            throw new RuntimeException('Declared deliberate lab rejection is missing or changed.');
        }
        $seen = [];
        foreach (DB::table('phr_lab_results')->where('patient_id', $patient->id)->orderBy('id')->lazyById(100) as $row) {
            $identity = $row->import_source.'|'.$row->external_id;
            if (isset($seen[$identity]) || $row->deleted_at !== null || $row->review_status !== 'confirmed') {
                throw new RuntimeException('Synthetic lab identity/lifecycle differs from its declaration.');
            }
            $seen[$identity] = true;
            if ($row->import_source === 'synthetic-resource-history' && preg_match('/^synthetic-prior-([0-9]+)$/D', $row->external_id, $match)) {
                $i = (int) $match[1];
                $valid = $i < $history && $row->analyte === 'SYNTHETIC PRIOR '.$i && $row->value === '1' && $row->unit === 'synthetic';
            } elseif ($row->import_source === 'ehi:'.$sourceId && preg_match('/^ORDER_RESULTS:([0-9]+):([0-9]+):([0-9]+)$/D', $row->external_id, $match)) {
                $panel = (int) $match[1] - 100000;
                $line = (int) $match[3];
                $index = $panel * 10 + $line - 1;
                $expected = $index > 0 && $index <= $changed ? (string) (95 + $cycle).'.0' : '95.0';
                $valid = $panel >= 0 && (int) $match[2] === 60000 + $panel && $line >= 1 && $line <= 10
                    && $index > 0 && $index < $records && $row->value === $expected
                    && $row->analyte === 'SYNTHETIC CAPACITY ANALYTE '.$line && $row->unit === 'mg/dL';
            } else {
                $valid = false;
            }
            if (! $valid) {
                throw new RuntimeException('Synthetic lab identity/value differs from its independent declaration.');
            }
        }
        if (count($seen) !== $history + $records - 1) {
            throw new RuntimeException('Declared synthetic lab identities are missing or duplicated.');
        }
    }

    private function assertRowsPreserved(array $before, array $after): void
    {
        foreach ($before as $id => $hash) {
            if (($after[$id] ?? null) !== $hash) {
                throw new RuntimeException('Prior synthetic row contents were overwritten or removed.');
            }
        }
    }

    private function hash(array $row): string
    {
        ksort($row);

        return hash('sha256', json_encode($row, JSON_THROW_ON_ERROR));
    }
}
