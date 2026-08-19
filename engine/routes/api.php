<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\HolidayController;
use App\Http\Controllers\Admin\ImportController as AdminImportController;
use App\Http\Controllers\Admin\LineColorController;
use App\Http\Controllers\Admin\LineVersionController;
use App\Http\Controllers\Admin\SchoolHolidayController;
use App\Http\Controllers\Collector\ImportController;
use App\Http\Controllers\LineController;
use App\Http\Controllers\StopController;
use App\Http\Controllers\TripController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    // Öffentliche Stammdaten-Endpunkte (kein Auth im MVP, nur Lesezugriff).
    Route::get('lines', [LineController::class, 'index'])->name('lines.index');
    Route::get('lines/{line}/trips', [LineController::class, 'trips'])->name('lines.trips');
    Route::get('stops', [StopController::class, 'index'])->name('stops.index');
    Route::get('trips', [TripController::class, 'index'])->name('trips.index');

    // Admin-Schaltzentrale — Login öffentlich, alles übrige Sanctum-geschützt.
    Route::prefix('admin')->group(function (): void {
        Route::post('login', [AuthController::class, 'login'])->name('admin.login');

        Route::middleware('auth:sanctum')->group(function (): void {
            Route::post('logout', [AuthController::class, 'logout'])->name('admin.logout');
            Route::get('me', [AuthController::class, 'me'])->name('admin.me');
            Route::get('imports', [AdminImportController::class, 'index'])->name('admin.imports.index');
            Route::put('line-colors/{line}', [LineColorController::class, 'update'])->name('admin.line-colors.update');
            Route::delete('line-colors/{line}', [LineColorController::class, 'destroy'])->name('admin.line-colors.destroy');

            // Fahrplanperioden-Config: Schulferien (CRUD) + berechnete Feiertage (read-only)
            Route::get('school-holidays', [SchoolHolidayController::class, 'index'])->name('admin.school-holidays.index');
            Route::post('school-holidays', [SchoolHolidayController::class, 'store'])->name('admin.school-holidays.store');
            Route::put('school-holidays/{schoolHoliday}', [SchoolHolidayController::class, 'update'])->name('admin.school-holidays.update');
            Route::delete('school-holidays/{schoolHoliday}', [SchoolHolidayController::class, 'destroy'])->name('admin.school-holidays.destroy');
            Route::get('holidays', [HolidayController::class, 'index'])->name('admin.holidays.index');

            // Fahrplan-Konsolidat: Änderungshistorie je Linie und Betriebstag-Typ (I-13)
            Route::get('line-versions', [LineVersionController::class, 'index'])->name('admin.line-versions.index');
        });
    });

    // Interne Collector-Endpunkte — Bearer-Token-geschützt, gzip-Body wird entpackt (NAS → Engine).
    // throttle steht vorn: eine Flut wird verworfen, bevor Token-Vergleich und gzip-Dekompression
    // CPU kosten. 120/min ist bewusst großzügig — ein realer Lauf sendet rund 19 Requests (Start,
    // ~17 stop_times-Chunks, Abschluss) und das nur wöchentlich. Der Import darf nie am Limit scheitern.
    Route::prefix('collector')->middleware(['throttle:120,1', 'collector.token', 'decompress'])->group(function (): void {
        Route::get('imports', [ImportController::class, 'index'])->name('collector.imports.index');
        Route::post('imports', [ImportController::class, 'start'])->name('collector.imports.start');
        Route::post('imports/{run}/stop-times', [ImportController::class, 'stopTimes'])->name('collector.imports.stop-times');
        Route::post('imports/{run}/finish', [ImportController::class, 'finish'])->name('collector.imports.finish');
    });
});
