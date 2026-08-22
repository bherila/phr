<?php

namespace App\Services\PHR\Export;

use App\Models\PhrAllergy;
use App\Models\PhrCondition;
use App\Models\PhrDicomFile;
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
use App\Support\PHR\PhrReviewStatus;
use Illuminate\Database\Eloquent\Collection;

class PhrExportDataService
{
    /**
     * Clinical resources are restricted to confirmed records. Agent-written data
     * lands as `pending_review` and must be accepted by a human in the browser
     * before it can leave the system in a FHIR, C-CDA, or PDF export. Resources
     * without a review lifecycle, and the native backup path, are unaffected.
     *
     * @return array{
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
     *     dicom_files: Collection<int, PhrDicomFile>,
     *     documents: Collection<int, PhrDocument>
     * }
     */
    public function load(PhrPatient $patient): array
    {
        $patientId = (int) $patient->id;

        return [
            'patient' => $patient,
            'lab_results' => PhrLabResult::query()->where('patient_id', $patientId)->where('review_status', PhrReviewStatus::CONFIRMED)->orderByDesc('result_datetime')->orderByDesc('id')->get(),
            'vitals' => PhrPatientVital::query()->where('patient_id', $patientId)->where('review_status', PhrReviewStatus::CONFIRMED)->orderByDesc('observed_at')->orderByDesc('vital_date')->orderByDesc('id')->get(),
            'conditions' => PhrCondition::query()->where('patient_id', $patientId)->where('review_status', PhrReviewStatus::CONFIRMED)->orderBy('name')->get(),
            'medications' => PhrMedication::query()->where('patient_id', $patientId)->where('review_status', PhrReviewStatus::CONFIRMED)->orderBy('name')->get(),
            'procedures' => PhrProcedure::query()->where('patient_id', $patientId)->where('review_status', PhrReviewStatus::CONFIRMED)->orderByDesc('performed_at')->orderByDesc('performed_on')->orderByDesc('id')->get(),
            'immunizations' => PhrImmunization::query()->where('patient_id', $patientId)->where('review_status', PhrReviewStatus::CONFIRMED)->orderByDesc('administered_on')->orderByDesc('id')->get(),
            'allergies' => PhrAllergy::query()->where('patient_id', $patientId)->where('review_status', PhrReviewStatus::CONFIRMED)->orderBy('substance')->get(),
            'office_visits' => PhrOfficeVisit::query()->where('patient_id', $patientId)->where('review_status', PhrReviewStatus::CONFIRMED)->orderByDesc('visit_started_at')->orderByDesc('visit_date')->orderByDesc('id')->get(),
            'portal_messages' => PhrPortalMessage::query()->where('patient_id', $patientId)->orderByDesc('message_at')->orderByDesc('id')->get(),
            'negative_assertions' => PhrNegativeAssertion::query()->where('patient_id', $patientId)->orderBy('assertion_type')->orderBy('scope')->orderByDesc('observed_on')->orderByDesc('id')->get(),
            'dicom_studies' => PhrDicomStudy::query()->where('patient_id', $patientId)->withCount(['series', 'instances'])->orderByDesc('study_date')->orderByDesc('id')->get(),
            'dicom_files' => PhrDicomFile::query()->where('patient_id', $patientId)->with(['instance.study'])->orderBy('original_relative_path')->get(),
            'documents' => PhrDocument::query()->where('patient_id', $patientId)->orderByDesc('created_at')->orderByDesc('id')->get(),
        ];
    }
}
