<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppHeader from '../components/AppHeader.vue'
import CourseGridTable from '../components/CourseGridTable.vue'
import LineBadge from '../components/LineBadge.vue'
import {
  fetchCourseGrid,
  fetchLineCourses,
  type CourseChain,
  type CourseChainTrip,
  type CourseGrid,
  type CourseTerminal,
  type LineCourseOverview,
} from '../services/courses'
import { FAHRPLAN_TYPEN, fetchLines, type FahrplanTyp, type Line } from '../services/lines'
import { lineSortKey, lineTypeOrder } from '../utils/lineStyle'
import { fetchSchedulePeriods, periodOptionLabel, type SchedulePeriod } from '../services/schedulePeriods'
import { formatClock, formatDuration } from '../utils/timezone'

const route = useRoute()
const router = useRouter()

const linien = ref<Line[]>([])
const perioden = ref<SchedulePeriod[]>([])
const uebersicht = ref<LineCourseOverview | null>(null)

const gewaehlteLinie = ref<string | null>(null)
const gewaehltePeriode = ref<number | null>(null)
const dayType = ref<FahrplanTyp>('mo_fr')
const nurUnvollstaendige = ref(false)

/**
 * „Kette" zeigt je Umlauf seine Fahrten untereinander — gut, um **einen** Umlauf zu pruefen.
 * „Tabelle" stellt die Umlaeufe **nebeneinander**: Halte als Zeilen, ein Kurs je Spalte. Erst so
 * wird sichtbar, was ein Takt ist — Kurs 1 und Kurs 2 fahren dieselbe Folge, nur versetzt.
 *
 * Zwei Endpunkte, weil die Tabelle deutlich schwerer wiegt (Linie 6 Mo-Fr: rund 8.500 Zellen).
 * Sie wird deshalb erst geladen, wenn sie gebraucht wird.
 */
const ansicht = ref<'kette' | 'tabelle'>('kette')

const grid = ref<CourseGrid | null>(null)

const loading = ref(true)
const loadingKurse = ref(false)
const error = ref<string | null>(null)

const linienVerzeichnis = computed<Record<string, Line>>(() =>
  Object.fromEntries(linien.value.map((l) => [l.route_short_name, l])),
)

/** Fällt das Verzeichnis aus, trägt das Signet wenigstens die richtige Form. */
function signet(linie: string, mode: 'tram' | 'bus' | 'other'): Line {
  return (
    linienVerzeichnis.value[linie] ?? {
      route_short_name: linie,
      route_type: mode === 'tram' ? 0 : 3,
      mode,
      modes: [mode],
      route_ids: [],
      color: null,
    }
  )
}

const sichtbareKurse = computed(() => {
  if (uebersicht.value === null) {
    return []
  }
  return nurUnvollstaendige.value
    ? uebersicht.value.courses.filter((k) => k.breaks > 0)
    : uebersicht.value.courses
})

onMounted(async () => {
  try {
    const [l, p] = await Promise.all([fetchLines(), fetchSchedulePeriods()])

    linien.value = l
      .slice()
      .sort(
        (a, b) =>
          lineTypeOrder(a) - lineTypeOrder(b) ||
          lineSortKey(a) - lineSortKey(b) ||
          a.route_short_name.localeCompare(b.route_short_name),
      )
    perioden.value = p

    // Auswahl aus der URL uebernehmen, damit eine Kursliste verlinkbar bleibt.
    gewaehlteLinie.value = (route.query.line as string) ?? linien.value[0]?.route_short_name ?? null
    gewaehltePeriode.value =
      Number(route.query.period) || p.find((periode) => periode.status === 'current')?.id || p[0]?.id || null
    dayType.value = ((route.query.day_type as FahrplanTyp) ?? 'mo_fr') as FahrplanTyp
    ansicht.value = route.query.view === 'tabelle' ? 'tabelle' : 'kette'

    await lade()
  } catch {
    error.value = 'Linien oder Perioden konnten nicht geladen werden.'
  } finally {
    loading.value = false
  }
})

