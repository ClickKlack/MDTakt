<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Der **Betriebshof** (KURSE §3.2). Magdeburg hat drei, und sie sind nach Verkehrsmittel
        // getrennt: Nord und Westerhüsen nehmen Straßenbahnen auf, Kroatenwuhne Busse.
        //
        // Warum eine eigene Tabelle und kein Haken an der Haltestelle: Kroatenwuhne ist **keiner
        // Haltestelle zugeordnet** — als Haken an einer `stop_group` ließe er sich gar nicht
        // führen. Dazu kommt, dass die `stop_groups` beim Import über den Namen neu gebildet
        // werden; ein Betriebshof ist aber gepflegtes Wissen und soll das überstehen.
        Schema::create('depots', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            // Kurzform für enge Stellen (Board-Zeile, Kurs-Tabelle), z. B. „Nord".
            $table->string('short_name', 16)->nullable();

            // Leere Liste = gilt für alle Verkehrsmittel. Sonst `tram`/`bus` — die Auswahl an
            // der Fahrt bietet dann nur passende Höfe an, und die automatische Zuordnung
            // greift nur beim passenden Verkehrsmittel.
            $table->json('modes')->nullable();

            // Stillgelegt statt gelöscht: Ein Hof, der nicht mehr betrieben wird, darf aus der
            // Auswahl verschwinden, ohne die Entscheidungen mitzunehmen, die an ihm hängen.
            $table->boolean('active')->default(true);
            $table->string('note')->nullable();
            $table->timestampsTz();
        });

        // **Mehrere Haltestellen je Hof.** Ausrückfahrten aus Westerhüsen beginnen fast immer an
        // der *Schleswiger Straße*, nicht am Hof selbst — der Weg dorthin ist die Betriebsfahrt,
        // die im Fahrplan gar nicht steht. Eine einzelne `stop_group_id` am Hof könnte das nicht
        // ausdrücken und hätte den Regelfall verfehlt.
        //
        // Daraus entsteht die Automatik: Wer an einer dieser Haltestellen eine Fahrt als
        // Betriebsfahrt markiert, bekommt den Hof gleich mitgesetzt.
        Schema::create('depot_stop_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('depot_id')->constrained('depots')->cascadeOnDelete();
            $table->foreignId('stop_group_id')->constrained('stop_groups')->cascadeOnDelete();
            $table->timestampsTz();

            $table->unique(['depot_id', 'stop_group_id']);
            // Bewusst **kein** Unique auf `stop_group_id` allein: An einer Haltestelle können
            // ein Tram- und ein Bushof hängen. Auseinander hält sie das Verkehrsmittel — und
            // wo auch das nicht reicht, wählt die Automatik lieber nichts als das Falsche.
            $table->index('stop_group_id');
        });

        // SQLite kann einen Fremdschlüssel nicht nachträglich an eine bestehende Tabelle
        // hängen; die Tests laufen darauf. Die Spalte entsteht deshalb ohne Constraint, und
        // die referenzielle Zusicherung kommt nur dort, wo sie auch trägt — in PostgreSQL.
        Schema::table('trip_links', function (Blueprint $table) {
            // Nur bei `kind=start`/`end` belegt, und auch dort **freiwillig**: Beginnt eine
            // Kette an einer Endstelle ohne zugeordneten Hof, steht er nicht fest. Ein
            // erzwungener Wert wäre dort geraten, und Geratenes ist schlimmer als eine offene
            // Angabe.
            $table->unsignedBigInteger('depot_id')->nullable();
            $table->index('depot_id');
        });

        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('trip_links', function (Blueprint $table) {
                $table->foreign('depot_id')->references('id')->on('depots')->nullOnDelete();
            });
        }

        $this->seedKnownDepots();
    }

    public function down(): void
    {
        Schema::table('trip_links', function (Blueprint $table) {
            if (DB::getDriverName() !== 'sqlite') {
                $table->dropForeign(['depot_id']);
            }

            $table->dropIndex(['depot_id']);
            $table->dropColumn('depot_id');
        });

        Schema::dropIfExists('depot_stop_groups');
        Schema::dropIfExists('depots');
    }

    /**
     * Die drei Magdeburger Höfe vorbelegen.
     *
     * Sie stehen fest und sind im Frontend änderbar — wer sie erst anlegen müsste, fände beim
     * ersten Markieren eine leere Auswahl vor. Die Haltestellen werden über den **Namen**
     * gesucht und nicht über eine Id: Die `stop_groups` entstehen beim Import neu, und eine
     * hier festgeschriebene Id zeigte in einer frischen Datenbank ins Leere. Fehlt eine
     * Haltestelle, entsteht der Hof trotzdem — nur ohne diese Zuordnung.
     */
    private function seedKnownDepots(): void
    {
        $hoefe = [
            [
                'name' => 'Betriebshof Nord',
                'short_name' => 'Nord',
                'modes' => ['tram'],
                'stop_names' => ['Betriebshof Nord / AMROC'],
            ],
            [
                'name' => 'Betriebshof Westerhüsen',
                'short_name' => 'Westerhüsen',
                'modes' => ['tram'],
                // Die Schleswiger Straße gehört dazu: Von dort rücken die Westerhüsener
                // Fahrten fast immer aus, der Weg zum Hof steht im Fahrplan nicht.
                'stop_names' => ['Westerhüsen (Betriebshof)', 'Schleswiger Straße'],
            ],
            [
                'name' => 'Betriebshof Kroatenwuhne',
                'short_name' => 'Kroatenwuhne',
                'modes' => ['bus'],
                // Faktisch keiner Haltestelle zugeordnet — dort greift die Automatik nicht,
                // und der Hof wird von Hand gewählt.
                'stop_names' => [],
            ],
        ];

        foreach ($hoefe as $hof) {
            $id = DB::table('depots')->insertGetId([
                'name' => $hof['name'],
                'short_name' => $hof['short_name'],
                'modes' => json_encode($hof['modes']),
                'active' => true,
                'note' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($hof['stop_names'] as $name) {
                $gruppe = DB::table('stop_groups')->where('name', $name)->value('id');

                if ($gruppe === null) {
                    continue;
                }

                DB::table('depot_stop_groups')->insert([
                    'depot_id' => $id,
                    'stop_group_id' => $gruppe,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
};
