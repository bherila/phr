<?php

namespace App\Services\PHR\Export;

use App\Models\PhrAllergy;
use App\Models\PhrCondition;
use App\Models\PhrDicomStudy;
use App\Models\PhrDocument;
use App\Models\PhrImmunization;
use App\Models\PhrLabResult;
use App\Models\PhrMedication;
use App\Models\PhrNegativeAssertion;
use App\Models\PhrOfficeVisit;
use App\Models\PhrPatient;
use App\Models\PhrPatientVital;
use App\Models\PhrPortalMessage;
use App\Models\PhrProcedure;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;

class PhrPdfSummaryRenderer
{
    private const int ROW_LIMIT = 60;

    private const string OVERFLOW_NOTE = 'Additional records are included in FHIR/CCDA exports.';

    /**
     * @param  array{
     *     patient: PhrPatient,
     *     lab_results: Collection<int, PhrLabResult>,
     *     vitals: Collection<int, PhrPatientVital>,
     *     conditions: Collection<int, PhrCondition>,
     *     medications: Collection<int, PhrMedication>,
     *     procedures: Collection<int, PhrProcedure>,
     *     immunizations: Collection<int, PhrImmunization>,
     *     allergies: Collection<int, PhrAllergy>,
     *     office_visits: Collection<int, PhrOfficeVisit>,
     *     portal_messages: Collection<int, PhrPortalMessage>,
     *     negative_assertions: Collection<int, PhrNegativeAssertion>,
     *     dicom_studies: Collection<int, PhrDicomStudy>,
     *     documents: Collection<int, PhrDocument>
     * }  $data
     */
    public function render(array $data): string
    {
        $patient = $data['patient'];
        $appName = (string) config('app.name', 'PHR');

        return Pdf::loadView('phr.pdf.summary', [
            'title' => 'Personal Health Record Summary',
            'generated_at' => now()->toDateTimeString(),
            'overflow_note' => self::OVERFLOW_NOTE,
            'sections' => [
                $this->section('Patient', [
                    ['Name', $patient->display_name ?? 'Patient '.$patient->id],
                    ['Relationship', $patient->relationship],
                    ['Birth Date', $patient->birth_date?->toDateString()],
                    ['Sex at Birth', $patient->sex_at_birth],
                ]),
                $this->section('Labs', $data['lab_results']->map(fn (PhrLabResult $lab): array => [
                    $lab->result_datetime?->toDateString() ?? $lab->collection_datetime?->toDateString(),
                    $lab->analyte ?? $lab->test_name,
                    trim((string) ($lab->value ?? $lab->value_numeric).' '.(string) $lab->unit),
                    $lab->abnormal_flag,
                ])->all()),
                $this->section('Vitals', $data['vitals']->map(fn (PhrPatientVital $vital): array => [
                    $vital->observed_at?->toDateString() ?? $vital->vital_date?->toDateString(),
                    $vital->vital_name,
                    trim((string) ($vital->vital_value ?? $vital->value_numeric).' '.(string) $vital->unit),
                ])->all()),
                $this->section('Conditions', $data['conditions']->map(fn (PhrCondition $condition): array => [
                    $condition->name,
                    $condition->icd10_code,
                    $condition->clinical_status,
                ])->all()),
                $this->section('Medications', $data['medications']->map(fn (PhrMedication $medication): array => [
                    $medication->name,
                    trim(implode(' ', array_filter([$medication->dose, $medication->dose_unit, $medication->frequency]))),
                    $medication->status,
                ])->all()),
                $this->section('Procedures', $data['procedures']->map(fn (PhrProcedure $procedure): array => [
                    $procedure->performed_at?->toDateString() ?? $procedure->performed_on?->toDateString(),
                    $procedure->name,
                    $procedure->status,
                ])->all()),
                $this->section('Immunizations', $data['immunizations']->map(fn (PhrImmunization $immunization): array => [
                    $immunization->administered_on?->toDateString(),
                    $immunization->vaccine_name,
                    $immunization->lot_number,
                ])->all()),
                $this->section('Allergies', $data['allergies']->map(fn (PhrAllergy $allergy): array => [
                    $allergy->substance,
                    $allergy->reaction,
                    $allergy->severity,
                ])->all()),
                $this->section('Office Visits', $data['office_visits']->map(fn (PhrOfficeVisit $visit): array => [
                    $visit->visit_started_at?->toDateString() ?? $visit->visit_date?->toDateString(),
                    $visit->provider_name,
                    $visit->chief_complaint,
                    $visit->assessment,
                ])->all()),
                $this->section('Portal Messages', $data['portal_messages']->map(fn (PhrPortalMessage $message): array => [
                    $message->message_at?->toDateString(),
                    $message->direction,
                    $message->subject,
                    $this->messageParticipants($message),
                    $message->summary,
                    $message->clinical_relevance,
                ])->all()),
                $this->section('Negative Assertions', $data['negative_assertions']->map(fn (PhrNegativeAssertion $assertion): array => [
                    $assertion->observed_on?->toDateString(),
                    $assertion->assertion_type,
                    $assertion->scope,
                    $assertion->statement,
                    $assertion->notes,
                ])->all()),
                $this->section('Imaging', $data['dicom_studies']->map(fn (PhrDicomStudy $study): array => [
                    $study->study_date?->toDateString(),
                    $study->description,
                    $study->modalities,
                ])->all()),
                $this->section('Documents', $data['documents']->map(fn (PhrDocument $document): array => [
                    $document->title ?? $document->original_filename,
                    $document->document_type,
                    $document->summary,
                ])->all()),
            ],
        ])
            ->setPaper('letter')
            ->addInfo([
                'Creator' => $appName,
                'Author' => $appName,
                'Title' => 'PHR Summary',
            ])
            ->output();
    }

    private function messageParticipants(PhrPortalMessage $message): ?string
    {
        if (! $message->sender_name && ! $message->recipient_name) {
            return null;
        }

        return trim(($message->sender_name ?? '').' -> '.($message->recipient_name ?? ''), ' ->');
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     * @return array{title: string, rows: array<int, string>, is_empty: bool, has_more: bool}
     */
    private function section(string $title, array $rows): array
    {
        $lines = [];

        foreach (array_slice($rows, 0, self::ROW_LIMIT) as $row) {
            $line = $this->rowLine($row);
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return [
            'title' => $title,
            'rows' => $lines,
            'is_empty' => $rows === [],
            'has_more' => count($rows) > self::ROW_LIMIT,
        ];
    }

    /**
     * @param  array<int, mixed>  $row
     */
    private function rowLine(array $row): string
    {
        return implode(' | ', array_filter(array_map(
            static fn (mixed $value): string => is_scalar($value) ? trim((string) $value) : '',
            $row
        )));
    }
}
