<?php

use App\Http\Controllers\LoginController;
use App\Http\Controllers\PHR\PageController as PHRPageController;
use App\Http\Controllers\PHR\PhrDocumentController;
use App\Http\Controllers\PHR\PhrExportController;
use Illuminate\Support\Facades\Route;

Route::get('/login', function () {
    return view('login');
})->name('login');

Route::post('/login', [LoginController::class, 'login']);
Route::post('/login/email-code', [LoginController::class, 'requestEmailCode'])
    ->middleware('throttle:5,1')
    ->name('login.email-code');
Route::post('/login/dev', [LoginController::class, 'devLogin'])->name('login.dev');
Route::post('/login/dev-by-id', [LoginController::class, 'devLoginById'])->name('login.dev.by-id');

Route::redirect('/', '/phr');

Route::middleware('auth')->group(function (): void {
    Route::get('/phr', [PHRPageController::class, 'index'])->name('phr.index');
    Route::get('/phr/patients', [PHRPageController::class, 'patients'])->name('phr.patients');
    Route::get('/phr/patients/manage', [PHRPageController::class, 'managePatients'])->name('phr.patients.manage');
    Route::get('/phr/imports', [PHRPageController::class, 'imports'])->name('phr.imports');
    Route::get('/phr/config', [PHRPageController::class, 'config'])->name('phr.config');
    Route::get('/phr/patient/{patient}', [PHRPageController::class, 'patient'])
        ->whereNumber('patient')
        ->name('phr.patient');
    Route::get('/phr/patient/{patient}/imaging/series/{series}/explore-3d', [PHRPageController::class, 'explore3d'])
        ->whereNumber(['patient', 'series'])
        ->name('phr.imaging.explore-3d');
});

Route::middleware(['auth', 'signed'])->group(function (): void {
    Route::get('/phr/documents/{document}/download', [PhrDocumentController::class, 'download'])
        ->whereNumber('document')
        ->name('phr.documents.download');
    Route::get('/phr/exports/{export}/download', [PhrExportController::class, 'download'])
        ->whereNumber('export')
        ->name('phr.exports.download');
});
