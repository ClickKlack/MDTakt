<script setup lang="ts">
import { computed, nextTick, ref, type VNode } from 'vue'
import type { TimetableDirection, TimetableSightingGroup, TimetableTrip } from '../services/timetable'
import { courseMarkClass, courseMarkTitle } from '../utils/courseMark'
import { formatClock } from '../utils/timezone'

const props = defineProps<{
  direction: TimetableDirection
  busy: boolean
  /**
   * Bereichsmodus: Start- und Endspalte markieren, um eine Nummernfolge fortzuschreiben. Die
   * Markierung haengt an der **Spaltennummer-Kopfzeile**, nicht an der Kurszeile — so bleibt
   * das Inline-Edit einer einzelnen Nummer ohne Moduswechsel moeglich.
   */
  rangeMode?: boolean
  rangeFrom?: number | null
  rangeTo?: number | null
  /** Fahrt, die hervorgehoben wird — der Einstieg aus der Prüfliste der Sichtungen. */
  highlightTrip?: number | null
}>()

const emit = defineEmits<{
  assign: [tripId: number, number: string]
  detach: [tripId: number]
  rangePick: [tripId: number]
  sightingAccept: [trip: TimetableTrip, group: TimetableSightingGroup]
  sightingReject: [trip: TimetableTrip, group: TimetableSightingGroup]
  sightingDetails: [trip: TimetableTrip]
}>()

/**
 * Die Zeile „Sichtungen" erscheint nur, wenn es in dieser Richtung etwas zu entscheiden gibt —
 * sonst kostete sie jeder Tabelle Höhe, ohne etwas zu sagen.
 */
const hatSichtungen = computed(() => props.direction.trips.some((t) => t.sightings.length > 0))

/** Die Kurszeile rückt unter die Sichtungszeile; beide bleiben beim Scrollen stehen. */
const kursTop = computed(() => (hatSichtungen.value ? '5.75rem' : '2.25rem'))

/** rot = weicht vom Kurs der Fahrt ab, gelb = Fahrt hat noch keinen Kurs, grün = stimmt. */
function chipKlasse(g: TimetableSightingGroup): string {
  switch (g.comparison) {
    case 'differs':
      return 'bg-red-600 text-white hover:bg-red-700'
    case 'none':
      return 'bg-amber-400 text-amber-950 hover:bg-amber-500'
    default:
      return 'bg-emerald-600 text-white hover:bg-emerald-700'
  }
}

function chipTitel(g: TimetableSightingGroup, trip: TimetableTrip): string {
  const lokal = trip.course ? `lokal ${trip.course.display}` : 'lokal kein Kurs'
  const folge = g.next_version ? ' · aus Folgeversion' : ''
  return `Gesichtet ${g.display} (${g.count}×, ${lokal})${folge} — klicken für Details`
}

/**
 * Liegt diese Spalte im markierten Bereich?
 *
 * Ueber den **Index** in `direction.trips` — dieselbe Reihenfolge, die die Engine aus dem
 * Fahrplan bekommt (entlang des Betriebstags, nicht der Uhr). Verkehrt herum markiert wird
 * getauscht, wie in der Engine auch.
 */
function imBereich(index: number): boolean {
  const grenzen = bereichGrenzen.value

  return grenzen !== null && index >= grenzen[0] && index <= grenzen[1]
}

const bereichGrenzen = computed<[number, number] | null>(() => {
  const von = props.direction.trips.findIndex((t) => t.id === props.rangeFrom)

  if (von === -1) {
    return null
  }

  const bis = props.direction.trips.findIndex((t) => t.id === props.rangeTo)

  // Erst eine Grenze gesetzt: Der Bereich ist diese eine Spalte.
  if (bis === -1) {
    return [von, von]
  }

  return von <= bis ? [von, bis] : [bis, von]
})

function istGrenze(index: number): boolean {
  const grenzen = bereichGrenzen.value

  return grenzen !== null && (index === grenzen[0] || index === grenzen[1])
}

