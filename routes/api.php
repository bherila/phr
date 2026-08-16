<?php

use App\Http\Controllers\Api\DevicePairingExchangeController;
use App\Http\Controllers\Api\UserAiConfigurationController;
use App\Http\Controllers\Api\UserAiModelsController;
use App\Http\Controllers\Api\UserDeviceController;
use App\Http\Controllers\Api\V1\AgentClinicalReadController;
use App\Http\Controllers\Api\V1\AgentClinicalWriteController;
use App\Http\Controllers\Api\V1\AgentDiscoveryController;
use App\Http\Controllers\Api\V1\AgentDocumentController;
use App\Http\Controllers\Api\V1\AgentEvidenceController;
use App\Http\Controllers\Api\V1\AgentMcpController;
use App\Http\Controllers\Api\V1\AgentPatientController;
use App\Http\Controllers\Api\V1\AgentRecordSearchController;
use App\Http\Controllers\Api\V1\AgentTokenController;
use App\Http\Controllers\PHR\AllergyController as PHRAllergyController;
use App\Http\Controllers\PHR\ClinicalEobController as PHRClinicalEobController;
use App\Http\Controllers\PHR\ConditionController as PHRConditionController;
use App\Http\Controllers\PHR\DICOM\DicomFileController as PHRDicomFileController;
use App\Http\Controllers\PHR\DICOM\DicomStudyController as PHRDicomStudyController;
use App\Http\Controllers\PHR\DICOM\DicomUploadController as PHRDicomUploadController;
use App\Http\Controllers\PHR\DICOM\DicomVolumeCacheController as PHRDicomVolumeCacheController;
use App\Http\Controllers\PHR\HealthLogController as PHRHealthLogController;
use App\Http\Controllers\PHR\HealthLogEntryController as PHRHealthLogEntryController;
use App\Http\Controllers\PHR\ImmunizationController as PHRImmunizationController;
use App\Http\Controllers\PHR\LabResultController as PHRLabResultController;
use App\Http\Controllers\PHR\MedicationController as PHRMedicationController;
use App\Http\Controllers\PHR\OfficeVisitController as PHROfficeVisitController;
use App\Http\Controllers\PHR\PatientAccessController as PHRPatientAccessController;
use App\Http\Controllers\PHR\PatientController as PHRPatientController;
use App\Http\Controllers\PHR\PhrDataHubController;
use App\Http\Controllers\PHR\PhrDocumentController;
use App\Http\Controllers\PHR\PhrExportController;
use App\Http\Controllers\PHR\PhrGenAiImportController;
use App\Http\Controllers\PHR\PhrNativeBackupController;
use App\Http\Controllers\PHR\PhrNativeRestoreController;
use App\Http\Controllers\PHR\PhrPatientDeletionController;
use App\Http\Controllers\PHR\PhrPatientSearchController;
use App\Http\Controllers\PHR\ProcedureController as PHRProcedureController;
use App\Http\Controllers\PHR\RespiratoryEventController as PHRRespiratoryEventController;
use App\Http\Controllers\PHR\SinusEnrollmentController as PHRSinusEnrollmentController;
use App\Http\Controllers\PHR\SinusSettingsController as PHRSinusSettingsController;
use App\Http\Controllers\PHR\VitalController as PHRVitalController;
use App\Http\Middleware\AuditAgentApiRequest;
use App\Http\Middleware\AuthenticateWebOrMcpRequest;
use App\Http\Middleware\EnsureOAuthUserCanLogin;
use App\Http\Middleware\PreventAgentApiResponseCaching;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\AgentClinicalResourceCatalog;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Http\Middleware\CheckToken;