async function lade(): Promise<void> {
  if (gewaehlteLinie.value === null || gewaehltePeriode.value === null) {
    uebersicht.value = null
    return
  }

  loadingKurse.value = true
  error.value = null

  try {
    // Die Kennzahlen und die Fahrten ohne Kurs kommen immer aus der Uebersicht; die Tabelle
    // liegt auf einem eigenen Endpunkt und wird nur geholt, wenn sie gerade gezeigt wird.
    const [u, g] = await Promise.all([
      fetchLineCourses(gewaehlteLinie.value, gewaehltePeriode.value, dayType.value),
      ansicht.value === 'tabelle'
        ? fetchCourseGrid(gewaehlteLinie.value, gewaehltePeriode.value, dayType.value)
        : Promise.resolve(null),
    ])

    uebersicht.value = u
    grid.value = g

    spiegelUrl()
  } catch {
    uebersicht.value = null
    grid.value = null
    error.value = 'Die Umläufe konnten nicht geladen werden.'
  } finally {
    loadingKurse.value = false
  }
}

function spiegelUrl(): void {
  void router.replace({
    name: 'courses',
    query: {
      line: gewaehlteLinie.value,
      period: gewaehltePeriode.value,
      day_type: dayType.value,
      view: ansicht.value === 'tabelle' ? 'tabelle' : undefined,
    },
  })
}

/**
 * Der Wechsel zur Tabelle holt sie nach, die Rueckkehr zur Kette nicht: Die Uebersicht liegt
 * bereits vor, und die einmal geholte Tabelle bleibt fuer den naechsten Wechsel stehen.
 */
watch(ansicht, () => {
  if (loading.value) {
    return
  }

  if (ansicht.value === 'tabelle' && grid.value === null) {
    void lade()
    return
  }

  spiegelUrl()
})

/**
 * Ein Abstand ist erst dann auffällig, wenn dort **kein** Anschluss steht — dann ist die Kette
 * gerissen. Mit Anschluss ist auch eine lange Wende in Ordnung.
 */
/**
 * Die Betriebshof-Marke an einem Ketten-Ende in Anzeigeform.
 *
 * Drei Zustaende, die auseinanderzuhalten sind — und der erste ist der einzige, der auffallen
 * soll: Ein Umlauf, der ins Leere beginnt oder endet, ist eine Luecke. Eine Marke **ohne** Hof
 * ist dagegen vollstaendig gepflegt: An einer Endstelle steht der Hof oft nicht fest.
 */
function hofMarke(terminal: CourseTerminal, richtung: 'aus' | 'ein'): {
  text: string
  klasse: string
  fehlt: boolean
} {
  if (!terminal.marked) {
    return {
      text:
        richtung === 'aus'
          ? 'Kein Ausrücken markiert — der Umlauf beginnt ins Leere'
          : 'Kein Einrücken markiert — der Umlauf endet ins Leere',
      klasse: 'bg-amber-50 text-amber-900',
      fehlt: true,
    }
  }

  const wohin = richtung === 'aus' ? 'Aus dem Betriebshof' : 'In den Betriebshof'

  return {
    text: terminal.depot === null ? `${wohin} — welcher, ist offen` : `${wohin} ${terminal.depot.name}`,
    klasse: 'bg-sky-50 text-sky-900',
    fehlt: false,
  }
}

/**
 * Aus- und Einrueckhof nebeneinander — sie sind **nicht** zwangslaeufig derselbe, und genau das
 * soll am Umlauf auf einen Blick zu sehen sein.
 */
function hofSpanne(kurs: CourseChain): { text: string; verschieden: boolean } | null {
  const aus = kurs.terminal_out.depot
  const ein = kurs.terminal_in.depot

  if (aus === null && ein === null) {
    return null
  }

  return {
    text: `${aus?.display ?? '?'} → ${ein?.display ?? '?'}`,
    verschieden: aus !== null && ein !== null && aus.id !== ein.id,
  }
}

