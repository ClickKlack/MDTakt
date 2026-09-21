<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PeriodOrigin;
use App\Enums\PeriodStatus;
use Carbon\CarbonImmutable;
use Database\Factories\SchedulePeriodFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Netzweite Fahrplanperiode (FAHRPLANPERIODEN §4.1).
 *
 * @property int $id
 * @property string $label
 * @property Carbon $valid_from
 * @property Carbon|null $valid_to
 * @property-read PeriodStatus $status abgeleitet, nicht gespeichert — siehe unten
 * @property PeriodOrigin $created_via
 */
final class SchedulePeriod extends Model
{
    /** @use HasFactory<SchedulePeriodFactory> */
    use HasFactory;

    protected $fillable = ['label', 'valid_from', 'valid_to', 'created_via'];

    /**
     * `status` wird mit ausgegeben, obwohl keine Spalte dahintersteht.
     *
     * @var list<string>
     */
    protected $appends = ['status'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'valid_from' => 'date',
            'valid_to' => 'date',
            'created_via' => PeriodOrigin::class,
        ];
    }

    /**
     * Laufend oder eingefroren — **berechnet, nicht gespeichert**.
     *
     * Der Zustand hängt am heutigen Tag: Dieselbe Periode ist morgen eingefroren, ohne dass
     * jemand etwas schreibt. Als Spalte veraltete er deshalb still — wer am Vortag eine Periode
     * für den Folgetag anlegte, hatte am Folgetag zwei falsche Werte auf einmal.
     */
    protected function status(): Attribute
    {
        return Attribute::get(fn (): PeriodStatus => $this->isCurrent()
            ? PeriodStatus::Current
            : PeriodStatus::Frozen);
    }

    /**
     * Gilt diese Periode heute? Begonnen und noch nicht abgelöst.
     *
     * Eine erst künftig beginnende Periode ist bewusst nicht laufend — sonst schlüge ein Import
     * seine Versionen einer Periode zu, die noch gar nicht gilt.
     */
    public function isCurrent(): bool
    {
        $heute = CarbonImmutable::now()->startOfDay();
        $von = CarbonImmutable::parse($this->valid_from->toDateString());
        $bis = $this->valid_to === null ? null : CarbonImmutable::parse($this->valid_to->toDateString());

        return ! $von->greaterThan($heute) && ($bis === null || ! $bis->lessThan($heute));
    }

    /**
     * Dieselbe Regel wie {@see isCurrent()}, nur als Abfrage — höchstens eine Periode erfüllt
     * sie, weil die Kette lückenlos und überschneidungsfrei ist.
     *
     * @param  Builder<SchedulePeriod>  $query
     */
    public function scopeCurrent(Builder $query): void
    {
        $heute = CarbonImmutable::now()->startOfDay()->toDateString();

        $query
            ->whereDate('valid_from', '<=', $heute)
            ->where(fn (Builder $q) => $q
                ->whereNull('valid_to')
                ->orWhereDate('valid_to', '>=', $heute));
    }

    /**
     * @return HasMany<LineVersion, $this>
     */
    public function lineVersions(): HasMany
    {
        return $this->hasMany(LineVersion::class, 'period_id');
    }
}
