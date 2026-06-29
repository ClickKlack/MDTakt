<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Im Admin pflegbare Linienfarbe (je route_short_name).
 *
 * @property string $route_short_name
 * @property string $color
 */
final class LineColor extends Model
{
    protected $primaryKey = 'route_short_name';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'route_short_name',
        'color',
    ];
}
