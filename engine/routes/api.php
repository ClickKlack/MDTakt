<?php

declare(strict_types=1);

use App\Http\Controllers\Collector\ImportController;
use App\Http\Controllers\LineController;
use App\Http\Controllers\StopController;
use App\Http\Controllers\TripController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    // Öffentliche Stammdaten-Endpunkte (kein Auth im MVP, nur Lesezugriff).
    Route::get('lines', [LineController::class, 'index'])->name('lines.index');
    Route::get('stops', [StopController::class, 'index'])->name('stops.index');
    Route::get('trips', [TripController::class, 'index'])->name('trips.index');

    // Interne Collector-Endpunkte — Bearer-Token-geschützt, gzip-Body wird entpackt (NAS → Engine).
    Route::prefix('collector')->middleware(['collector.token', 'decompress'])->group(function (): void {
        Route::get('imports', [ImportController::class, 'index'])->name('collector.imports.index');
        Route::post('imports', [ImportController::class, 'start'])->name('collector.imports.start');
        Route::post('imports/{run}/stop-times', [ImportController::class, 'stopTimes'])->name('collector.imports.stop-times');
        Route::post('imports/{run}/finish', [ImportController::class, 'finish'])->name('collector.imports.finish');
    });
});
