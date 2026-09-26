<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FahrplanTyp;
use Database\Factories\CourseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ein Umlauf als benennbare Einheit — die Kursnummer gehört ihm, nicht der Linie.
 *
 * Wechselt ein Fahrzeug in Sudenburg von der 1 auf die 13, bleibt die Nummer; nur der
 * Anzeige-Präfix wechselt (`1/03` → `13/03`, KURSE §2 K1). Die Nummer ist bewusst **nicht**
 * als eindeutig erzwungen (K3) — ob sie netzweit oder nur je Linie eindeutig ist, zeigt erst
 * die Pflege.
 *
 * @property int $id
 * @property int $period_id
 * @property FahrplanTyp $day_type
 * @property string $number
 * @property string|null $note
 */
final class Course extends Model
{
    /** @use HasFactory<CourseFactory> */
    use HasFactory;

    protected $fillable = ['period_id', 'day_type', 'number', 'note'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['day_type' => FahrplanTyp::class];
    }

    /**
     * Gleiche Kursnummer ohne Rücksicht auf führende Nullen: „03" = „3" (Tracker sendet ohne Null,
     * im Bestand gibt es beides). Nicht-numerische Nummern vergleichen ohne Groß/Klein.
     */
    public static function sameNumber(string $a, string $b): bool
    {
        $norm = static function (string $n): string {
            $n = trim($n);

            return ctype_digit($n) ? (ltrim($n, '0') ?: '0') : mb_strtolower($n);
        };

        return $norm($a) === $norm($b);
    }

    /**
     * @return BelongsTo<SchedulePeriod, $this>
     */
    public function period(): BelongsTo
    {
        return $this->belongsTo(SchedulePeriod::class, 'period_id');
    }

    /**
     * @return HasMany<CourseTrip, $this>
     */
    public function courseTrips(): HasMany
    {
        return $this->hasMany(CourseTrip::class, 'course_id');
    }
}