/** Die Fahrt, deren Kursnummer gerade bearbeitet wird. */
const bearbeitet = ref<number | null>(null)
const eingabe = ref('')

function bearbeite(trip: TimetableTrip): void {
  bearbeitet.value = trip.id
  eingabe.value = trip.course?.number ?? ''
}

/**
 * Fokus setzen und eine vorhandene Nummer gleich markieren: Wer einen Kurs korrigiert, tippt
 * die neue Nummer, statt erst die alte zu loeschen.
 *
 * Zwei Fallen stecken darin, beide in dieser Reihenfolge zu umgehen:
 *
 * 1. Ueber ein `ref` geht es nicht — ein `ref` innerhalb eines `v-for` sammelt Vue zu einem
 *    **Array**, nicht zu einem einzelnen Element. Deshalb der Mount-Hook des Elements.
 * 2. Der Mount-Hook allein genuegt nicht: Vue ruft `vnodeHook` **vor** den Directive-Hooks,
 *    und `v-model` setzt `el.value` erst in seinem `mounted`. Zum Zeitpunkt des Hooks ist das
 *    Feld also noch leer, und `select()` markiert nichts. `nextTick` wartet den ganzen
 *    Render-Durchlauf ab.
 */
async function feldBereit(vnode: VNode): Promise<void> {
  await nextTick()

  const el = vnode.el as HTMLInputElement | null
  el?.focus()
  el?.select()
}

function uebernimm(trip: TimetableTrip): void {
  const wert = eingabe.value.trim()
  bearbeitet.value = null

  if (wert === (trip.course?.number ?? '')) {
    return
  }

  // Leer bedeutet loesen - aber nur, wenn vorher einer dranhing.
  if (wert === '') {
    if (trip.course) {
      emit('detach', trip.id)
    }
    return
  }

  emit('assign', trip.id, wert)
}

/**
 * Ein Halt, der auf dem Laufweg erneut berührt wird (Wendeschleife, Stichabstecher), bekommt
 * eine zweite Zeile. Ohne Kennzeichnung sähe das nach einem Doppeleintrag aus.
 */
function zeilenTitel(repeatIndex: number): string | undefined {
  return repeatIndex > 0
    ? 'Dieser Halt wird auf dem Laufweg ein weiteres Mal berührt (Wendeschleife oder Stichabstecher).'
    : undefined
}

const spaltenbreite = computed(() => `${props.direction.trips.length * 3.5 + 14}rem`)
</script>

