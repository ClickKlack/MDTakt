<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\StopGroupOrigin;
use App\Models\StopGroup;
use App\Models\StopGroupMember;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pflegt die **Haltestellen** — die Klammer um die Halte, die im Betrieb derselbe Ort sind
 * (KURSE §3.1).
 *
 * Zwei Quellen, und die Reihenfolge ist wesentlich:
 *
 * 1. **Automatik über den normalisierten Namen.** Deckt den Regelfall ab: die Richtungs-Halte
 *    einer Endstelle heißen gleich. Am Realbestand sind das 49 von 64 einseitigen Endstellen.
 * 2. **Pflege von Hand** für den Rest. „Rothensee" und „Rothensee (Schleife)" sind dieselbe
 *    Haltestelle, heißen aber verschieden; kein Namensabgleich findet das, und eine reine
 *    Abstandsregel würde an anderer Stelle Falsches verschmelzen.
 *
 * Deshalb trägt jede Zuordnung ihre Herkunft: Die Automatik fasst `manual`-Zeilen nicht an.
 * Ohne diese Sperre wäre jede Pflege beim nächsten Import wieder verloren.
 */
final class StopGroupService
{
    /**
     * Umkreis für Vorschläge beim Zuordnen. Bewusst großzügig — ein Vorschlag ist eine Frage,
     * keine Entscheidung. Am Realbestand liegen die echten Gegenstücke bei 14–99 m; darüber
     * beginnen die Betriebsfahrten (`Südring` ↔ `Eiskellerplatz`, 484 m), die als solche
     * festgehalten und nicht verschmolzen werden sollen.
     */
    private const SUGGESTION_RADIUS_METERS = 350.0;

    private const EARTH_RADIUS_METERS = 6371000.0;

    /**
     * Schreibt die automatische Gruppierung fort: Jeder Halt ohne Zuordnung landet in der
     * Gruppe seines Namensschlüssels. Bestehende Zuordnungen bleiben unberührt — auch die
     * automatischen, denn ein Halt, der einmal zugeordnet war, soll nicht bei jedem Lauf
     * zwischen Gruppen wandern.
     *
     * Idempotent; läuft beim Import-Abschluss und ist von Hand anstoßbar.
     *
     * @return array{groups_created: int, stops_assigned: int}
     */
    public function sync(): array
    {
        $zugeordnet = StopGroupMember::query()->pluck('consolidated_stop_id')->all();

        $offen = DB::table('consolidated_stops')
            ->when($zugeordnet !== [], fn ($q) => $q->whereNotIn('id', $zugeordnet))
            ->select('id', 'name_key')
            ->get();

        if ($offen->isEmpty()) {
            return ['groups_created' => 0, 'stops_assigned' => 0];
        }

        $namen = app(ConsolidatedStopNameResolver::class)->namesFor($offen->pluck('id')->map(static fn ($id): int => (int) $id)->all());

        $neu = 0;
        $gesetzt = 0;

        foreach ($offen->groupBy('name_key') as $nameKey => $halte) {
            $gruppe = StopGroup::query()->where('name_key', $nameKey)->first();

            if ($gruppe === null) {
                $gruppe = StopGroup::query()->create([
                    // Der Anzeigename kommt vom Halt, nicht aus dem normalisierten Schlüssel:
                    // „herrenkrug" wäre als Überschrift unbrauchbar.
                    'name' => $namen[$halte->first()->id] ?? (string) $nameKey,
                    'name_key' => $nameKey,
                    'created_via' => StopGroupOrigin::Auto,
                ]);
                $neu++;
            }

            foreach ($halte as $halt) {
                StopGroupMember::query()->create([
                    'stop_group_id' => $gruppe->id,
                    'consolidated_stop_id' => $halt->id,
                    'assigned_via' => StopGroupOrigin::Auto,
                ]);
                $gesetzt++;
            }
        }

        Log::info('Stop groups synchronised', ['groups_created' => $neu, 'stops_assigned' => $gesetzt]);

        return ['groups_created' => $neu, 'stops_assigned' => $gesetzt];
    }

    /**
     * Ordnet einen Halt von Hand einer Haltestelle zu. Die alte Gruppe wird aufgeräumt, wenn
     * sie dadurch leer zurückbleibt — eine Haltestelle ohne Halte ist keine.
     */
    public function assign(int $stopId, StopGroup $gruppe): StopGroupMember
    {
        return DB::transaction(function () use ($stopId, $gruppe): StopGroupMember {
            $vorher = StopGroupMember::query()->where('consolidated_stop_id', $stopId)->first();
            $alteGruppe = $vorher?->stop_group_id;

            $vorher?->delete();

            $mitglied = StopGroupMember::query()->create([
                'stop_group_id' => $gruppe->id,
                'consolidated_stop_id' => $stopId,
                'assigned_via' => StopGroupOrigin::Manual,
            ]);

            $this->dropIfEmpty($alteGruppe);

            Log::info('Stop assigned to stop group', [
                'consolidated_stop_id' => $stopId,
                'stop_group_id' => $gruppe->id,
                'previous_group_id' => $alteGruppe,
            ]);

            return $mitglied;
        });
    }

    /**
     * Löst einen Halt aus seiner Haltestelle und gibt ihn der Automatik zurück: Er landet
     * wieder in der Gruppe seines Namensschlüssels.
     */
    public function detach(int $stopId): void
    {
        DB::transaction(function () use ($stopId): void {
            $mitglied = StopGroupMember::query()->where('consolidated_stop_id', $stopId)->first();

            if ($mitglied === null) {
                return;
            }

            $alteGruppe = $mitglied->stop_group_id;
            $mitglied->delete();
            $this->dropIfEmpty($alteGruppe);

            Log::info('Stop detached from stop group', [
                'consolidated_stop_id' => $stopId,
                'previous_group_id' => $alteGruppe,
            ]);
        });

        $this->sync();
    }