Route::prefix('v1')->name('agent-api.v1.')->group(function (): void {
    Route::get('/capabilities', [AgentDiscoveryController::class, 'capabilities'])
        ->middleware('throttle:60,1')
        ->name('capabilities');
    Route::options('/mcp', AgentMcpController::class)
        ->middleware('throttle:60,1')
        ->name('mcp.options');

    Route::middleware([
        'auth:api',
        AuditAgentApiRequest::class,
        EnsureOAuthUserCanLogin::class,
        PreventAgentApiResponseCaching::class,
    ])->group(function (): void {
        // Self-revocation is deliberately outside the ordinary traffic bucket. A
        // saturated API limit must never prevent a client from disconnecting.
        Route::delete('/oauth/token', [AgentTokenController::class, 'destroy'])
            ->name('oauth-token.destroy');

        Route::get('/me', [AgentDiscoveryController::class, 'me'])
            ->middleware('throttle:agent-api')
            ->middleware(CheckToken::using(AgentApiScopes::IDENTITY_READ))
            ->name('me');

        Route::match(['POST', 'DELETE'], '/mcp', AgentMcpController::class)
            ->middleware('throttle:agent-api')
            ->middleware(CheckToken::using(AgentApiScopes::MCP_USE))
            ->name('mcp');

        Route::get('/patients', [AgentPatientController::class, 'index'])
            ->middleware('throttle:agent-api')
            ->middleware(CheckToken::using(AgentApiScopes::PATIENTS_READ))
            ->name('patients.index');
        Route::get('/patients/{patient}', [AgentPatientController::class, 'show'])
            ->whereNumber('patient')
            ->middleware('throttle:agent-api')
            ->middleware(CheckToken::using(AgentApiScopes::PATIENTS_READ))
            ->name('patients.show');

        Route::get('/patients/{patient}/records/search', [AgentRecordSearchController::class, 'search'])
            ->whereNumber('patient')
            ->middleware('throttle:agent-api')
            ->middleware(CheckToken::using(AgentApiScopes::CLINICAL_READ))
            ->name('records.search');
        Route::get('/patients/{patient}/timeline', [AgentRecordSearchController::class, 'timeline'])
            ->whereNumber('patient')
            ->middleware('throttle:agent-api')
            ->middleware(CheckToken::using(AgentApiScopes::CLINICAL_READ))
            ->name('timeline.index');

        Route::get('/patients/{patient}/eobs', [AgentEvidenceController::class, 'eobs'])
            ->whereNumber('patient')->middleware('throttle:agent-api')
            ->middleware(CheckToken::using(AgentApiScopes::CLINICAL_READ))->name('eobs.index');
        Route::get('/patients/{patient}/eobs/{eob}', [AgentEvidenceController::class, 'eob'])
            ->whereNumber(['patient', 'eob'])->middleware('throttle:agent-api')
            ->middleware(CheckToken::using(AgentApiScopes::CLINICAL_READ))->name('eobs.show');
        Route::get('/patients/{patient}/eobs/{eob}/lines', [AgentEvidenceController::class, 'eobLines'])
            ->whereNumber(['patient', 'eob'])->middleware('throttle:agent-api')
            ->middleware(CheckToken::using(AgentApiScopes::CLINICAL_READ))->name('eob-lines.index');
        Route::get('/patients/{patient}/eobs/{eob}/lines/{line}', [AgentEvidenceController::class, 'eobLine'])
            ->whereNumber(['patient', 'eob', 'line'])->middleware('throttle:agent-api')
            ->middleware(CheckToken::using(AgentApiScopes::CLINICAL_READ))->name('eob-lines.show');
        Route::get('/patients/{patient}/evidence-links', [AgentEvidenceController::class, 'links'])
            ->whereNumber('patient')->middleware('throttle:agent-api')
            ->middleware(CheckToken::using(AgentApiScopes::CLINICAL_READ))->name('evidence.links');

        Route::get('/patients/{patient}/documents', [AgentDocumentController::class, 'index'])
            ->whereNumber('patient')->middleware('throttle:agent-api')
            ->middleware(CheckToken::using(AgentApiScopes::DOCUMENTS_READ))->name('documents.index');
        Route::get('/patients/{patient}/documents/{document}', [AgentDocumentController::class, 'show'])
            ->whereNumber(['patient', 'document'])->middleware('throttle:agent-api')
            ->middleware(CheckToken::using(AgentApiScopes::DOCUMENTS_READ))->name('documents.show');
        Route::post('/patients/{patient}/documents/{document}/download-access', [AgentDocumentController::class, 'createDownloadAccess'])
            ->whereNumber(['patient', 'document'])->middleware('throttle:agent-api')
            ->middleware(CheckToken::using(AgentApiScopes::DOCUMENTS_READ))->name('documents.download-access');
        Route::get('/patients/{patient}/documents/{document}/file', [AgentDocumentController::class, 'file'])
            ->whereNumber(['patient', 'document'])->middleware(['throttle:agent-api', 'signed'])
            ->middleware(CheckToken::using(AgentApiScopes::DOCUMENTS_READ))->name('documents.file');

        Route::get('/patients/{patient}/{resource}', [AgentClinicalReadController::class, 'index'])
            ->whereNumber('patient')
            ->whereIn('resource', AgentClinicalResourceCatalog::ids())
            ->middleware('throttle:agent-api')
            ->middleware(CheckToken::using(AgentApiScopes::CLINICAL_READ))
            ->name('clinical.index');
        foreach (AgentClinicalResourceCatalog::writableIds() as $writableResource) {
            Route::put("/patients/{patient}/{$writableResource}", [AgentClinicalWriteController::class, 'upsert'])
                ->defaults('resource', $writableResource)
                ->whereNumber('patient')
                ->middleware('throttle:agent-api')
                ->middleware(CheckToken::using(AgentApiScopes::CLINICAL_WRITE))
                ->name('clinical.'.str_replace('-', '_', $writableResource).'.upsert');
        }
        Route::get('/patients/{patient}/{resource}/{record}', [AgentClinicalReadController::class, 'show'])
            ->whereNumber(['patient', 'record'])
            ->whereIn('resource', AgentClinicalResourceCatalog::ids())
            ->middleware('throttle:agent-api')
            ->middleware(CheckToken::using(AgentApiScopes::CLINICAL_READ))
            ->name('clinical.show');
    });
});

