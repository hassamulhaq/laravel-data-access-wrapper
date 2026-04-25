<?php

// Locations
use App\Http\Controllers\Tenant\LocationController;

Route::prefix('locations')->name('locations.')->group(function () {
    Route::get('/', [LocationController::class, 'index'])->name('index');
    Route::post('/', [LocationController::class, 'store'])->name('store');
    Route::get('/{location}', [LocationController::class, 'show'])->name('show');
    Route::patch('/{location}', [LocationController::class, 'update'])->name('update');
    Route::delete('/{location}', [LocationController::class, 'destroy'])->name('destroy');
    Route::post('/{location}/restore', [LocationController::class, 'restore'])->name('restore');
    Route::patch('/{location}/toggle-status', [LocationController::class, 'toggleStatus'])->name('toggle-status');
});