<template>
  <section class="mt-6">
    <header class="mx-auto max-w-6xl px-6">
      <h3 class="text-sm font-semibold text-slate-900">
        {{ direction.start_stop }} → {{ direction.end_stop }}
      </h3>
      <p class="mt-1 text-xs text-slate-500">
        {{ direction.trip_count }} Fahrten · {{ direction.rows.length }} Halte
        <template v-if="direction.variant_count > 1">
          · {{ direction.variant_count }} Laufwege auf eine Achse gebracht
        </template>
      </p>

      <slot name="werkzeuge" />

      <p v-if="rangeMode" class="mt-2 text-xs text-emerald-800">
        Klicke die <strong>Spaltennummern</strong> an: erst die Startspalte, dann die letzte. Die Kursnummer einer
        einzelnen Spalte lässt sich weiterhin direkt ändern.
      </p>

      <!-- Die Ausrichtung ist eine Heuristik. Läuft sie auseinander, muss die Anzeige das sagen,
           statt eine womöglich irreführende Tabelle unkommentiert zu zeigen. -->
      <p
        v-if="direction.alignment_warning"
        class="mt-2 rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-900"
      >
        <strong>Die Laufwege dieser Richtung unterscheiden sich stark.</strong>
        Die gemeinsame Halte-Achse ist deutlich länger als jeder einzelne Laufweg — die Tabelle
        zeigt korrekte Zeiten, liest sich aber lückenhaft.
      </p>
    </header>

    <!--
      Bewusst außerhalb des sonst durchgängigen max-w-6xl-Rahmens: Eine Fahrplantabelle mit bis zu
      120 Spalten braucht die volle Breite. Kopfzeile und Haltespalte bleiben beim Scrollen stehen.
      Wichtig: border-separate statt border-collapse — bei collapse verschwinden die Rahmen von
      sticky-Zellen. Trennlinien deshalb über ring-*, nicht über border-*.
    -->
    <div class="mt-3 overflow-x-auto pb-2">
      <table
        class="border-separate border-spacing-0 text-sm tabular-nums"
        :style="{ minWidth: spaltenbreite }"
      >
        <thead>
          <tr>
            <th
              class="sticky left-0 top-0 z-30 bg-slate-50 px-4 py-2 text-left text-xs font-medium uppercase text-slate-500 ring-1 ring-slate-200"
            >
              Halt
            </th>
            <!-- Im Bereichsmodus ist diese Zelle der Griff; ohne ihn bleibt sie inert wie bisher. -->
            <th
              v-for="(trip, i) in direction.trips"
              :key="trip.id"
              class="sticky top-0 z-20 min-w-14 px-2 py-2 text-center text-xs font-medium ring-1 ring-slate-200 transition"
              :id="`fahrt-${trip.id}`"
              :class="[
                rangeMode && imBereich(i)
                  ? 'bg-emerald-100 text-emerald-900'
                  : trip.id === highlightTrip
                    ? 'bg-amber-200 font-bold text-amber-900'
                    : 'bg-slate-50 text-slate-500',
                rangeMode ? 'cursor-pointer hover:bg-emerald-200' : '',
                rangeMode && istGrenze(i) ? 'ring-2 ring-emerald-600' : '',
              ]"
              :title="
                rangeMode
                  ? `Fahrt ${i + 1} als Bereichsgrenze wählen`
                  : `Fahrt ${i + 1} · Signatur ${trip.signature.slice(0, 12)}…`
              "
              @click="rangeMode && emit('rangePick', trip.id)"
            >
              {{ i + 1 }}
            </th>
          </tr>
          <!--
            Kurszeile: Sie sagt, zu welchem Umlauf eine Spalte gehoert - die Auskunft, wegen
            der I-13 (D) offen war. Der Kurs haengt an der Kette, nicht an der Fahrt: Ein
            Eintrag hier setzt ihn fuer alle Fahrten des Umlaufs.
          -->
          <!--
            Sichtungen aus MDKursTracker: im Fahrplan entscheiden, weil sich hier die Richtigkeit am
            besten beurteilen laesst - Nachbarspalten und Kurs stehen direkt daneben. Je Spalte die
            meistgenannte Nummer mit Annehmen/Ablehnen; weitere Nummern und Details per Klick.
          -->
          <tr v-if="hatSichtungen">
            <th
              class="sticky left-0 top-9 z-30 h-14 bg-violet-50 px-4 py-1 text-left text-xs font-medium uppercase text-violet-700 ring-1 ring-slate-200"
            >
              Sichtungen
            </th>
            <th
              v-for="trip in direction.trips"
              :key="`sicht-${trip.id}`"
              class="sticky top-9 z-20 h-14 px-0.5 py-1 text-center align-top ring-1 ring-slate-200"
              :class="trip.id === highlightTrip ? 'bg-amber-100' : 'bg-violet-50'"
            >
              <template v-if="trip.sightings.length > 0">
                <button
                  type="button"
                  class="w-full rounded px-1 py-0.5 text-xs font-semibold tabular-nums"
                  :class="[chipKlasse(trip.sightings[0]), trip.sightings[0].next_version ? 'ring-2 ring-violet-500' : '']"
                  :title="chipTitel(trip.sightings[0], trip)"
                  @click="emit('sightingDetails', trip)"
                >
                  {{ trip.sightings[0].number }}<sup v-if="trip.sightings[0].count > 1" class="ml-px font-normal">×{{ trip.sightings[0].count }}</sup><sup
                    v-if="trip.sightings.length > 1"
                    class="ml-px font-normal"
                    >+{{ trip.sightings.length - 1 }}</sup
                  >
                </button>
                <div class="mt-0.5 flex justify-center gap-0.5">
                  <button
                    type="button"
                    class="rounded px-1 text-xs text-emerald-700 hover:bg-emerald-100 disabled:opacity-40"
                    :disabled="busy"
                    :title="`${trip.sightings[0].display} annehmen`"
                    @click="emit('sightingAccept', trip, trip.sightings[0])"
                  >
                    ✓
                  </button>
                  <button
                    type="button"
                    class="rounded px-1 text-xs text-slate-500 hover:bg-slate-200 disabled:opacity-40"
                    :disabled="busy"
                    :title="`${trip.sightings[0].display} ablehnen`"
                    @click="emit('sightingReject', trip, trip.sightings[0])"
                  >
                    ✗
                  </button>
                </div>
              </template>
            </th>
          </tr>
          <tr>
            <th
              class="sticky left-0 z-30 bg-slate-50 px-4 py-1 text-left text-xs font-medium uppercase text-slate-500 ring-1 ring-slate-200"
              :style="{ top: kursTop }"
            >
              Kurs
            </th>
            <th
              v-for="(trip, i) in direction.trips"
              :key="`kurs-${trip.id}`"
              class="sticky z-20 px-1 py-1 text-center ring-1 ring-slate-200"
              :class="rangeMode && imBereich(i) ? 'bg-emerald-50' : 'bg-slate-50'"
              :style="{ top: kursTop }"
            >
              <input
                v-if="bearbeitet === trip.id"
                v-model="eingabe"
                @vue:mounted="feldBereit"
                type="text"
                maxlength="8"
                class="w-12 rounded border border-slate-800 px-1 py-0.5 text-center text-xs tabular-nums focus:outline-none"
                @blur="uebernimm(trip)"
                @keydown.enter.prevent="uebernimm(trip)"
                @keydown.esc.prevent="bearbeitet = null"
              />
              <button
                v-else
                type="button"
                class="w-full rounded px-1 py-0.5 text-xs tabular-nums transition disabled:opacity-50"
                :class="
                  trip.course
                    ? courseMarkClass(trip.course.sighting)
                    : 'text-slate-300 hover:bg-slate-200 hover:text-slate-600'
                "
                :disabled="busy"
                :title="
                  trip.course
                    ? `Kurs ${trip.course.display}${courseMarkTitle(trip.course.sighting)} — klicken zum Ändern`
                    : 'Kursnummer eintragen'
                "
                @click="bearbeite(trip)"
              >
                {{ trip.course ? trip.course.number : '–' }}
              </button>
            </th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="zeile in direction.rows" :key="zeile.position" class="hover:bg-slate-50">
            <th
              scope="row"
              class="sticky left-0 z-10 max-w-64 truncate bg-white px-4 py-1.5 text-left font-normal text-slate-700 ring-1 ring-slate-100"
              :title="zeilenTitel(zeile.repeat_index)"
            >
              {{ zeile.stop_name }}
              <sup v-if="zeile.repeat_index > 0" class="ml-0.5 text-slate-400">
                {{ zeile.repeat_index + 1 }}.
              </sup>
            </th>
            <td
              v-for="trip in direction.trips"
              :key="trip.id"
              class="px-2 py-1.5 text-center ring-1 ring-slate-100"
              :class="trip.cells[zeile.position] ? 'text-slate-700' : 'text-slate-300'"
            >
              <!-- Leere Zelle als Punkt, nicht als Leerraum: Beim horizontalen Scrollen
                   verliert das Auge sonst die Zeile. -->
              {{ trip.cells[zeile.position] ? formatClock(trip.cells[zeile.position]) : '·' }}
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>
