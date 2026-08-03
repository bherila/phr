<?php

use App\Http\Controllers\OAuthLoginController;
use App\Http\Controllers\OhifViewerController;
use App\Http\Controllers\PHR\PageController as PHRPageController;
use App\Http\Controllers\PHR\PhrDocumentController;
use App\Http\Controllers\PHR\PhrExportController;
use App\Http\Controllers\PHR\PhrNativeBackupController;
use App\Http\Controllers\UptimeController;
use Illuminate\Support\Facades\Route;

Route::get('/login', function () {
    return view('login');
})->name('login');

Route::get('/oauth/redirect', [OAuthLoginController::class, 'redirect'])
    ->middleware('throttle:20,1')
    ->name('oauth.redirect');
Route::get('/oauth/callback', [OAuthLoginController::class, 'callback'])
    ->middleware('throttle:20,1')
    ->name('oauth.callback');
Route::post('/logout', [OAuthLoginController::class, 'logout'])
    ->middleware('auth')
    ->name('logout');
Route::redirect('/', '/phr');

Route::middleware('auth')->group(function (): void {
    Route::get('/uptime', UptimeController::class)->name('uptime');
    Route::get('/phr', [PHRPageController::class, 'index'])->name('phr.index');
    Route::get('/phr/patients', [PHRPageController::class, 'patients'])->name('phr.patients');
    Route::get('/phr/patients/manage', [PHRPageController::class, 'managePatients'])->name('phr.patients.manage');
    Route::get('/phr/imports', [PHRPageController::class, 'imports'])->name('phr.imports');
    Route::get('/phr/config', [PHRPageController::class, 'config'])->name('phr.config');
    Route::get('/phr/data-hub', [PHRPageController::class, 'dataHub'])->name('phr.data-hub');
    Route::get('/phr/patient/{patient}', [PHRPageController::class, 'patient'])
        ->whereNumber('patient')
        ->name('phr.patient');
    Route::get('/phr/patient/{patient}/imaging/series/{series}/explore-3d', [PHRPageController::class, 'explore3d'])
        ->whereNumber(['patient', 'series'])
        ->name('phr.imaging.explore-3d');

    /*
     * Static OHIF Viewer bundle (public/ohif/, uploaded out of band — see
     * OhifViewerController). The imaging UI opens /ohif/viewer/dicomjson?url=…
     * in a new tab, and that manifest URL is an authenticated /api/phr/ route,
     * so the viewer is useless without a session. Keeping the entrypoint behind
     * `auth` means an expired session lands on the identity provider and comes
     * back to the viewer URL via redirect()->intended(), instead of rendering a
     * viewer shell that then fails to load any images.
     */
    Route::get('/ohif', OhifViewerController::class)->name('ohif.index');
    Route::get('/ohif/viewer/{path?}', OhifViewerController::class)
        ->where('path', '.*')
        ->name('ohif.viewer');
});

Route::middleware(['auth', 'signed'])->group(function (): void {
    Route::get('/phr/documents/{document}/download', [PhrDocumentController::class, 'download'])
        ->whereNumber('document')
        ->name('phr.documents.download');
    Route::get('/phr/exports/{export}/download', [PhrExportController::class, 'download'])
        ->whereNumber('export')
        ->name('phr.exports.download');
    Route::get('/phr/native-backups/{backup}/download', [PhrNativeBackupController::class, 'download'])
        ->whereNumber('backup')
        ->name('phr.native-backups.download');
});
