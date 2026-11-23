<?php

namespace App\Services\PHR\Export;

use App\Models\PhrAllergy;
use App\Models\PhrCondition;
use App\Models\PhrDicomFile;
use App\Models\PhrDicomStudy;
use App\Models\PhrDocument;
use App\Models\PhrEpisode;
use App\Models\PhrImmunization;
use App\Models\PhrLabResult;
use App\Models\PhrMedication;
use App\Models\PhrMedicationFill;
use App\Models\PhrNegativeAssertion;
use App\Models\PhrOfficeVisit;
use App\Models\PhrPatient;
use App\Models\PhrPatientVital;
use App\Models\PhrPortalMessage;
use App\Models\PhrProcedure;
use App\Services\PHR\Encounters\PhrEncounterLinks;
use App\Services\PHR\Encounters\PhrEpisodeLinks;
use App\Support\PHR\PhrReviewStatus;
use Illuminate\Database\Eloquent\Collection;

class PhrExportDataService
{
    /**
     * Clinical resources are restricted to confirmed records that still stand.
     * Agent-written data lands as `pending_review` and must be accepted by a
     * human in the browser before it can leave the system in a FHIR, C-CDA, or
     * PDF export, and a record whose source later withdrew it stops qualifying
     * even though a human once confirmed it -- a confirmation is not a licence
     * to keep exporting a retracted claim. Deleted records are excluded by the
     * soft-delete scope. Documents follow the same rule (phr#408): one an agent,
     * an AI read or a standards import wrote leaves only once a person confirms
     * it. Resources without a review lifecycle, and the native backup path, are
     * unaffected; a backup must stay complete.
     *
     * A record exported here never points at a document that is not: a
     * `source_document_id` naming a pending, rejected, retracted or deleted
     * document is dropped from the loaded record (never saved), so no exporter
     * can emit a reference to a document the export leaves out.
     *
     * Nothing PHR wrote is exported as clinician-authenticated: no exporter
     * writes a C-CDA legalAuthenticator or authenticator. A note's cosigner, as
     * its source recorded it, is exported (a FHIR DocumentReference
     * authenticator, a C-CDA Note Activity legal-authenticator participant)
     * only for a document a source wrote; a document's text an agent wrote or an
     * AI read (source agent_upload or genai_import) is the agent's, whatever a
     * person approved, and carries none (phr#409). A document's link to the one
     * it amends is exported only when both are.
     *
     * @return array{
     *     patient: PhrPatient,
     *     lab_results: Collection<int, PhrLabResult>,
     *     vitals: Collection<int, PhrPatientVital>,
     *     conditions: Collection<int, PhrCondition>,
     *     medications: Collection<int, PhrMedication>,
     *     medication_fills: Collection<int, PhrMedicationFill>,
     *     procedures: Collection<int, PhrProcedure>,
     *     immunizations: Collection<int, PhrImmunization>,
     *     allergies: Collection<int, PhrAllergy>,
     *     office_visits: Collection<int, PhrOfficeVisit>,
     *     encounter_links: array<string, array<int, int>>,
     *     episodes: Collection<int, PhrEpisode>,
     *     episode_encounters: array<int, list<int>>,
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
        $documents = PhrDocument::query()->where('patient_id', $patientId)->where('review_status', PhrReviewStatus::CONFIRMED)->whereNull('retracted_at')->with('relatesTo')->orderByDesc('created_at')->orderByDesc('id')->get();
        $visits = PhrOfficeVisit::query()->where('patient_id', $patientId)->where('review_status', PhrReviewStatus::CONFIRMED)->whereNull('retracted_at')->with('participants')->orderByDesc('visit_started_at')->orderByDesc('visit_date')->orderByDesc('id')->get();
        $episodes = PhrEpisode::query()->where('patient_id', $patientId)->where('review_status', PhrReviewStatus::CONFIRMED)->whereNull('retracted_at')->orderByDesc('started_at')->orderByDesc('id')->get();

        return self::withoutDanglingDocuments([
            'patient' => $patient,
            'lab_results' => PhrLabResult::query()->where('patient_id', $patientId)->where('review_status', PhrReviewStatus::CONFIRMED)->whereNull('retracted_at')->orderByDesc('result_datetime')->orderByDesc('id')->get(),
            'vitals' => PhrPatientVital::query()->where('patient_id', $patientId)->where('review_status', PhrReviewStatus::CONFIRMED)->whereNull('retracted_at')->orderByDesc('observed_at')->orderByDesc('vital_date')->orderByDesc('id')->get(),
            'conditions' => PhrCondition::query()->where('patient_id', $patientId)->where('review_status', PhrReviewStatus::CONFIRMED)->whereNull('retracted_at')->orderBy('name')->get(),
            'medications' => PhrMedication::query()->where('patient_id', $patientId)->where('review_status', PhrReviewStatus::CONFIRMED)->whereNull('retracted_at')->orderBy('name')->get(),
            'medication_fills' => PhrMedicationFill::query()->where('patient_id', $patientId)->where('review_status', PhrReviewStatus::CONFIRMED)->whereNull('retracted_at')->orderByDesc('filled_on')->orderByDesc('id')->get(),
            'procedures' => PhrProcedure::query()->where('patient_id', $patientId)->where('review_status', PhrReviewStatus::CONFIRMED)->whereNull('retracted_at')->orderByDesc('performed_at')->orderByDesc('performed_on')->orderByDesc('id')->get(),
            'immunizations' => PhrImmunization::query()->where('patient_id', $patientId)->where('review_status', PhrReviewStatus::CONFIRMED)->whereNull('retracted_at')->orderByDesc('administered_on')->orderByDesc('id')->get(),
            'allergies' => PhrAllergy::query()->where('patient_id', $patientId)->where('review_status', PhrReviewStatus::CONFIRMED)->whereNull('retracted_at')->orderBy('substance')->get(),
            'office_visits' => $visits,
            // Only links to a visit this export carries: a reference to one it leaves out
            // (pending, rejected, retracted) would dangle, so it is dropped (#411).
            'encounter_links' => app(PhrEncounterLinks::class)->linksToVisits($patientId, $visits->modelKeys()),
            // Episodes of care (#415), and for each exported visit the exported episodes it is
            // grouped under. A link to an episode or a visit the export leaves out is dropped.
            'episodes' => $episodes,
            'episode_encounters' => app(PhrEpisodeLinks::class)->linksBetween($patientId, $episodes->modelKeys(), $visits->modelKeys()),
            'portal_messages' => PhrPortalMessage::query()->where('patient_id', $patientId)->orderByDesc('message_at')->orderByDesc('id')->get(),
            'negative_assertions' => PhrNegativeAssertion::query()->where('patient_id', $patientId)->orderBy('assertion_type')->orderBy('scope')->orderByDesc('observed_on')->orderByDesc('id')->get(),
            'dicom_studies' => PhrDicomStudy::query()->where('patient_id', $patientId)->withCount(['series', 'instances'])->orderByDesc('study_date')->orderByDesc('id')->get(),
            'dicom_files' => PhrDicomFile::query()->where('patient_id', $patientId)->with(['instance.study'])->orderBy('original_relative_path')->get(),
            'documents' => $documents,
        ]);
    }

    /**
     * @template TData of array<string, mixed>
     *
     * @param  TData  $data
     * @return TData
     */
    private static function withoutDanglingDocuments(array $data): array
    {
        $exported = [];
        foreach ($data['documents'] as $document) {
            $exported[(int) $document->id] = true;
        }
        foreach ($data as $key => $records) {
            if ($key === 'documents' || ! $records instanceof Collection) {
                continue;
            }
            foreach ($records as $record) {
                $documentId = $record->getAttributes()['source_document_id'] ?? null;
                if ($documentId !== null && ! isset($exported[(int) $documentId])) {
                    // Unsaved: the record keeps its link; only this export omits it.
                    $record->setAttribute('source_document_id', null);
                }
            }
        }

        return $data;
    }
}
