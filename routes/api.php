<?php

use App\Http\Controllers\Api\UserAiConfigurationController;
use App\Http\Controllers\Api\UserAiModelsController;
use App\Http\Controllers\PHR\AllergyController as PHRAllergyController;
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
use App\Http\Controllers\PHR\PhrDocumentController;
use App\Http\Controllers\PHR\PhrExportController;
use App\Http\Controllers\PHR\PhrGenAiImportController;
use App\Http\Controllers\PHR\ProcedureController as PHRProcedureController;
use App\Http\Controllers\PHR\RespiratoryEventController as PHRRespiratoryEventController;
use App\Http\Controllers\PHR\SinusEnrollmentController as PHRSinusEnrollmentController;
use App\Http\Controllers\PHR\SinusSettingsController as PHRSinusSettingsController;
use App\Http\Controllers\PHR\VitalController as PHRVitalController;
use App\Http\Middleware\AuthenticateWebOrMcpRequest;
use Illuminate\Support\Facades\Route;

// Per-user AI provider settings used by ParseImportJob via User::resolvedAiClient().
// The authenticated PHR Config screen consumes these session-protected endpoints.
Route::middleware(['web', 'auth'])->group(function (): void {
    Route::get('/user/ai-prefs', [UserAiConfigurationController::class, 'index']);
    Route::post('/user/ai-prefs', [UserAiConfigurationController::class, 'store']);
    Route::put('/user/ai-prefs/{id}', [UserAiConfigurationController::class, 'update']);
    Route::delete('/user/ai-prefs/{id}', [UserAiConfigurationController::class, 'destroy']);
    Route::post('/user/ai-prefs/{id}/activate', [UserAiConfigurationController::class, 'activate']);
    Route::post('/user/ai-prefs/models', [UserAiModelsController::class, 'fetch']);
});

Route::middleware(['web', 'auth'])
    ->prefix('phr')
    ->name('phr.')
    ->group(function (): void {
        Route::get('/patients', [PHRPatientController::class, 'index'])->name('patients.index');
        Route::post('/patients', [PHRPatientController::class, 'store'])->name('patients.store');
        Route::get('/patients/{patient}', [PHRPatientController::class, 'show'])->whereNumber('patient')->name('patients.show');
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
        Route::patch('/patients/{patient}/procedures/{procedure}', [PHRProcedureController::class, 'update'])->whereNumber(['patient', 'procedure'])->name('patients.procedures.update');
        Route::delete('/patients/{patient}/procedures/{procedure}', [PHRProcedureController::class, 'destroy'])->whereNumber(['patient', 'procedure'])->name('patients.procedures.destroy');
        Route::get('/patients/{patient}/immunizations', [PHRImmunizationController::class, 'index'])->whereNumber('patient')->name('patients.immunizations.index');
        Route::post('/patients/{patient}/immunizations', [PHRImmunizationController::class, 'store'])->whereNumber('patient')->name('patients.immunizations.store');
        Route::get('/patients/{patient}/immunizations/{immunization}', [PHRImmunizationController::class, 'show'])->whereNumber(['patient', 'immunization'])->name('patients.immunizations.show');
        Route::patch('/patients/{patient}/immunizations/{immunization}', [PHRImmunizationController::class, 'update'])->whereNumber(['patient', 'immunization'])->name('patients.immunizations.update');
        Route::delete('/patients/{patient}/immunizations/{immunization}', [PHRImmunizationController::class, 'destroy'])->whereNumber(['patient', 'immunization'])->name('patients.immunizations.destroy');
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
