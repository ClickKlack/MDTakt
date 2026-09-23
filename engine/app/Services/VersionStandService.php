<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FahrplanTyp;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * **Versionsstände**: die Abschnitte, in denen sich an einer Menge von Linien-Versionen nichts
 * ändert.
 *
 * Der Baustein stand ursprünglich im {@see StopLinkBoardService} — dort entstand er, weil der
 * Haltestellen-Editor auf Periode und Fahrplantyp arbeitet und nicht auf einem Datum (KURSE §2
 * K5). Innerhalb einer Periode kann eine Linie aber mehrere Versionen haben; „Periode +
 * Fahrplantyp" benennt dann keinen eindeutigen Fahrplan.
 *
 * Seit eine Fahrt mehrere Anschlüsse tragen darf (§3), braucht die **Kursansicht** dieselbe
 * Unterscheidung: Verzweigt sich ein Umlauf am Versionswechsel einer beteiligten Linie, stünden
 * sonst Fahrten beider Versionen untereinander, als führe das Fahrzeug sie am selben Tag. Damit
 * ist die Faltung nicht mehr die Sache eines Editors, sondern eine eigene Ebene.
 *
 * Die Versionsmenge kommt vom Aufrufer: am Halt die Versionen, die ihn berühren, im Umlauf die
 * der beteiligten Fahrten.
 */
final class VersionStandService
{
    /**
     * Die Versionsstände einer Versionsmenge.
     *
     * @param  array<int, int>  $versionIds
     * @return array<int, array<string, mixed>>
     */
    public function foldFor(array $versionIds, FahrplanTyp $typ): array
    {
        $versionIds = array_values(array_unique(array_filter($versionIds)));

        if ($versionIds === []) {
            return [];
        }

        $intervalle = DB::table('line_version_intervals')
            ->whereIn('line_version_id', $versionIds)
            ->orderBy('valid_from')
            ->get()
            ->groupBy('line_version_id');

        $versionen = [];

        foreach ($versionIds as $id) {
            $versionen[] = [
                'id' => $id,
                'intervals' => $intervalle->get($id, collect())
                    ->map(static fn (object $i): array => [
                        'from' => CarbonImmutable::parse($i->valid_from)->startOfDay(),
                        'to' => CarbonImmutable::parse($i->valid_to)->startOfDay(),
                    ])
                    ->values()
                    ->all(),
            ];
        }

        return $this->foldStands($versionen, $typ);
    }

    /** @var array<string, FahrplanTyp> Datum => Typ, damit der Klassifizierer nicht je Tag erneut abfragt */
    private array $typCache = [];

    public function __construct(private readonly FahrplanTypClassifier $classifier) {}

    /**
     * Zerlegt den Zeitstrahl an allen Intervallgrenzen und verschmilzt wieder, wo dieselbe
     * Versionsmenge gilt. Übrig bleiben genau die Abschnitte, in denen sich am beteiligten
     * Fahrplan nichts ändert.
     *
     * @param  array<int, array{id: int, intervals: array<int, array{from: CarbonImmutable, to: CarbonImmutable}>}>  $versionen
     * @return array<int, array<string, mixed>>
     */
    private function foldStands(array $versionen, FahrplanTyp $typ): array
    {
        $grenzen = [];

        foreach ($versionen as $version) {
            foreach ($version['intervals'] as $intervall) {
                // Der Tag nach dem Ende ist ebenfalls eine Grenze — sonst verschwände der
                // Übergang von „gilt" zu „gilt nicht mehr".
                $grenzen[$intervall['from']->toDateString()] = $intervall['from'];
                $grenzen[$intervall['to']->addDay()->toDateString()] = $intervall['to']->addDay();
            }
        }

        if ($grenzen === []) {
            return [];
        }

        ksort($grenzen);
        $punkte = array_values($grenzen);

        $roh = [];

        for ($i = 0; $i < count($punkte) - 1; $i++) {
            $von = $punkte[$i];
            $bis = $punkte[$i + 1]->subDay();

            $aktiv = [];

            foreach ($versionen as $version) {
                foreach ($version['intervals'] as $intervall) {
                    if ($intervall['from'] <= $von && $von <= $intervall['to']) {
                        $aktiv[] = $version['id'];
                        break;
                    }
                }
            }

            if ($aktiv === []) {
                continue;
            }

            // Auf die Tage des gewählten Typs beschneiden. Ein Abschnitt, in den keiner
            // fällt, ist kein Fahrplanstand, sondern die Lücke dazwischen — im `mo_fr`-Strang
            // sind das die Wochenenden. Und ein Zeitraum, der am Samstag beginnt, obwohl nur
            // Mo-Fr zählt, nennt ein Datum, an dem nichts gilt.
            $beschnitten = $this->trimToType($von, $bis, $typ);

            if ($beschnitten === null) {
                continue;
            }

            sort($aktiv);
            $roh[] = ['from' => $beschnitten[0], 'to' => $beschnitten[1], 'versions' => $aktiv];
        }

        return $this->groupByVersionSet($roh);
    }