// Per-user AI provider settings used by ParseImportJob via User::resolvedAiClient().
// The authenticated PHR Config screen consumes these session-protected endpoints.
Route::middleware(['web', 'auth'])->group(function (): void {
    Route::get('/user/ai-prefs', [UserAiConfigurationController::class, 'index']);
    Route::post('/user/ai-prefs', [UserAiConfigurationController::class, 'store']);
    Route::put('/user/ai-prefs/{id}', [UserAiConfigurationController::class, 'update']);
    Route::delete('/user/ai-prefs/{id}', [UserAiConfigurationController::class, 'destroy']);
    Route::post('/user/ai-prefs/{id}/activate', [UserAiConfigurationController::class, 'activate']);
    Route::post('/user/ai-prefs/models', [UserAiModelsController::class, 'fetch']);

    // Device management for devices paired via /device-pairing (DevicePairingController).
    // Session-only: this never accepts a device's own bearer key, only a browser session,
    // so a stolen device key cannot be used to enumerate or revoke other devices.
    Route::get('/user/devices', [UserDeviceController::class, 'index']);
    Route::delete('/user/devices/{id}', [UserDeviceController::class, 'destroy']);
});

// Device-pairing code exchange (Sinus Sentinel companion app). No session: the
// app has no cookie jar, so this sits outside the ['web','auth'] group above —
// the code + PKCE verifier pair is its own credential. Still needs the `web`
// middleware group to be eligible for the CSRF exemption in bootstrap/app.php
// (there is nothing to exempt from a group that never checks it).
// Throttled: the response is identical for every failure mode (see
// DevicePairingExchangeController), so rate limiting is the only thing
// standing between this endpoint and code-guessing — same precedent as
// patients.access.store below.
Route::post('/device-pairing/exchange', [DevicePairingExchangeController::class, 'exchange'])
    ->middleware(['web', 'throttle:10,1'])
    ->name('device-pairing.exchange');

