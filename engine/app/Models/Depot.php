<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\DepotFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ein Betriebshof (KURSE §3.2).
 *
 * Er hängt an der **Entscheidung**, nicht am Kurs: Ein Fahrzeug rückt morgens aus dem einen Hof
 * aus und abends in einen anderen ein, wenn der Umlauf es dorthin trägt. Aus- und Einrückhof
 * sind deshalb zwei getrennte Angaben an zwei getrennten `trip_links`.
 *
 * @property int $id
 * @property string $name
 * @property string|null $short_name
 * @property array<int, string>|null $modes
 * @property bool $active
 * @property string|null $note
 */
final class Depot extends Model
{
    /** @use HasFactory<DepotFactory> */
    use HasFactory;

    protected $fillable = ['name', 'short_name', 'modes', 'active', 'note'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'modes' => 'array',
            'active' => 'boolean',
        ];
    }

    /**
     * Die Kurzform, wo der Platz knapp ist — sonst der volle Name. Nie leer: Eine Marke ohne
     * Beschriftung wäre im Board nicht von einer ohne Hof zu unterscheiden.
     */
    public function display(): string
    {
        $kurz = $this->short_name;

        return $kurz === null || $kurz === '' ? $this->name : $kurz;
    }

    /**
     * Nimmt dieser Hof ein Fahrzeug dieses Verkehrsmittels auf?
     *
     * Eine leere Liste heißt **alle** — das ist der Vorgabefall und nicht „keines". Wer nichts
     * einschränkt, soll jeden Hof angeboten bekommen; die Einschränkung ist die Ausnahme.
     */
    public function acceptsMode(?string $mode): bool
    {
        $modi = $this->modes ?? [];

        return $modi === [] || $mode === null || in_array($mode, $modi, true);
    }

    /**
     * Die Haltestellen, an denen dieser Hof aus- und einrücken lässt.
     *
     * **Mehrere**, und das ist der Regelfall, nicht die Ausnahme: Die Westerhüsener Fahrten
     * beginnen fast immer an der *Schleswiger Straße* und nicht am Hof selbst — der Weg dorthin
     * ist die Betriebsfahrt, die im Fahrplan gar nicht steht. Kroatenwuhne hat dagegen keine;
     * dort greift die Automatik nicht.
     *
     * @return BelongsToMany<StopGroup, $this>
     */
    public function stopGroups(): BelongsToMany
    {
        return $this->belongsToMany(StopGroup::class, 'depot_stop_groups')->withTimestamps();
    }

    /**
     * @return HasMany<TripLink, $this>
     */
    public function tripLinks(): HasMany
    {
        return $this->hasMany(TripLink::class);
    }
}
