<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\LineColor;
use Illuminate\Database\Seeder;

/**
 * Startwerte der Linienfarben — dominante Farbe der offiziellen MVB-Liniensignets
 * (mvbnet.de). Im Admin pflegbar; idempotent.
 */
final class LineColorSeeder extends Seeder
{
    /** @var array<string, string> */
    private const COLORS = [
        '1' => '#c9346c', '2' => '#3c64ad', '3' => '#fecd2a', '4' => '#78c14e', '5' => '#b86f2e',
        '6' => '#523f95', '8' => '#f99d2d', '9' => '#0d7462', '10' => '#0089bf', '13' => '#353831',
        '51' => '#3c64ad', '52' => '#f9a033', '53' => '#fece2a', '54' => '#78c14e', '55' => '#b86f2e',
        '56' => '#d4be39', '57' => '#e12a90', '58' => '#01aca7', '59' => '#0c7462', '61' => '#0089bf',
        '66' => '#b03406', '69' => '#523f95', '71' => '#da3449', '72' => '#3c64ad', '73' => '#444843',
        'N1' => '#bc0c31', 'N2' => '#785ba3', 'N3' => '#d51030', 'N4' => '#007858', 'N5' => '#ffcc00',
        'N6' => '#f59c00', 'N7' => '#0082bf', 'N8' => '#c60b6e', 'N9' => '#e74010',
    ];

    public function run(): void
    {
        foreach (self::COLORS as $line => $color) {
            LineColor::query()->updateOrCreate(
                ['route_short_name' => $line],
                ['color' => $color],
            );
        }
    }
}
