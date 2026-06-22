<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\GtfsImportStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Audit-Datensatz eines GTFS-Import-Laufs (Start/Ende, Status, Counts, Feed-Stand).
 *
 * @property int $id
 * @property GtfsImportStatus $status
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property string|null $feed_version
 * @property Carbon|null $feed_start_date
 * @property Carbon|null $feed_end_date
 * @property array<string, int>|null $counts
 * @property string|null $error_message
 */
final class GtfsImportRun extends Model
{
    // Eigene Zeitstempel (started_at/finished_at) statt created_at/updated_at.
    public $timestamps = false;

    protected $fillable = [
        'status',
        'started_at',
        'finished_at',
        'feed_version',
        'feed_start_date',
        'feed_end_date',
        'counts',
        'error_message',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => GtfsImportStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'feed_start_date' => 'date',
            'feed_end_date' => 'date',
            'counts' => 'array',
        ];
    }
}
