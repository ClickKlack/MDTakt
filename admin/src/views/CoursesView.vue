<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppHeader from '../components/AppHeader.vue'
import LineBadge from '../components/LineBadge.vue'
import { fetchLineCourses, type CourseChainTrip, type LineCourseOverview } from '../services/courses'
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
    uebersicht.value = await fetchLineCourses(gewaehlteLinie.value, gewaehltePeriode.value, dayType.value)

    void router.replace({
      name: 'courses',
      query: { line: gewaehlteLinie.value, period: gewaehltePeriode.value, day_type: dayType.value },
    })
  } catch {
    uebersicht.value = null
    error.value = 'Die Umläufe konnten nicht geladen werden.'
  } finally {
    loadingKurse.value = false
  }
}

/**
 * Ein Abstand ist erst dann auffällig, wenn dort **kein** Anschluss steht — dann ist die Kette
 * gerissen. Mit Anschluss ist auch eine lange Wende in Ordnung.
 */
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

          <label v-if="uebersicht.summary.breaks > 0" class="mt-3 flex items-center gap-1.5 text-xs text-slate-600">
            <input v-model="nurUnvollstaendige" type="checkbox" />
            Nur Umläufe mit gerissener Kette
          </label>

          <p v-if="sichtbareKurse.length === 0" class="mt-6 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900">
            <template v-if="nurUnvollstaendige">
              Keine gerissene Kette — alle Umläufe dieser Linie hängen durchgehend zusammen.
            </template>
            <template v-else>
              Für diese Linie ist noch kein Umlauf vergeben. Die Ketten entstehen unter „Anschlüsse", die Kursnummern
              dort oder in der Fahrplan-Ansicht.
            </template>
          </p>

          <!-- Umläufe -->
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
            </header>

            <ol class="mt-3 space-y-1">
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
          </section>

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