Route::middleware(['web', 'auth'])
    ->prefix('phr')
    ->name('phr.')
    ->group(function (): void {
        Route::get('/data-hub', [PhrDataHubController::class, 'index'])->name('data-hub.index');
        Route::get('/data-hub/deletions', [PhrPatientDeletionController::class, 'index'])->name('data-hub.deletions.index');
        Route::get('/data-hub/patients/{patient}/deletion-preview', [PhrPatientDeletionController::class, 'preview'])->whereNumber('patient')->name('data-hub.deletions.preview');
        Route::get('/data-hub/deletions/{deletion}', [PhrPatientDeletionController::class, 'show'])->whereNumber('deletion')->name('data-hub.deletions.show');
        Route::post('/data-hub/deletions/{deletion}/retry', [PhrPatientDeletionController::class, 'retry'])->whereNumber('deletion')->name('data-hub.deletions.retry');
        Route::get('/data-hub/native-restores', [PhrNativeRestoreController::class, 'index'])->name('data-hub.native-restores.index');
        Route::post('/data-hub/native-restores/uploads', [PhrNativeRestoreController::class, 'startUpload'])->middleware('throttle:10,1')->name('data-hub.native-restores.uploads.start');
        Route::post('/data-hub/native-restores/{restore}/chunks', [PhrNativeRestoreController::class, 'appendChunk'])->whereNumber('restore')->middleware('throttle:120,1')->name('data-hub.native-restores.uploads.chunk');
        Route::post('/data-hub/native-restores/{restore}/preview', [PhrNativeRestoreController::class, 'preview'])->whereNumber('restore')->name('data-hub.native-restores.preview');
        Route::get('/data-hub/native-restores/{restore}', [PhrNativeRestoreController::class, 'show'])->whereNumber('restore')->name('data-hub.native-restores.show');
        Route::post('/data-hub/native-restores/{restore}/apply', [PhrNativeRestoreController::class, 'apply'])->whereNumber('restore')->name('data-hub.native-restores.apply');
        Route::get('/patients', [PHRPatientController::class, 'index'])->name('patients.index');
        Route::post('/patients', [PHRPatientController::class, 'store'])->name('patients.store');
        Route::get('/patients/{patient}', [PHRPatientController::class, 'show'])->whereNumber('patient')->name('patients.show');
        Route::get('/patients/{patient}/search', [PhrPatientSearchController::class, 'index'])->whereNumber('patient')->name('patients.search');
        Route::patch('/patients/{patient}', [PHRPatientController::class, 'update'])->whereNumber('patient')->name('patients.update');
        Route::delete('/patients/{patient}', [PHRPatientController::class, 'destroy'])->whereNumber('patient')->name('patients.destroy');
        Route::get('/patients/{patient}/lab-results', [PHRLabResultController::class, 'index'])->whereNumber('patient')->name('patients.lab-results.index');
        Route::post('/patients/{patient}/lab-results', [PHRLabResultController::class, 'store'])->whereNumber('patient')->name('patients.lab-results.store');
        Route::get('/patients/{patient}/lab-results/{labResult}', [PHRLabResultController::class, 'show'])->whereNumber(['patient', 'labResult'])->name('patients.lab-results.show');
        Route::get('/patients/{patient}/labs/{labResult}', [PHRLabResultController::class, 'showPanel'])->whereNumber(['patient', 'labResult'])->name('patients.labs.show');
        Route::patch('/patients/{patient}/lab-results/{labResult}', [PHRLabResultController::class, 'update'])->whereNumber(['patient', 'labResult'])->name('patients.lab-results.update');
        Route::delete('/patients/{patient}/lab-results/{labResult}', [PHRLabResultController::class, 'destroy'])->whereNumber(['patient', 'labResult'])->name('patients.lab-results.destroy');
        Route::get('/patients/{patient}/vitals', [PHRVitalController::class, 'index'])->whereNumber('patient')->name('patients.vitals.index');
        Route::post('/patients/{patient}/vitals', [PHRVitalController::class, 'store'])->whereNumber('patient')->name('patients.vitals.store');
        Route::get('/patients/{patient}/vitals/trend/{metricKey}', [PHRVitalController::class, 'trend'])->whereNumber('patient')->name('patients.vitals.trend');
        Route::get('/patients/{patient}/vitals/{vital}', [PHRVitalController::class, 'show'])->whereNumber(['patient', 'vital'])->name('patients.vitals.show');
        Route::patch('/patients/{patient}/vitals/{vital}', [PHRVitalController::class, 'update'])->whereNumber(['patient', 'vital'])->name('patients.vitals.update');
        Route::delete('/patients/{patient}/vitals/{vital}', [PHRVitalController::class, 'destroy'])->whereNumber(['patient', 'vital'])->name('patients.vitals.destroy');
        // Throttled: the handler responds identically whether or not the
        // address belongs to an account, but a rate limit keeps bulk probing
        // of the user table impractical regardless.
        Route::post('/patients/{patient}/access', [PHRPatientAccessController::class, 'store'])->whereNumber('patient')->middleware('throttle:10,1')->name('patients.access.store');
        Route::delete('/patients/{patient}/access/{access}', [PHRPatientAccessController::class, 'destroy'])->whereNumber(['patient', 'access'])->name('patients.access.destroy');
        Route::get('/patients/{patient}/office-visits', [PHROfficeVisitController::class, 'index'])->whereNumber('patient')->name('patients.office-visits.index');
        Route::post('/patients/{patient}/office-visits', [PHROfficeVisitController::class, 'store'])->whereNumber('patient')->name('patients.office-visits.store');
        Route::get('/patients/{patient}/office-visits/{visit}', [PHROfficeVisitController::class, 'show'])->whereNumber(['patient', 'visit'])->name('patients.office-visits.show');
        Route::post('/patients/{patient}/office-visits/{visit}/eobs/{eob}', [PHRClinicalEobController::class, 'linkOfficeVisit'])->whereNumber(['patient', 'visit', 'eob'])->name('patients.office-visits.eobs.store');
        Route::delete('/patients/{patient}/office-visits/{visit}/eobs/{eob}', [PHRClinicalEobController::class, 'unlinkOfficeVisit'])->whereNumber(['patient', 'visit', 'eob'])->name('patients.office-visits.eobs.destroy');
        Route::post('/patients/{patient}/office-visits/{visit}/imaging-studies/{study}', [PHROfficeVisitController::class, 'linkImagingStudy'])->whereNumber(['patient', 'visit', 'study'])->name('patients.office-visits.imaging-studies.store');
        Route::delete('/patients/{patient}/office-visits/{visit}/imaging-studies/{study}', [PHROfficeVisitController::class, 'unlinkImagingStudy'])->whereNumber(['patient', 'visit', 'study'])->name('patients.office-visits.imaging-studies.destroy');
        Route::patch('/patients/{patient}/office-visits/{visit}', [PHROfficeVisitController::class, 'update'])->whereNumber(['patient', 'visit'])->name('patients.office-visits.update');
        Route::delete('/patients/{patient}/office-visits/{visit}', [PHROfficeVisitController::class, 'destroy'])->whereNumber(['patient', 'visit'])->name('patients.office-visits.destroy');
        Route::get('/patients/{patient}/medications', [PHRMedicationController::class, 'index'])->whereNumber('patient')->name('patients.medications.index');
        Route::post('/patients/{patient}/medications', [PHRMedicationController::class, 'store'])->whereNumber('patient')->name('patients.medications.store');
        Route::get('/patients/{patient}/medications/{medication}', [PHRMedicationController::class, 'show'])->whereNumber(['patient', 'medication'])->name('patients.medications.show');
        Route::patch('/patients/{patient}/medications/{medication}', [PHRMedicationController::class, 'update'])->whereNumber(['patient', 'medication'])->name('patients.medications.update');
        Route::delete('/patients/{patient}/medications/{medication}', [PHRMedicationController::class, 'destroy'])->whereNumber(['patient', 'medication'])->name('patients.medications.destroy');
        Route::get('/patients/{patient}/conditions', [PHRConditionController::class, 'index'])->whereNumber('patient')->name('patients.conditions.index');
        Route::post('/patients/{patient}/conditions', [PHRConditionController::class, 'store'])->whereNumber('patient')->name('patients.conditions.store');
        Route::get('/patients/{patient}/conditions/{condition}', [PHRConditionController::class, 'show'])->whereNumber(['patient', 'condition'])->name('patients.conditions.show');
        Route::patch('/patients/{patient}/conditions/{condition}', [PHRConditionController::class, 'update'])->whereNumber(['patient', 'condition'])->name('patients.conditions.update');
        Route::delete('/patients/{patient}/conditions/{condition}', [PHRConditionController::class, 'destroy'])->whereNumber(['patient', 'condition'])->name('patients.conditions.destroy');
        Route::get('/patients/{patient}/procedures', [PHRProcedureController::class, 'index'])->whereNumber('patient')->name('patients.procedures.index');
        Route::post('/patients/{patient}/procedures', [PHRProcedureController::class, 'store'])->whereNumber('patient')->name('patients.procedures.store');
        Route::get('/patients/{patient}/procedures/{procedure}', [PHRProcedureController::class, 'show'])->whereNumber(['patient', 'procedure'])->name('patients.procedures.show');
        Route::post('/patients/{patient}/procedures/{procedure}/eobs/{eob}', [PHRClinicalEobController::class, 'linkProcedure'])->whereNumber(['patient', 'procedure', 'eob'])->name('patients.procedures.eobs.store');
        Route::delete('/patients/{patient}/procedures/{procedure}/eobs/{eob}', [PHRClinicalEobController::class, 'unlinkProcedure'])->whereNumber(['patient', 'procedure', 'eob'])->name('patients.procedures.eobs.destroy');
        Route::patch('/patients/{patient}/procedures/{procedure}', [PHRProcedureController::class, 'update'])->whereNumber(['patient', 'procedure'])->name('patients.procedures.update');
        Route::delete('/patients/{patient}/procedures/{procedure}', [PHRProcedureController::class, 'destroy'])->whereNumber(['patient', 'procedure'])->name('patients.procedures.destroy');
        Route::get('/patients/{patient}/immunizations', [PHRImmunizationController::class, 'index'])->whereNumber('patient')->name('patients.immunizations.index');
        Route::post('/patients/{patient}/immunizations', [PHRImmunizationController::class, 'store'])->whereNumber('patient')->name('patients.immunizations.store');
        Route::get('/patients/{patient}/immunizations/{immunization}', [PHRImmunizationController::class, 'show'])->whereNumber(['patient', 'immunization'])->name('patients.immunizations.show');
        Route::patch('/patients/{patient}/immunizations/{immunization}', [PHRImmunizationController::class, 'update'])->whereNumber(['patient', 'immunization'])->name('patients.immunizations.update');
        Route::delete('/patients/{patient}/immunizations/{immunization}', [PHRImmunizationController::class, 'destroy'])->whereNumber(['patient', 'immunization'])->name('patients.immunizations.destroy');
        Route::get('/patients/{patient}/eobs', [PHRClinicalEobController::class, 'index'])->whereNumber('patient')->name('patients.eobs.index');
        Route::get('/patients/{patient}/allergies', [PHRAllergyController::class, 'index'])->whereNumber('patient')->name('patients.allergies.index');
        Route::post('/patients/{patient}/allergies', [PHRAllergyController::class, 'store'])->whereNumber('patient')->name('patients.allergies.store');
        Route::get('/patients/{patient}/allergies/{allergy}', [PHRAllergyController::class, 'show'])->whereNumber(['patient', 'allergy'])->name('patients.allergies.show');
        Route::patch('/patients/{patient}/allergies/{allergy}', [PHRAllergyController::class, 'update'])->whereNumber(['patient', 'allergy'])->name('patients.allergies.update');
        Route::delete('/patients/{patient}/allergies/{allergy}', [PHRAllergyController::class, 'destroy'])->whereNumber(['patient', 'allergy'])->name('patients.allergies.destroy');
        Route::get('/patients/{patient}/health-logs', [PHRHealthLogController::class, 'index'])->whereNumber('patient')->name('patients.health-logs.index');
        Route::post('/patients/{patient}/health-logs', [PHRHealthLogController::class, 'store'])->whereNumber('patient')->name('patients.health-logs.store');
        Route::get('/patients/{patient}/health-logs/{healthLog}', [PHRHealthLogController::class, 'show'])->whereNumber(['patient', 'healthLog'])->name('patients.health-logs.show');
        Route::patch('/patients/{patient}/health-logs/{healthLog}', [PHRHealthLogController::class, 'update'])->whereNumber(['patient', 'healthLog'])->name('patients.health-logs.update');
        Route::delete('/patients/{patient}/health-logs/{healthLog}', [PHRHealthLogController::class, 'destroy'])->whereNumber(['patient', 'healthLog'])->name('patients.health-logs.destroy');
        Route::get('/patients/{patient}/health-logs/{healthLog}/entries', [PHRHealthLogEntryController::class, 'index'])->whereNumber(['patient', 'healthLog'])->name('patients.health-logs.entries.index');
        Route::post('/patients/{patient}/health-logs/{healthLog}/entries', [PHRHealthLogEntryController::class, 'store'])->whereNumber(['patient', 'healthLog'])->name('patients.health-logs.entries.store');
        Route::get('/patients/{patient}/health-logs/{healthLog}/entries/{entry}', [PHRHealthLogEntryController::class, 'show'])->whereNumber(['patient', 'healthLog', 'entry'])->name('patients.health-logs.entries.show');
        Route::patch('/patients/{patient}/health-logs/{healthLog}/entries/{entry}', [PHRHealthLogEntryController::class, 'update'])->whereNumber(['patient', 'healthLog', 'entry'])->name('patients.health-logs.entries.update');
        Route::delete('/patients/{patient}/health-logs/{healthLog}/entries/{entry}', [PHRHealthLogEntryController::class, 'destroy'])->whereNumber(['patient', 'healthLog', 'entry'])->name('patients.health-logs.entries.destroy');
        Route::get('/patients/{patient}/dicom/studies', [PHRDicomStudyController::class, 'index'])->whereNumber('patient')->name('patients.dicom.studies.index');
        Route::get('/patients/{patient}/dicom/studies/{study}', [PHRDicomStudyController::class, 'show'])->whereNumber(['patient', 'study'])->name('patients.dicom.studies.show');
        Route::post('/patients/{patient}/dicom/uploads', [PHRDicomUploadController::class, 'open'])->whereNumber('patient')->name('patients.dicom.uploads.open');
        Route::post('/patients/{patient}/dicom/uploads/{upload}/files', [PHRDicomUploadController::class, 'storeFile'])->whereNumber(['patient', 'upload'])->name('patients.dicom.uploads.files.store');
        Route::post('/patients/{patient}/dicom/uploads/{upload}/finalize', [PHRDicomUploadController::class, 'finalize'])->whereNumber(['patient', 'upload'])->name('patients.dicom.uploads.finalize');
        Route::post('/patients/{patient}/dicom/uploads/{upload}/cancel', [PHRDicomUploadController::class, 'cancel'])->whereNumber(['patient', 'upload'])->name('patients.dicom.uploads.cancel');
        Route::get('/patients/{patient}/dicom/studies/{study}/viewer-json', [PHRDicomStudyController::class, 'viewerJson'])->whereNumber(['patient', 'study'])->name('patients.dicom.studies.viewer-json');
        Route::get('/patients/{patient}/dicom/series/{series}/volume-manifest', [PHRDicomStudyController::class, 'volumeManifest'])->whereNumber(['patient', 'series'])->name('patients.dicom.series.volume-manifest');
        Route::post('/patients/{patient}/dicom/series/{series}/volume-cache', [PHRDicomVolumeCacheController::class, 'store'])->whereNumber(['patient', 'series'])->name('patients.dicom.series.volume-cache.store');
        Route::get('/patients/{patient}/dicom/series/{series}/volume-cache', [PHRDicomVolumeCacheController::class, 'show'])->whereNumber(['patient', 'series'])->name('patients.dicom.series.volume-cache.show');
        Route::get('/patients/{patient}/dicom/studies/{study}/download', [PHRDicomFileController::class, 'downloadStudy'])->whereNumber(['patient', 'study'])->name('patients.dicom.studies.download');
        Route::get('/patients/{patient}/dicom/instances/{instance}/file', [PHRDicomFileController::class, 'proxyInstanceFile'])->whereNumber(['patient', 'instance'])->name('patients.dicom.instances.file');
        Route::get('/patients/{patient}/documents', [PhrDocumentController::class, 'index'])->whereNumber('patient')->name('patients.documents.index');
        Route::post('/patients/{patient}/documents', [PhrDocumentController::class, 'store'])->whereNumber('patient')->name('patients.documents.store');
        Route::get('/patients/{patient}/documents/{document}', [PhrDocumentController::class, 'show'])->whereNumber(['patient', 'document'])->name('patients.documents.show');
        Route::get('/patients/{patient}/documents/{document}/file', [PhrDocumentController::class, 'file'])->whereNumber(['patient', 'document'])->name('patients.documents.file');
        Route::patch('/patients/{patient}/documents/{document}', [PhrDocumentController::class, 'update'])->whereNumber(['patient', 'document'])->name('patients.documents.update');
        Route::delete('/patients/{patient}/documents/{document}', [PhrDocumentController::class, 'destroy'])->whereNumber(['patient', 'document'])->name('patients.documents.destroy');
        Route::post('/patients/{patient}/documents/{document}/process', [PhrDocumentController::class, 'process'])->whereNumber(['patient', 'document'])->name('patients.documents.process');
        Route::get('/patients/{patient}/exports', [PhrExportController::class, 'index'])->whereNumber('patient')->name('patients.exports.index');
        Route::post('/patients/{patient}/exports', [PhrExportController::class, 'store'])->whereNumber('patient')->name('patients.exports.store');
        Route::get('/patients/{patient}/native-backups', [PhrNativeBackupController::class, 'index'])->whereNumber('patient')->name('patients.native-backups.index');
        Route::post('/patients/{patient}/native-backups', [PhrNativeBackupController::class, 'store'])->whereNumber('patient')->name('patients.native-backups.store');
        Route::get('/genai/writable-patients', [PhrGenAiImportController::class, 'writablePatients'])->name('genai.writable-patients');
        Route::post('/genai/jobs/{job}/results/{result}/accept', [PhrGenAiImportController::class, 'accept'])->whereNumber(['job', 'result'])->name('genai.results.accept');
    });

