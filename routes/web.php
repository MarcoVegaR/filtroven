<?php

use App\Http\Controllers\HelpController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function (Request $request) {
    return $request->user()
        ? to_route('dashboard')
        : to_route('login');
})->name('home');

Route::middleware(['auth', 'verified', 'ensure-two-factor'])->group(function () {
    Route::get('dashboard', fn () => Inertia::render('dashboard'))->name('dashboard');

    Route::controller(HelpController::class)->prefix('help')->name('help.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('{category}/{slug}', 'show')->name('show');
    });
});

require __DIR__.'/settings.php';
require __DIR__.'/system.php';