    /**
     * Schneidet einen Abschnitt auf den ersten und letzten Tag des gewählten Fahrplantyps zu.
     * `null`, wenn gar keiner darin liegt.
     *
     * Bereits geprüfte Tage werden gemerkt — der Klassifizierer fragt je Datum die
     * Ferien-Tabelle ab, und dieselben Tage kommen über mehrere Abschnitte hinweg wieder vor.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    private function trimToType(CarbonImmutable $von, CarbonImmutable $bis, FahrplanTyp $typ): ?array
    {
        $erster = null;
        $letzter = null;

        for ($tag = $von; $tag <= $bis; $tag = $tag->addDay()) {
            $schluessel = $tag->toDateString();

            $this->typCache[$schluessel] ??= $this->classifier->classify($tag);

            if ($this->typCache[$schluessel] !== $typ) {
                continue;
            }

            $erster ??= $tag;
            $letzter = $tag;
        }

        return $erster === null ? null : [$erster, $letzter];
    }

    /**
     * Fasst alle Abschnitte mit **derselben Versionsmenge** zu einem Stand zusammen — auch
     * wenn sie nicht aneinandergrenzen.
     *
     * Das ist dieselbe Denkweise wie bei den Linien-Versionen selbst (FAHRPLANPERIODEN §5.4 a):
     * Ein Stand ist über seinen *Inhalt* identifiziert, nicht über seine Laufzeit, und trägt
     * mehrere Gültigkeits-Zeiträume.
     *
     * Ohne das zerfällt die Auswahl: Nachtlinien wechseln im `mo_fr`-Strang wöchentlich die
     * Version, weil die Nacht von Sonntag auf Montag eine Sonntagsnacht ist (FAHRPLANPERIODEN
     * §8). An Herrenkrug ergab das 16 Stände über vier Wochen — einen je Montag und je
     * Di–Fr-Block —, obwohl es dort nur drei verschiedene Fahrplanstände gibt.
     *
     * @param  array<int, array{from: CarbonImmutable, to: CarbonImmutable, versions: array<int, int>}>  $roh
     * @return array<int, array<string, mixed>>
     */
    private function groupByVersionSet(array $roh): array
    {
        $staende = [];

        foreach ($roh as $abschnitt) {
            $schluessel = implode(',', $abschnitt['versions']);

            if (! isset($staende[$schluessel])) {
                $staende[$schluessel] = ['versions' => $abschnitt['versions'], 'ranges' => []];
            }

            $ranges = &$staende[$schluessel]['ranges'];
            $letzter = $ranges === [] ? null : $ranges[count($ranges) - 1];

            // Angrenzende Zeiträume desselben Standes bleiben ein Zeitraum.
            if ($letzter !== null && $letzter['to']->addDay()->equalTo($abschnitt['from'])) {
                $ranges[count($ranges) - 1]['to'] = $abschnitt['to'];
            } else {
                $ranges[] = ['from' => $abschnitt['from'], 'to' => $abschnitt['to']];
            }

            unset($ranges);
        }

        // Nach dem ersten Zeitraum sortieren, damit die Auswahl chronologisch bleibt.
        uasort($staende, static fn (array $a, array $b): int => $a['ranges'][0]['from'] <=> $b['ranges'][0]['from']);

        $ergebnis = [];
        $index = 0;

        foreach ($staende as $stand) {
            $von = $stand['ranges'][0]['from'];
            $bis = $stand['ranges'][count($stand['ranges']) - 1]['to'];

            $ergebnis[] = [
                'index' => $index++,
                // Gesamtspanne für die kurze Beschriftung …
                'valid_from' => $von->toDateString(),
                'valid_to' => $bis->toDateString(),
                // … die einzelnen Zeiträume für die ehrliche Antwort, wann der Stand gilt.
                'ranges' => array_map(
                    static fn (array $r): array => [
                        'valid_from' => $r['from']->toDateString(),
                        'valid_to' => $r['to']->toDateString(),
                    ],
                    $stand['ranges'],
                ),
                'line_version_ids' => $stand['versions'],
                'line_count' => count($stand['versions']),
            ];
        }

        return $ergebnis;
    }

    /**
     * Ohne Vorgabe der Abschnitt, der heute enthält — sonst der letzte. Der Pflegende soll
     * beim Öffnen den Stand sehen, an dem er gerade arbeitet.
     *
     * @param  array<int, array<string, mixed>>  $staende
     * @return array<string, mixed>|null
     */
    public function pick(array $staende, ?int $standIndex): ?array
    {
        if ($staende === []) {
            return null;
        }

        if ($standIndex !== null) {
            foreach ($staende as $stand) {
                if ($stand['index'] === $standIndex) {
                    return $stand;
                }
            }

            return null;
        }

        $heute = CarbonImmutable::now()->toDateString();

        foreach ($staende as $stand) {
            if ($stand['valid_from'] <= $heute && $heute <= $stand['valid_to']) {
                return $stand;
            }
        }

        return $staende[count($staende) - 1];
    }
}
