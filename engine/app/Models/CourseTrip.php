<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CourseTripFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Zugehörigkeit einer Fahrt zu einem Umlauf.
 *
 * Gesetzt wird sie immer für die **ganze Kette**, nie für eine einzelne Fahrt — die Kursnummer
 * ist ein Etikett am Umlauf, nicht an der Fahrt (KURSE §2 K2). Das Unique auf
 * `consolidated_trip_id` hält fest, dass eine Fahrt zu höchstens einem Umlauf gehört.
 *
 * @property int $id
 * @property int $course_id
 * @property int $consolidated_trip_id
 */
final class CourseTrip extends Model
{
    /** @use HasFactory<CourseTripFactory> */
    use HasFactory;

    protected $fillable = ['course_id', 'consolidated_trip_id'];

    /**
     * @return BelongsTo<Course, $this>
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id');
    }

    /**
     * @return BelongsTo<ConsolidatedTrip, $this>
     */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(ConsolidatedTrip::class, 'consolidated_trip_id');
    }
}