    /**
     * Schiebt alle Halte einer Gruppe in eine andere und löscht die leergeräumte.
     *
     * Der Weg für „Rothensee (Schleife)" → „Rothensee": Die Mitgliedschaften gelten danach als
     * von Hand gesetzt, damit die Automatik sie nicht wieder auseinanderzieht.
     */
    public function merge(StopGroup $von, StopGroup $nach): void
    {
        if ($von->id === $nach->id) {
            return;
        }

        DB::transaction(function () use ($von, $nach): void {
            StopGroupMember::query()
                ->where('stop_group_id', $von->id)
                ->update([
                    'stop_group_id' => $nach->id,
                    'assigned_via' => StopGroupOrigin::Manual->value,
                    'updated_at' => now(),
                ]);

            $von->delete();

            Log::info('Stop groups merged', ['from_group_id' => $von->id, 'into_group_id' => $nach->id]);
        });
    }

    /**
     * Die Haltestelle, zu der ein Halt gehört.
     */
    public function groupIdFor(int $stopId): ?int
    {
        $wert = StopGroupMember::query()->where('consolidated_stop_id', $stopId)->value('stop_group_id');

        return $wert === null ? null : (int) $wert;
    }

    /**
     * Die Haltestellen mehrerer Halte auf einen Schlag.
     *
     * `groupIdFor()` ist eine Abfrage je Halt — im Einzelfall richtig, in einem Mengen-Lauf über
     * Dutzende Kandidatenpaare aber tausende. Halte ohne Haltestelle fehlen im Ergebnis; der
     * Aufrufer unterscheidet „nicht zugeordnet" damit selbst.
     *
     * @param  array<int, int>  $stopIds
     * @return array<int, int> consolidated_stop_id => stop_group_id
     */
    public function groupIdsFor(array $stopIds): array
    {
        $eindeutig = array_values(array_unique(array_filter($stopIds)));

        if ($eindeutig === []) {
            return [];
        }

        $ergebnis = [];

        foreach (array_chunk($eindeutig, 1000) as $teil) {
            foreach (StopGroupMember::query()
                ->whereIn('consolidated_stop_id', $teil)
                ->pluck('stop_group_id', 'consolidated_stop_id') as $stopId => $gruppenId) {
                $ergebnis[(int) $stopId] = (int) $gruppenId;
            }
        }

        return $ergebnis;
    }

    /**
     * Die Halte einer Haltestelle.
     *
     * @return array<int, int>
     */
    public function stopIdsOf(StopGroup $gruppe): array
    {
        return StopGroupMember::query()
            ->where('stop_group_id', $gruppe->id)
            ->pluck('consolidated_stop_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Andere Haltestellen in Laufweite — Kandidaten für eine Zusammenlegung.
     *
     * Bewusst nur ein **Vorschlag**: Eine Abstandsregel allein würde an dichten Kreuzungen
     * Falsches verschmelzen, deshalb entscheidet der Mensch. Sortiert nach Abstand.
     *
     * @return array<int, array{id: int, name: string, distance_meters: int, stop_count: int}>
     */
    public function suggestionsFor(StopGroup $gruppe): array
    {
        $eigene = $this->anchorsByGroup();
        $meine = $eigene[$gruppe->id] ?? [];

        if ($meine === []) {
            return [];
        }

        $namen = StopGroup::query()->pluck('name', 'id');
        $vorschlaege = [];

        foreach ($eigene as $gruppenId => $anker) {
            if ($gruppenId === $gruppe->id) {
                continue;
            }

            $kuerzester = INF;

            foreach ($meine as $a) {
                foreach ($anker as $b) {
                    $d = $this->distance($a['lat'], $a['lon'], $b['lat'], $b['lon']);
                    $kuerzester = min($kuerzester, $d);
                }
            }

            if ($kuerzester <= self::SUGGESTION_RADIUS_METERS) {
                $vorschlaege[] = [
                    'id' => $gruppenId,
                    'name' => $namen[$gruppenId] ?? '—',
                    'distance_meters' => (int) round($kuerzester),
                    'stop_count' => count($anker),
                ];
            }
        }

        usort($vorschlaege, static fn (array $a, array $b): int => $a['distance_meters'] <=> $b['distance_meters']);

        return $vorschlaege;
    }

    /**
     * @return array<int, array<int, array{lat: float, lon: float}>>
     */
    private function anchorsByGroup(): array
    {
        $zeilen = DB::table('stop_group_members as m')
            ->join('consolidated_stops as s', 's.id', '=', 'm.consolidated_stop_id')
            ->select('m.stop_group_id', 's.anchor_lat', 's.anchor_lon')
            ->get();

        $anker = [];

        foreach ($zeilen as $zeile) {
            $anker[(int) $zeile->stop_group_id][] = [
                'lat' => (float) $zeile->anchor_lat,
                'lon' => (float) $zeile->anchor_lon,
            ];
        }

        return $anker;
    }

    private function distance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $h = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return 2 * self::EARTH_RADIUS_METERS * asin(min(1.0, sqrt($h)));
    }

    private function dropIfEmpty(?int $gruppenId): void
    {
        if ($gruppenId === null) {
            return;
        }

        $leer = ! StopGroupMember::query()->where('stop_group_id', $gruppenId)->exists();

        if ($leer) {
            StopGroup::query()->whereKey($gruppenId)->delete();
        }
    }
}
