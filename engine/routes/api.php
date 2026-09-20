<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\CoverageController;
use App\Http\Controllers\Admin\HolidayController;
use App\Http\Controllers\Admin\ImportController as AdminImportController;
use App\Http\Controllers\Admin\LineColorController;
use App\Http\Controllers\Admin\LineVersionController;
use App\Http\Controllers\Admin\LineVersionDiffController;
use App\Http\Controllers\Admin\PeriodChangeOfferController;
use App\Http\Controllers\Admin\SchedulePeriodController;
use App\Http\Controllers\Admin\SchoolHolidayController;
use App\Http\Controllers\Admin\StopGroupController;
use App\Http\Controllers\Admin\StopLinkController;
use App\Http\Controllers\Admin\TimetableController;
use App\Http\Controllers\Admin\TripLinkController;
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

            // Abdeckung des Konsolidats: welche Zeiträume sind abrufbar, wo sind Lücken
            Route::get('coverage', [CoverageController::class, 'index'])->name('admin.coverage.index');

            // Unterschied zweier Versionen auf Fahrt-Ebene
            Route::get('line-version-diff', [LineVersionDiffController::class, 'show'])
                ->name('admin.line-version-diff');

            // Fahrplan einer Version als Matrix: Halte als Zeilen, Fahrten als Spalten
            Route::get('line-versions/{lineVersion}/timetable', [TimetableController::class, 'show'])
                ->name('admin.line-versions.timetable');

            // Haltestellen als Betriebspunkte: die Klammer um die Richtungs-Bahnsteige.
            // Automatisch über den Namen gebildet, von Hand nachpflegbar (KURSE §3.1).
            Route::get('stop-groups', [StopGroupController::class, 'index'])->name('admin.stop-groups.index');
            Route::post('stop-groups', [StopGroupController::class, 'store'])->name('admin.stop-groups.store');
            Route::get('stop-groups/{stopGroup}', [StopGroupController::class, 'show'])->name('admin.stop-groups.show');
            Route::put('stop-groups/{stopGroup}', [StopGroupController::class, 'update'])->name('admin.stop-groups.update');
            Route::post('stop-groups/{stopGroup}/stops', [StopGroupController::class, 'assign'])->name('admin.stop-groups.assign');
            Route::delete('stop-groups/{stopGroup}/stops/{stop}', [StopGroupController::class, 'detach'])->name('admin.stop-groups.detach');
            Route::post('stop-groups/{stopGroup}/merge', [StopGroupController::class, 'merge'])->name('admin.stop-groups.merge');

            // Umlauf-Pflege (I-14): Haltestellen-Editor und die Entscheidungen daraus.
            // Die Kette führt, die Kursnummer ist ein Etikett daran (KURSE §2 K2).
            Route::get('stop-links', [StopLinkController::class, 'index'])->name('admin.stop-links.index');
            Route::post('trip-links', [TripLinkController::class, 'store'])->name('admin.trip-links.store');
            Route::delete('trip-links/{tripLink}', [TripLinkController::class, 'destroy'])->name('admin.trip-links.destroy');

            // Fahrplanperioden — netzweit, kuratiert (FAHRPLANPERIODEN §4.1)
            Route::get('schedule-periods', [SchedulePeriodController::class, 'index'])->name('admin.schedule-periods.index');
            Route::post('schedule-periods', [SchedulePeriodController::class, 'store'])->name('admin.schedule-periods.store');
            Route::put('schedule-periods/{period}', [SchedulePeriodController::class, 'update'])->name('admin.schedule-periods.update');
            Route::delete('schedule-periods/{period}', [SchedulePeriodController::class, 'destroy'])->name('admin.schedule-periods.destroy');

            // Periodenwechsel-Vorschläge — das System bietet an, der Admin entscheidet (§4.3)
            Route::get('period-change-offers', [PeriodChangeOfferController::class, 'index'])->name('admin.period-offers.index');
            Route::post('period-change-offers/{offer}/accept', [PeriodChangeOfferController::class, 'accept'])->name('admin.period-offers.accept');
            Route::post('period-change-offers/{offer}/decline', [PeriodChangeOfferController::class, 'decline'])->name('admin.period-offers.decline');
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