// PHR respiratory-events (Sinus Sentinel device ingest).
// Separate group from the session-only PHR block above: AuthenticateWebOrMcpRequest
// accepts either a browser session or an `Authorization: Bearer <mcp_api_key>` token,
// so the desktop app can post without a session. The `web` group is kept for session
// support; the batch/delete-batch write paths are exempted from CSRF in bootstrap/app.php
// (a bearer request carries no session token).
Route::middleware(['web', AuthenticateWebOrMcpRequest::class])
    ->prefix('phr')
    ->name('phr.respiratory-events.')
    ->group(function (): void {
        Route::post('/patients/{patient}/respiratory-events/batch', [PHRRespiratoryEventController::class, 'batch'])->whereNumber('patient')->name('batch.store');
        Route::delete('/patients/{patient}/respiratory-events/batch', [PHRRespiratoryEventController::class, 'deleteBatch'])->whereNumber('patient')->name('batch.destroy');
        Route::get('/patients/{patient}/respiratory-events/summary', [PHRRespiratoryEventController::class, 'summary'])->whereNumber('patient')->name('summary');
        Route::get('/patients/{patient}/respiratory-events', [PHRRespiratoryEventController::class, 'index'])->whereNumber('patient')->name('index');
        Route::post('/patients/{patient}/respiratory-events/flag-batch', [PHRRespiratoryEventController::class, 'flagBatch'])->whereNumber('patient')->name('flag-batch.store');
    });