function istRiss(trip: CourseChainTrip, index: number): boolean {
  return index > 0 && !trip.linked_to_previous
}

watch([gewaehlteLinie, gewaehltePeriode, dayType], () => {
  if (!loading.value) {
    void lade()
  }
})
</script>

<template>
  <div class="min-h-full bg-slate-100">
    <AppHeader />

    <main class="mx-auto max-w-6xl px-6 py-8">
      <h1 class="text-2xl font-semibold text-slate-900">Kurse</h1>
      <p class="mt-1 max-w-3xl text-sm text-slate-600">
        Die Umläufe einer Linie im Ganzen. Ein Umlauf läuft über Linien hinweg — eine 1 wird in Sudenburg zur 13 —,
        deshalb stehen hier auch die Fahrten der anderen Linien, sobald der Umlauf diese Linie berührt.
      </p>

      <p v-if="error" class="mt-4 rounded-md bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ error }}</p>
      <p v-if="loading" class="mt-4 text-sm text-slate-500">Wird geladen …</p>

      <template v-else>
        <!-- Auswahl -->
        <div class="mt-6 flex flex-wrap gap-2">
          <button
            v-for="linie in linien"
            :key="linie.route_short_name"
            type="button"
            class="rounded-lg p-1 transition"
            :class="
              gewaehlteLinie === linie.route_short_name ? 'ring-2 ring-slate-800' : 'hover:ring-2 hover:ring-slate-300'
            "
            @click="gewaehlteLinie = linie.route_short_name"
          >
            <LineBadge :line="linie" size="lg" />
          </button>
        </div>

        <div class="mt-4 flex flex-wrap items-end gap-6">
          <div>
            <label class="block text-xs font-medium uppercase tracking-wide text-slate-500">Periode</label>
            <select
              v-model.number="gewaehltePeriode"
              class="mt-1 rounded-md border border-slate-300 px-3 py-1.5 text-sm focus:border-slate-800 focus:outline-none"
            >
              <option v-for="periode in perioden" :key="periode.id" :value="periode.id">
                {{ periodOptionLabel(periode) }}
              </option>
            </select>
          </div>

          <div>
            <label class="block text-xs font-medium uppercase tracking-wide text-slate-500">Fahrplantyp</label>
            <div class="mt-1 flex flex-wrap gap-1 rounded-lg bg-slate-200/60 p-1">
              <button
                v-for="typ in FAHRPLAN_TYPEN"
                :key="typ.value"
                type="button"
                class="rounded-md px-3 py-1 text-sm transition"
                :class="
                  dayType === typ.value ? 'bg-white font-medium text-slate-900 shadow-sm' : 'text-slate-600 hover:bg-white/60'
                "
                @click="dayType = typ.value"
              >
                {{ typ.label }}
              </button>
            </div>
          </div>
        </div>

        <p v-if="loadingKurse" class="mt-6 text-sm text-slate-500">Umläufe werden geladen …</p>

        <template v-else-if="uebersicht">
          <!-- Kennzahlen -->
          <div class="mt-6 flex flex-wrap gap-6 text-sm">
            <span class="text-slate-600">
              <strong class="text-slate-900">{{ uebersicht.summary.courses }}</strong> Umläufe
            </span>
            <span class="text-slate-600">
              <strong class="text-slate-900">{{ uebersicht.summary.assigned_trips }}</strong> Fahrten zugeordnet
            </span>
            <span :class="uebersicht.summary.unassigned_trips > 0 ? 'text-amber-800' : 'text-emerald-700'">
              <strong>{{ uebersicht.summary.unassigned_trips }}</strong> ohne Kurs
            </span>
            <span v-if="uebersicht.summary.breaks > 0" class="text-amber-800">
              <strong>{{ uebersicht.summary.breaks }}</strong> gerissene Stellen
            </span>
          </div>

          <div class="mt-3 flex flex-wrap items-center gap-4">
            <div class="flex gap-1 rounded-lg bg-slate-200/60 p-1">
              <button
                v-for="sicht in [
                  { wert: 'kette' as const, text: 'Kette' },
                  { wert: 'tabelle' as const, text: 'Tabelle' },
                ]"
                :key="sicht.wert"
                type="button"
                class="rounded-md px-3 py-1 text-sm transition"
                :class="
                  ansicht === sicht.wert
                    ? 'bg-white font-medium text-slate-900 shadow-sm'
                    : 'text-slate-600 hover:bg-white/60'
                "
                @click="ansicht = sicht.wert"
              >
                {{ sicht.text }}
              </button>
            </div>

            <!-- Der Filter greift nur in die Ketten-Liste; die Tabelle zeigt alle Spalten. -->
            <label
              v-if="ansicht === 'kette' && uebersicht.summary.breaks > 0"
              class="flex items-center gap-1.5 text-xs text-slate-600"
            >
              <input v-model="nurUnvollstaendige" type="checkbox" />
              Nur Umläufe mit gerissener Kette
            </label>
          </div>

          <p v-if="ansicht === 'tabelle'" class="mt-2 max-w-3xl text-xs text-slate-500">
            Halte als Zeilen, ein Kurs je Spalte. In der Zelle steht die Abfahrt — am letzten Halt einer Fahrt die
            Ankunft. Die Achse folgt dem Umlauf: Nach der Endstelle geht es zurück, dieselbe Haltestelle steht
            deshalb mehrfach untereinander. Das ist die nächste Runde, keine Doppelung.
            <br />
            Die Spalten sind gegeneinander <strong>um ganze Umläufe verschoben</strong>, damit eine Taktzeile quer
            gelesen aufsteigt — Kurs 2 zeigt neben der ersten Runde von Kurs 1 also seine zweite. Auf dem Weg aus
            dem Betriebshof bleiben die Zeilen ungeordnet: Dort hat jedes Fahrzeug sein eigenes Muster.
          </p>

          <!-- Tabelle: alle Umläufe nebeneinander -->
          <template v-if="ansicht === 'tabelle'">
            <p v-if="grid === null" class="mt-6 text-sm text-slate-500">Tabelle wird geladen …</p>
            <CourseGridTable v-else :grid="grid" :lines="linienVerzeichnis" />
          </template>

          <!-- Kette: je Umlauf ein Abschnitt untereinander -->
          <template v-else>
            <p v-if="sichtbareKurse.length === 0" class="mt-6 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900">
              <template v-if="nurUnvollstaendige">
                Keine gerissene Kette — alle Umläufe dieser Linie hängen durchgehend zusammen.
              </template>
              <template v-else>
                Für diese Linie ist noch kein Umlauf vergeben. Die Ketten entstehen unter „Anschlüsse", die
                Kursnummern dort oder in der Fahrplan-Ansicht.
              </template>
            </p>

            <section v-for="kurs in sichtbareKurse" :key="kurs.id" class="mt-6 rounded-lg bg-white p-4 shadow-sm">
            <header class="flex flex-wrap items-center gap-3">
              <span class="rounded bg-slate-800 px-2 py-1 text-sm font-semibold tabular-nums text-white">
                {{ kurs.number }}
              </span>
              <span class="flex flex-wrap gap-1">
                <LineBadge v-for="l in kurs.lines" :key="l" :line="signet(l, 'tram')" size="sm" />
              </span>
              <span class="text-sm text-slate-600">
                {{ kurs.trip_count }} Fahrten · {{ formatClock(kurs.first_departure) }} bis
                {{ formatClock(kurs.last_arrival) }}
              </span>
              <span v-if="kurs.breaks > 0" class="rounded bg-amber-50 px-2 py-0.5 text-xs text-amber-900">
                {{ kurs.breaks }}× gerissen
              </span>
              <span v-if="kurs.duplicate" class="rounded bg-rose-50 px-2 py-0.5 text-xs text-rose-800">
                Nummer doppelt vergeben
              </span>
              <!-- Ausrück- und Einrückhof nebeneinander: Sie sind nicht zwangsläufig derselbe. -->
              <span
                v-if="hofSpanne(kurs)"
                class="rounded px-2 py-0.5 text-xs"
                :class="hofSpanne(kurs)!.verschieden ? 'bg-violet-100 font-medium text-violet-900' : 'bg-sky-50 text-sky-900'"
                :title="
                  hofSpanne(kurs)!.verschieden
                    ? 'Das Fahrzeug rückt aus einem anderen Hof aus, als es abends einrückt.'
                    : 'Aus- und Einrückhof sind derselbe.'
                "
              >
                Hof {{ hofSpanne(kurs)!.text }}
              </span>
            </header>

            <!-- Die Klammer um den Fahrzeugtag: Woher es kommt und wohin es abends geht. -->
            <p
              class="mt-3 rounded-md px-3 py-1 text-xs"
              :class="hofMarke(kurs.terminal_out, 'aus').klasse"
            >
              {{ hofMarke(kurs.terminal_out, 'aus').text }}
            </p>

            <ol class="mt-1 space-y-1">
              <li v-for="(trip, i) in kurs.trips" :key="trip.id">
                <!-- Der Abstand zur Vorfahrt: mit Anschluss eine Wende, ohne Anschluss ein Riss. -->
                <p v-if="i > 0" class="flex items-center gap-2 py-0.5 pl-4 text-xs">
                  <span
                    class="rounded px-1.5 py-0.5 tabular-nums"
                    :class="istRiss(trip, i) ? 'bg-amber-100 text-amber-900' : 'bg-slate-100 text-slate-600'"
                  >
                    {{ formatDuration(trip.gap_before_seconds) }}
                    {{ istRiss(trip, i) ? 'Lücke — kein Anschluss' : 'Wende' }}
                  </span>
                </p>

                <div class="flex items-center gap-3 rounded-md bg-slate-50 px-3 py-1.5">
                  <LineBadge :line="signet(trip.line, trip.mode)" size="sm" />
                  <span class="w-28 shrink-0 text-sm tabular-nums text-slate-900">
                    {{ formatClock(trip.departure_time) }} – {{ formatClock(trip.arrival_time) }}
                  </span>
                  <span class="min-w-0 flex-1 truncate text-sm text-slate-600">
                    {{ trip.start_stop ?? '—' }} → {{ trip.end_stop ?? '—' }}
                  </span>
                </div>
                </li>
              </ol>

            <p
              class="mt-1 rounded-md px-3 py-1 text-xs"
              :class="hofMarke(kurs.terminal_in, 'ein').klasse"
            >
              {{ hofMarke(kurs.terminal_in, 'ein').text }}
            </p>
            </section>
          </template>

          <!-- Fahrten ohne Kurs -->
          <section v-if="uebersicht.unassigned.length" class="mt-8">
            <h2 class="text-sm font-medium text-slate-900">
              Fahrten dieser Linie ohne Kurs
              <span class="font-normal text-slate-500">({{ uebersicht.unassigned.length }})</span>
            </h2>
            <ul class="mt-2 space-y-1">
              <li
                v-for="trip in uebersicht.unassigned"
                :key="trip.id"
                class="flex items-center gap-3 rounded-md border border-dashed border-slate-300 bg-white px-3 py-1.5"
              >
                <LineBadge :line="signet(trip.line, trip.mode)" size="sm" />
                <span class="w-28 shrink-0 text-sm tabular-nums text-slate-900">
                  {{ formatClock(trip.departure_time) }} – {{ formatClock(trip.arrival_time) }}
                </span>
                <span class="min-w-0 flex-1 truncate text-sm text-slate-600">
                  {{ trip.start_stop ?? '—' }} → {{ trip.end_stop ?? '—' }}
                </span>
              </li>
            </ul>
          </section>
        </template>
      </template>
    </main>
  </div>
</template>
