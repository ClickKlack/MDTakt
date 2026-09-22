<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Depot;
use App\Models\StopGroup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Das Betriebshof-Verzeichnis (KURSE §3.2).
 *
 * Klein, aber eine eigene Ebene: Der Hof ist ein Betriebspunkt, kein Fahrplan-Halt. Er entsteht
 * nicht aus dem Import, sondern aus der Pflege, und er überlebt jeden Import unangetastet.
 */
final class DepotService
{
    /**
     * Das Verzeichnis samt Verwendung.
     *
     * `usage_count` ist keine Zierde: Er entscheidet, ob ein Hof gelöscht oder nur stillgelegt
     * werden darf — und beantwortet im Frontend die Frage, ob eine Umbenennung folgenlos ist.
     *
     * @return array<int, array<string, mixed>>
     */
    public function directory(bool $nurAktive = false): array
    {
        $verwendung = DB::table('trip_links')
            ->select('depot_id', DB::raw('count(*) as anzahl'))
            ->whereNotNull('depot_id')
            ->groupBy('depot_id')
            ->pluck('anzahl', 'depot_id');

        return Depot::query()
            ->with('stopGroups')
            ->when($nurAktive, static fn ($q) => $q->where('active', true))
            ->orderBy('name')
            ->get()
            ->map(fn (Depot $hof): array => $this->describe($hof, (int) ($verwendung[$hof->id] ?? 0)))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function describe(Depot $hof, ?int $verwendung = null): array
    {
        return [
            'id' => $hof->id,
            'name' => $hof->name,
            'short_name' => $hof->short_name,
            'display' => $hof->display(),
            // Mehrere, nach Namen sortiert. Leer heisst: Hier greift die Automatik nicht,
            // der Hof wird von Hand gewaehlt.
            'stop_groups' => $hof->stopGroups
                ->sortBy('name')
                ->map(static fn (StopGroup $g): array => ['id' => $g->id, 'name' => $g->name])
                ->values()
                ->all(),
            // Immer eine Liste, nie `null`: Das Frontend soll nicht zwei Leer-Fälle
            // unterscheiden müssen. Leer heißt „alle Verkehrsmittel".
            'modes' => $hof->modes ?? [],
            'active' => $hof->active,
            'note' => $hof->note,
            'usage_count' => $verwendung ?? $hof->tripLinks()->count(),
        ];
    }

    /**
     * Lässt sich dieser Hof löschen?
     *
     * Hängt eine Entscheidung daran, **nein** — die Angabe „ausgerückt aus Nord" ginge sonst
     * still verloren. Stilllegen ist dann der Weg: Der Hof verschwindet aus der Auswahl, bleibt
     * an den bestehenden Entscheidungen aber lesbar.
     */
    public function isInUse(Depot $hof): bool
    {
        return $hof->tripLinks()->exists();
    }

    /**
     * @param  array<string, mixed>  $daten
     * @param  array<int, int>  $stopGroupIds
     */
    public function create(array $daten, array $stopGroupIds = []): Depot
    {
        $hof = DB::transaction(function () use ($daten, $stopGroupIds): Depot {
            $hof = Depot::query()->create($daten);
            $hof->stopGroups()->sync($stopGroupIds);

            return $hof;
        });

        Log::info('Depot created', [
            'depot_id' => $hof->id,
            'name' => $hof->name,
            'stop_group_ids' => $stopGroupIds,
        ]);

        return $hof->load('stopGroups');
    }

    /**
     * @param  array<string, mixed>  $daten
     * @param  array<int, int>  $stopGroupIds
     */
    public function update(Depot $hof, array $daten, array $stopGroupIds = []): Depot
    {
        DB::transaction(function () use ($hof, $daten, $stopGroupIds): void {
            $hof->update($daten);
            $hof->stopGroups()->sync($stopGroupIds);
        });

        Log::info('Depot updated', [
            'depot_id' => $hof->id,
            'name' => $hof->name,
            'active' => $hof->active,
            'stop_group_ids' => $stopGroupIds,
        ]);

        return $hof->refresh()->load('stopGroups');
    }

    /**
     * Der Hof, der sich aus der Haltestelle **von selbst** ergibt.
     *
     * Wer an der Schleswiger Straße eine Fahrt als Ausrücken markiert, meint Westerhüsen — das
     * ist dort der Regelfall, und es von Hand nachzuklicken wäre Arbeit, die aus der Zuordnung
     * schon folgt.
     *
     * **Geraten wird nicht.** `null` kommt zurück, wenn kein Hof an dieser Haltestelle hängt,
     * wenn keiner das Verkehrsmittel aufnimmt — *oder wenn mehrere passen*. Im letzten Fall ist
     * eine offene Angabe richtiger als eine zufällig gewählte: Sie ist als „noch offen" lesbar,
     * ein falscher Hof dagegen steht als Tatsache in den Daten.
     */
    public function suggestFor(?int $stopId, ?string $mode): ?Depot
    {
        if ($stopId === null) {
            return null;
        }

        $gruppe = DB::table('stop_group_members')
            ->where('consolidated_stop_id', $stopId)
            ->value('stop_group_id');

        if ($gruppe === null) {
            return null;
        }

        $kandidaten = Depot::query()
            ->where('active', true)
            ->whereHas('stopGroups', static fn ($q) => $q->where('stop_groups.id', $gruppe))
            ->get()
            ->filter(static fn (Depot $hof): bool => $hof->acceptsMode($mode))
            ->values();

        if ($kandidaten->count() !== 1) {
            if ($kandidaten->count() > 1) {
                Log::debug('Depot suggestion is ambiguous', [
                    'stop_id' => $stopId,
                    'stop_group_id' => $gruppe,
                    'mode' => $mode,
                    'depot_ids' => $kandidaten->pluck('id')->all(),
                ]);
            }

            return null;
        }

        return $kandidaten->first();
    }

    public function delete(Depot $hof): void
    {
        $id = $hof->id;

        $hof->delete();

        Log::info('Depot deleted', ['depot_id' => $id]);
    }
}