// PHR Sinus Sentinel settings + Teach-mode enrollments (same device auth as the
// respiratory-events group above). A sibling group rather than an extension of
// it: a group `name()` prefix is a literal prepend, so folding these in would
// rename the four shipped respiratory-event routes.
Route::middleware(['web', AuthenticateWebOrMcpRequest::class])
    ->prefix('phr')
    ->name('phr.sinus.')
    ->group(function (): void {
        Route::get('/patients/{patient}/sinus-settings', [PHRSinusSettingsController::class, 'show'])->whereNumber('patient')->name('settings.show');
        Route::put('/patients/{patient}/sinus-settings', [PHRSinusSettingsController::class, 'update'])->whereNumber('patient')->name('settings.update');
        Route::get('/patients/{patient}/sinus-enrollments', [PHRSinusEnrollmentController::class, 'index'])->whereNumber('patient')->name('enrollments.index');
        Route::post('/patients/{patient}/sinus-enrollments/batch', [PHRSinusEnrollmentController::class, 'batch'])->whereNumber('patient')->name('enrollments.batch.store');
        Route::delete('/patients/{patient}/sinus-enrollments/batch', [PHRSinusEnrollmentController::class, 'deleteBatch'])->whereNumber('patient')->name('enrollments.batch.destroy');
    });
