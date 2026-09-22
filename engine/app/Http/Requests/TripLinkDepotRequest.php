<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\RouteType;
use App\Enums\TripLinkKind;
use App\Models\ConsolidatedTrip;
use App\Models\Depot;
use App\Models\TripLink;
use Illuminate\Validation\Validator;

/**
 * Den Betriebshof einer bestehenden Betriebsfahrt setzen oder wieder offen lassen.
 *
 * Eigener Request statt eines Feldes am Anlegen-Weg, weil es die übliche Reihenfolge ist: Erst
 * wird markiert — das ist die Aussage, die zählt —, der Hof kommt dazu, sobald er feststeht.
 * `depot_id: null` nimmt ihn wieder heraus und ist kein Rückschritt, sondern „noch offen".
 */
final class TripLinkDepotRequest extends ApiFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'depot_id' => ['present', 'nullable', 'integer', 'exists:depots,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $link = $this->link();

            // Ein Anschluss führt zu keinem Hof: Das Fahrzeug fährt weiter, es rückt nicht ein.
            if ($link->kind === TripLinkKind::Link) {
                $validator->errors()->add(
                    'depot_id',
                    'Ein Anschluss führt zu keinem Betriebshof — die Angabe gehört an eine Fahrt, '
                    .'die aus dem Hof kommt oder in ihn fährt.',
                );

                return;
            }

            $hof = $this->depot();

            if ($hof === null) {
                return;
            }

            if (! $hof->active) {
                $validator->errors()->add(
                    'depot_id',
                    sprintf('Der Betriebshof „%s" ist stillgelegt und nimmt keine neuen Fahrten mehr auf.', $hof->name),
                );

                return;
            }

            $mode = $this->modeOf($link);

            if ($mode !== null && ! $hof->acceptsMode($mode)) {
                $validator->errors()->add(
                    'depot_id',
                    sprintf('Der Betriebshof „%s" nimmt dieses Verkehrsmittel nicht auf.', $hof->name),
                );
            }
        });
    }

    public function link(): TripLink
    {
        /** @var TripLink $link */
        $link = $this->route('tripLink');

        return $link;
    }

    public function depot(): ?Depot
    {
        $id = $this->depotId();

        return $id === null ? null : Depot::query()->find($id);
    }

    public function depotId(): ?int
    {
        $wert = $this->input('depot_id');

        return $wert === null || $wert === '' ? null : (int) $wert;
    }

    /**
     * Das Verkehrsmittel der betroffenen Fahrt — bei `start` die beginnende, bei `end` die
     * endende. Die jeweils andere Seite ist bei einer Betriebsfahrt leer.
     */
    private function modeOf(TripLink $link): ?string
    {
        $id = $link->kind === TripLinkKind::Start ? $link->to_trip_id : $link->from_trip_id;

        if ($id === null) {
            return null;
        }

        $fahrt = ConsolidatedTrip::query()->find($id);

        return $fahrt === null ? null : RouteType::modeFor($fahrt->route_type);
    }
}
