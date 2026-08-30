<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FahrplanTyp;
use App\Models\LineVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ConsolidatedFixtures;
use Tests\TestCase;

/**
 * Unterschied zweier Fahrplan-Versionen auf Fahrt-Ebene.
 */
final class LineVersionDiffTest extends TestCase
{
    use RefreshDatabase;

    private ConsolidatedFixtures $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = new ConsolidatedFixtures;
    }

    private function token(): string
    {
        return User::factory()->create()->createToken('test')->plainTextToken;
    }

    private function vergleiche(LineVersion $von, LineVersion $nach, bool $mitHalten = true): array
    {
        $query = http_build_query(array_filter([
            'from' => $von->id,
            'to' => $nach->id,
            'include' => $mitHalten ? 'stops' : null,
        ]));

        return $this->withToken($this->token())
            ->getJson("/api/v1/admin/line-version-diff?{$query}")
            ->assertOk()
            ->json('data');
    }

    public function test_requires_authentication(): void
    {
        $a = $this->f->version();
        $b = $this->f->version('1', FahrplanTyp::MoFrNormal, 2);

        $this->getJson("/api/v1/admin/line-version-diff?from={$a->id}&to={$b->id}")
            ->assertStatus(401);
    }

    public function test_identical_versions_report_everything_unchanged(): void
    {
        $a = $this->f->version('1', FahrplanTyp::MoFrNormal, 1);
        $b = $this->f->version('1', FahrplanTyp::MoFrNormal, 2);

        foreach ([$a, $b] as $version) {
            $this->f->fahrt($version, ['A', 'B', 'C'], ['07:00:00', '07:05:00', '07:10:00']);
            $this->f->fahrt($version, ['A', 'B', 'C'], ['08:00:00', '08:05:00', '08:10:00']);
        }

        $d = $this->vergleiche($a, $b);

        $this->assertSame(2, $d['summary']['unchanged']);
        $this->assertSame(0, $d['summary']['changed']);
        $this->assertSame(0, $d['summary']['added']);
        $this->assertSame(0, $d['summary']['removed']);
    }

    public function test_a_shifted_trip_is_reported_as_changed_not_added_and_removed(): void
    {
        // Der reale Fall: Linie 1 zum 21.09. — dieselbe Fahrt, eine Minute spaeter.
        $a = $this->f->version('1', FahrplanTyp::MoFrNormal, 1);
        $b = $this->f->version('1', FahrplanTyp::MoFrNormal, 2);

        $this->f->fahrt($a, ['A', 'B', 'C'], ['07:00:00', '07:05:00', '07:10:00']);
        $this->f->fahrt($b, ['A', 'B', 'C'], ['07:00:00', '07:05:00', '07:10:00']);
        $this->f->fahrt($a, ['A', 'B', 'C'], ['18:39:00', '18:44:00', '18:49:00']);
        $this->f->fahrt($b, ['A', 'B', 'C'], ['18:40:00', '18:45:00', '18:50:00']);

        $d = $this->vergleiche($a, $b);

        $this->assertSame(1, $d['summary']['unchanged']);
        $this->assertSame(1, $d['summary']['changed']);
        $this->assertSame(0, $d['summary']['added']);
        $this->assertSame(0, $d['summary']['removed']);

        $paar = $d['changed'][0];
        $this->assertSame('time', $paar['reason']);
        $this->assertSame(60, $paar['shift_seconds']);
        $this->assertTrue($paar['uniform_shift'], 'Alle Halte um dieselbe Minute verschoben');
        $this->assertSame('18:39:00', $paar['from_trip']['departure_time']);
        $this->assertSame('18:40:00', $paar['to_trip']['departure_time']);
    }

    public function test_same_times_but_changed_route_is_not_reported_as_unchanged(): void
    {
        // Die Signatur enthaelt keine Halte — ohne Gegenpruefung erschiene das als "unveraendert".
        $a = $this->f->version('1', FahrplanTyp::MoFrNormal, 1);
        $b = $this->f->version('1', FahrplanTyp::MoFrNormal, 2);

        $signatur = hash('sha256', 'gleiche-zeiten');
        $this->f->fahrt($a, ['A', 'B', 'C'], ['07:00:00', '07:05:00', '07:10:00'], $signatur);
        $this->f->fahrt($b, ['A', 'X', 'C'], ['07:00:00', '07:05:00', '07:10:00'], $signatur);

        $d = $this->vergleiche($a, $b);

        $this->assertSame(0, $d['summary']['unchanged'], 'Der Laufweg-Wechsel darf nicht durchrutschen');
        $this->assertSame(1, $d['summary']['changed']);
        $this->assertSame('route', $d['changed'][0]['reason']);
    }

    public function test_stop_detail_marks_added_and_removed_stops(): void
    {
        $a = $this->f->version('1', FahrplanTyp::MoFrNormal, 1);
        $b = $this->f->version('1', FahrplanTyp::MoFrNormal, 2);

        $this->f->fahrt($a, ['A', 'B', 'D'], ['07:00:00', '07:05:00', '07:15:00']);
        $this->f->fahrt($b, ['A', 'C', 'D'], ['07:00:00', '07:07:00', '07:15:00']);

        $paar = $this->vergleiche($a, $b)['changed'][0];
        $status = array_column($paar['stops'], 'status', 'stop_name');

        $this->assertSame('equal', $status['A']);
        $this->assertSame('only_from', $status['B'], 'B entfaellt');
        $this->assertSame('only_to', $status['C'], 'C kommt hinzu');
        $this->assertSame('equal', $status['D']);
    }

    public function test_trips_without_a_counterpart_are_added_or_removed(): void
    {
        $a = $this->f->version('1', FahrplanTyp::MoFrNormal, 1);
        $b = $this->f->version('1', FahrplanTyp::MoFrNormal, 2);

        $this->f->fahrt($a, ['A', 'B'], ['07:00:00', '07:10:00']);
        // Anderer Zielhalt UND andere Zeiten: verschiedene Signaturen, verschiedene Endpunkte —
        // hier gibt es nichts zu paaren. (Bei gleichen Zeiten waere die Signatur dieselbe, und
        // das System sieht dann bewusst eine Fahrt mit geaendertem Laufweg, keine zwei Fahrten.)
        $this->f->fahrt($b, ['A', 'Z'], ['09:00:00', '09:10:00']);

        $d = $this->vergleiche($a, $b);

        $this->assertSame(0, $d['summary']['unchanged']);
        $this->assertSame(0, $d['summary']['changed']);
        $this->assertSame(1, $d['summary']['added']);
        $this->assertSame(1, $d['summary']['removed']);
        $this->assertSame('Z', $d['added'][0]['end_stop']);
        $this->assertSame('B', $d['removed'][0]['end_stop']);
    }

    public function test_pairing_stops_at_the_tolerance_boundary(): void
    {
        // Genau 60 Minuten: noch eine verschobene Fahrt.
        $a = $this->f->version('1', FahrplanTyp::MoFrNormal, 1);
        $b = $this->f->version('1', FahrplanTyp::MoFrNormal, 2);
        $this->f->fahrt($a, ['A', 'B'], ['07:00:00', '07:10:00']);
        $this->f->fahrt($b, ['A', 'B'], ['08:00:00', '08:10:00']);

        $d = $this->vergleiche($a, $b);
        $this->assertSame(1, $d['summary']['changed'], '60 Minuten liegen noch in der Toleranz');
        $this->assertSame(3600, $d['changed'][0]['shift_seconds']);
    }

    public function test_pairing_refuses_beyond_the_tolerance(): void
    {
        // 61 Minuten: zwei verschiedene Fahrten, keine verschobene.
        $a = $this->f->version('1', FahrplanTyp::MoFrNormal, 1);
        $b = $this->f->version('1', FahrplanTyp::MoFrNormal, 2);
        $this->f->fahrt($a, ['A', 'B'], ['07:00:00', '07:10:00']);
        $this->f->fahrt($b, ['A', 'B'], ['08:01:00', '08:11:00']);

        $d = $this->vergleiche($a, $b);

        $this->assertSame(0, $d['summary']['changed']);
        $this->assertSame(1, $d['summary']['added']);
        $this->assertSame(1, $d['summary']['removed']);
    }

    public function test_duplicate_signatures_are_counted_as_a_multiset(): void
    {
        // Zweimal dieselbe Signatur links, einmal rechts: eine unveraendert, eine entfallen.
        $a = $this->f->version('1', FahrplanTyp::MoFrNormal, 1);
        $b = $this->f->version('1', FahrplanTyp::MoFrNormal, 2);

        $signatur = hash('sha256', 'doppelt');
        $this->f->fahrt($a, ['A', 'B'], ['07:00:00', '07:10:00'], $signatur);
        $this->f->fahrt($a, ['A', 'B'], ['07:00:00', '07:10:00'], $signatur);
        $this->f->fahrt($b, ['A', 'B'], ['07:00:00', '07:10:00'], $signatur);

        $d = $this->vergleiche($a, $b);

        $this->assertSame(1, $d['summary']['unchanged']);
        $this->assertSame(1, $d['summary']['removed']);
        $this->assertSame(0, $d['summary']['added']);
    }

    public function test_result_is_deterministic(): void
    {
        $a = $this->f->version('1', FahrplanTyp::MoFrNormal, 1);
        $b = $this->f->version('1', FahrplanTyp::MoFrNormal, 2);

        // Zwei gleich weit entfernte Kandidaten — die Paarung muss trotzdem reproduzierbar sein.
        $this->f->fahrt($a, ['A', 'B'], ['07:00:00', '07:10:00']);
        $this->f->fahrt($a, ['A', 'B'], ['07:20:00', '07:30:00']);
        $this->f->fahrt($b, ['A', 'B'], ['07:10:00', '07:20:00']);
        $this->f->fahrt($b, ['A', 'B'], ['07:30:00', '07:40:00']);

        $erst = $this->vergleiche($a, $b);
        $zweit = $this->vergleiche($a, $b);

        $this->assertSame($erst['changed'], $zweit['changed']);
        $this->assertSame(2, $erst['summary']['changed']);
    }

    public function test_stop_detail_is_omitted_without_include(): void
    {
        $a = $this->f->version('1', FahrplanTyp::MoFrNormal, 1);
        $b = $this->f->version('1', FahrplanTyp::MoFrNormal, 2);
        $this->f->fahrt($a, ['A', 'B'], ['07:00:00', '07:10:00']);
        $this->f->fahrt($b, ['A', 'B'], ['07:01:00', '07:11:00']);

        $paar = $this->vergleiche($a, $b, mitHalten: false)['changed'][0];

        $this->assertArrayNotHasKey('stops', $paar);
        $this->assertSame(60, $paar['shift_seconds'], 'Die Kennzahlen bleiben trotzdem da');
    }

    public function test_versions_of_different_day_types_are_rejected(): void
    {
        $a = $this->f->version('1', FahrplanTyp::MoFrNormal);
        $b = $this->f->version('1', FahrplanTyp::Sa);

        $this->withToken($this->token())
            ->getJson("/api/v1/admin/line-version-diff?from={$a->id}&to={$b->id}")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 422);
    }

    public function test_versions_of_different_lines_are_rejected(): void
    {
        $a = $this->f->version('1', FahrplanTyp::MoFrNormal);
        $b = $this->f->version('2', FahrplanTyp::MoFrNormal);

        $this->withToken($this->token())
            ->getJson("/api/v1/admin/line-version-diff?from={$a->id}&to={$b->id}")
            ->assertStatus(422);
    }

    public function test_identical_ids_are_rejected(): void
    {
        $a = $this->f->version();

        $this->withToken($this->token())
            ->getJson("/api/v1/admin/line-version-diff?from={$a->id}&to={$a->id}")
            ->assertStatus(422);
    }

    public function test_unknown_version_is_rejected(): void
    {
        $a = $this->f->version();

        $this->withToken($this->token())
            ->getJson("/api/v1/admin/line-version-diff?from={$a->id}&to=999999")
            ->assertStatus(422);
    }
}
