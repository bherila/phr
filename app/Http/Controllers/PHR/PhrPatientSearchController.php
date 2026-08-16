<?php

namespace App\Http\Controllers\PHR;

use App\Http\Controllers\Controller;
use App\Models\PhrCondition;
use App\Models\PhrDicomStudy;
use App\Models\PhrDocument;
use App\Models\PhrImmunization;
use App\Models\PhrLabResult;
use App\Models\PhrMedication;
use App\Models\PhrOfficeVisit;
use App\Models\PhrPatientVital;
use App\Models\PhrProcedure;
use App\Services\PHR\Access\PhrPatientAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/**
 * Patient-scoped, structured-record search for the interactive PHR shell.
 *
 * @phpstan-type SearchResult array{id: int, category: string, label: string, description: string|null, date: string|null, module_id: string, sort: string}
 */
class PhrPatientSearchController extends Controller
{
    public function __construct(private PhrPatientAccessService $accessService) {}

    public function index(Request $request, int $patient): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:200'],
            'limit' => ['sometimes', 'integer', 'between:1,50'],
        ]);
        $resolvedPatient = $this->accessService->accessiblePatient($patient, (int) $request->user()?->id);
        $limit = (int) ($validated['limit'] ?? 20);
        $query = (string) $validated['q'];

        $results = collect()
            ->concat($this->officeVisits((int) $resolvedPatient->id, $query, $limit))
            ->concat($this->imagingStudies((int) $resolvedPatient->id, $query, $limit))
            ->concat($this->documents((int) $resolvedPatient->id, $query, $limit))
            ->concat($this->procedures((int) $resolvedPatient->id, $query, $limit))
            ->concat($this->immunizations((int) $resolvedPatient->id, $query, $limit))
            ->concat($this->medications((int) $resolvedPatient->id, $query, $limit))
            ->concat($this->conditions((int) $resolvedPatient->id, $query, $limit))
            ->concat($this->labs((int) $resolvedPatient->id, $query, $limit))
            ->concat($this->vitals((int) $resolvedPatient->id, $query, $limit))
            ->sortByDesc('sort')
            ->take($limit)
            ->map(fn (array $result): array => Arr::except($result, 'sort'))
            ->values();

        return response()->json(['results' => $results]);
    }

    /** @return Collection<int, covariant SearchResult> */
    private function officeVisits(int $patientId, string $query, int $limit): Collection
    {
        return $this->matches(PhrOfficeVisit::query()->where('patient_id', $patientId), $query, [
            'visit_type', 'provider_name', 'facility_name', 'chief_complaint', 'assessment', 'plan', 'subjective', 'objective', 'raw_text',
        ])->latest('visit_date')->limit($limit)->get()->toBase()->map(fn (PhrOfficeVisit $visit): array => $this->result(
            'Visit', $visit->id, $visit->chief_complaint ?: ($visit->visit_type ?: 'Office visit'),
            $this->join([$visit->provider_name, $visit->facility_name]), $visit->visit_date?->toDateString(), 'office-visit-detail',
        ));
    }

    /** @return Collection<int, covariant SearchResult> */
    private function imagingStudies(int $patientId, string $query, int $limit): Collection
    {
        return $this->matches(PhrDicomStudy::query()->where('patient_id', $patientId), $query, [
            'description', 'modalities', 'accession_number', 'study_instance_uid',
        ])->latest('study_date')->limit($limit)->get()->toBase()->map(fn (PhrDicomStudy $study): array => $this->result(
            'Imaging', $study->id, $study->description ?: 'Imaging study',
            $this->join([$study->modalities, $study->accession_number ? "Accession {$study->accession_number}" : null]),
            $study->study_date?->toDateString(), 'imaging-study-detail',
        ));
    }

    /** @return Collection<int, covariant SearchResult> */
    private function documents(int $patientId, string $query, int $limit): Collection
    {
        return $this->matches(PhrDocument::query()->where('patient_id', $patientId), $query, [
            'title', 'original_filename', 'summary', 'extracted_text', 'document_type',
        ])->latest('observed_at')->limit($limit)->get()->toBase()->map(fn (PhrDocument $document): array => $this->result(
            'Document', $document->id, $document->title ?: ($document->original_filename ?: 'Document'),
            $this->join([$document->document_type, $document->summary]), $document->observed_at?->toDateString(), 'document-viewer',
        ));
    }

    /** @return Collection<int, covariant SearchResult> */
    private function procedures(int $patientId, string $query, int $limit): Collection
    {
        return $this->matches(PhrProcedure::query()->where('patient_id', $patientId), $query, [
            'name', 'performer_name', 'facility_name', 'reason', 'outcome', 'notes', 'raw_text', 'cpt_code',
        ])->latest('performed_on')->limit($limit)->get()->toBase()->map(fn (PhrProcedure $procedure): array => $this->result(
            'Procedure', $procedure->id, $procedure->name,
            $this->join([$procedure->performer_name, $procedure->facility_name]), $procedure->performed_on?->toDateString(), 'procedure-detail',
        ));
    }

    /** @return Collection<int, covariant SearchResult> */
    private function immunizations(int $patientId, string $query, int $limit): Collection
    {
        return $this->matches(PhrImmunization::query()->where('patient_id', $patientId), $query, [
            'vaccine_name', 'manufacturer', 'lot_number', 'administered_by', 'facility_name', 'notes', 'raw_text',
        ])->latest('administered_on')->limit($limit)->get()->toBase()->map(fn (PhrImmunization $immunization): array => $this->result(
            'Immunization', $immunization->id, $immunization->vaccine_name,
            $this->join([$immunization->manufacturer, $immunization->facility_name]), $immunization->administered_on?->toDateString(), 'immunization-detail',
        ));
    }

    /** @return Collection<int, covariant SearchResult> */
    private function medications(int $patientId, string $query, int $limit): Collection
    {
        return $this->matches(PhrMedication::query()->where('patient_id', $patientId), $query, [
            'name', 'dose', 'frequency', 'prescriber_name', 'reason_for_use', 'raw_text',
        ])->latest('started_on')->limit($limit)->get()->toBase()->map(fn (PhrMedication $medication): array => $this->result(
            'Medication', $medication->id, $medication->name,
            $this->join([$medication->dose, $medication->frequency, $medication->prescriber_name]), $medication->started_on?->toDateString(), 'medication-detail',
        ));
    }

    /** @return Collection<int, covariant SearchResult> */
    private function conditions(int $patientId, string $query, int $limit): Collection
    {
        return $this->matches(PhrCondition::query()->where('patient_id', $patientId), $query, [
            'name', 'notes', 'raw_text', 'icd10_code', 'snomed_code',
        ])->latest('onset_date')->limit($limit)->get()->toBase()->map(fn (PhrCondition $condition): array => $this->result(
            'Condition', $condition->id, $condition->name, $condition->icd10_code, $condition->onset_date?->toDateString(), 'condition-detail',
        ));
    }

    /** @return Collection<int, covariant SearchResult> */
    private function labs(int $patientId, string $query, int $limit): Collection
    {
        return $this->matches(PhrLabResult::query()->where('patient_id', $patientId), $query, [
            'analyte', 'test_name', 'ordering_provider', 'resulting_lab', 'result_comment', 'message_from_provider', 'notes',
        ])->latest('result_datetime')->limit($limit)->get()->toBase()->map(fn (PhrLabResult $lab): array => $this->result(
            'Lab', $lab->id, $lab->analyte ?: ($lab->test_name ?: 'Lab result'),
            $this->join([$lab->value, $lab->unit, $lab->resulting_lab]), $lab->result_datetime?->toDateString(), 'lab-panel-detail',
        ));
    }

    /** @return Collection<int, covariant SearchResult> */
    private function vitals(int $patientId, string $query, int $limit): Collection
    {
        return $this->matches(PhrPatientVital::query()->where('patient_id', $patientId), $query, [
            'vital_name', 'vital_value', 'unit', 'body_site', 'notes',
        ])->latest('observed_at')->limit($limit)->get()->toBase()->map(fn (PhrPatientVital $vital): array => $this->result(
            'Vital', $vital->id, $vital->vital_name ?: 'Vital',
            $this->join([$vital->vital_value, $vital->unit, $vital->body_site]), $vital->observed_at?->toDateString() ?? $vital->vital_date?->toDateString(), 'vitals-reading-detail',
        ));
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $records
     * @param  list<string>  $columns
     * @return Builder<TModel>
     */
    private function matches(Builder $records, string $query, array $columns): Builder
    {
        $literal = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($query));
        $needle = "%{$literal}%";

        return $records->where(function (Builder $matches) use ($columns, $needle): void {
            foreach ($columns as $index => $column) {
                $method = $index === 0 ? 'whereRaw' : 'orWhereRaw';
                $matches->{$method}("LOWER(COALESCE({$matches->getModel()->qualifyColumn($column)}, '')) LIKE ? ESCAPE '!'", [$needle]);
            }
        });
    }

    /** @return SearchResult */
    private function result(string $category, int $id, string $label, ?string $description, ?string $date, string $module): array
    {
        return ['id' => $id, 'category' => $category, 'label' => $label, 'description' => $description, 'date' => $date, 'module_id' => $module, 'sort' => $date ?? ''];
    }

    /** @param list<string|null> $parts */
    private function join(array $parts): ?string
    {
        $value = implode(' · ', array_filter($parts, fn (mixed $part): bool => is_string($part) && $part !== ''));

        return $value === '' ? null : $value;
    }
}
