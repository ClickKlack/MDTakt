<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Beobachtete Gültigkeit einer Linien-Version (FAHRPLANPERIODEN §5.4 b).
 * `*_confirmed` = false bedeutet Fensterkante, also nur eine Untergrenze.
 *
 * @property int $id
 * @property int $line_version_id
 * @property Carbon $valid_from
 * @property Carbon $valid_to
 * @property bool $from_confirmed
 * @property bool $to_confirmed
 */
final class LineVersionInterval extends Model
{
    protected $fillable = ['line_version_id', 'valid_from', 'valid_to', 'from_confirmed', 'to_confirmed'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'valid_from' => 'date',
            'valid_to' => 'date',
            'from_confirmed' => 'boolean',
            'to_confirmed' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<LineVersion, $this>
     */
    public function lineVersion(): BelongsTo
    {
        return $this->belongsTo(LineVersion::class, 'line_version_id');
    }
}
