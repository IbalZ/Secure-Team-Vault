<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\CredentialAccessController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\VaultCredentialController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return Auth::check()
        ? redirect()->route('dashboard')
        : view('welcome');
})->name('landing');

// Accounts and password resets are controlled by administrators. Password
// confirmation remains enabled for secret reveal/copy operations.
Auth::routes(['register' => false, 'reset' => false]);

Route::middleware(['auth', 'account.usable'])->group(function () {
    Route::get('/dashboard', [HomeController::class, 'index'])->name('dashboard');
    Route::get('/home', fn () => redirect()->route('dashboard'))->name('home');

    Route::resource('credentials', VaultCredentialController::class);
    Route::post('/credentials/{credential}/secret/reveal', [VaultCredentialController::class, 'reveal'])
        ->middleware('throttle:credential-secrets')
        ->name('credentials.reveal');
    Route::post('/credentials/{credential}/secret/copy', [VaultCredentialController::class, 'copySecret'])
        ->middleware('throttle:credential-secrets')
        ->name('credentials.copy');
    Route::put('/credentials/{credential}/access', [CredentialAccessController::class, 'update'])
        ->name('credentials.access.update');

    Route::prefix('admin')->name('admin.')->middleware('role:admin')->group(function () {
        Route::resource('users', UserController::class)->except(['show', 'destroy']);
        Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('audit.index');
    });
});
