<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppHeader from '../components/AppHeader.vue'
import LineBadge from '../components/LineBadge.vue'
import { fetchLines, type Line } from '../services/lines'
import {
  acceptQuestion,
  acceptSightings,
  fetchSightings,
  rejectSightings,
  SIGHTING_STATES,
  type Sighting,
  type SightingPage,
  type SightingState,
} from '../services/sightings'
import { formatClock, formatDate, formatDateTime, formatTime } from '../utils/timezone'
import { lineSortKey, lineTypeOrder } from '../utils/lineStyle'

const PER_PAGE = 50

const route = useRoute()
const router = useRouter()

const lines = ref<Line[]>([])
const result = ref<SightingPage | null>(null)
const loading = ref(true)
const busy = ref(false)
const error = ref<string | null>(null)
const hinweis = ref<string | null>(null)

// Filter aus der URL, damit eine gefilterte Liste verlinkbar bleibt.
const state = ref<SightingState>((route.query.state as SightingState) ?? 'open')
const line = ref<string>((route.query.line as string) ?? '')
const dateFrom = ref<string>((route.query.from as string) ?? '')
const dateTo = ref<string>((route.query.to as string) ?? '')
const differsOnly = ref<boolean>(route.query.differs === '1')
const page = ref(Number(route.query.page) || 1)

const lineByName = computed(() => new Map(lines.value.map((l) => [l.route_short_name, l])))

onMounted(async () => {
  lines.value = (await fetchLines().catch(() => []))
    .slice()
    .sort((a, b) => lineTypeOrder(a) - lineTypeOrder(b) || lineSortKey(a) - lineSortKey(b))
  await load()
})

async function load(): Promise<void> {
  loading.value = true
  error.value = null

  void router.replace({
    name: 'sightings',
    query: {
      state: state.value !== 'open' ? state.value : undefined,
      line: line.value || undefined,
      from: dateFrom.value || undefined,
      to: dateTo.value || undefined,
      differs: differsOnly.value ? '1' : undefined,
      page: page.value > 1 ? String(page.value) : undefined,
    },
  })

  try {
    result.value = await fetchSightings(
      {
        state: state.value,
        line: line.value,
        date_from: dateFrom.value,
        date_to: dateTo.value,
        differs_only: differsOnly.value,
      },
      page.value,
      PER_PAGE,
    )
  } catch {
    error.value = 'Sichtungen konnten nicht geladen werden.'
  } finally {
    loading.value = false
  }
}

function filtern(): void {
  page.value = 1
  void load()
}

function goTo(target: number): void {
  page.value = target
  void load()
}

function meldung(e: unknown, fallback: string): string {
  const antwort = (e as { response?: { data?: { error?: { message?: string } } } }).response
  return antwort?.data?.error?.message ?? fallback
}

async function annehmen(s: Sighting): Promise<void> {
  const frage = acceptQuestion(s)
  if (frage !== null && !confirm(frage)) {
    return
  }

  busy.value = true
  error.value = null
  hinweis.value = null

  try {
    const r = await acceptSightings([s.id])
    hinweis.value =
      `Kurs ${s.trip?.line}/${s.course_number} gilt jetzt für ${r.trips_assigned} Fahrt(en).` +
      (r.confirmed_others > 0 ? ` ${r.confirmed_others} weitere Sichtung(en) dadurch bestätigt.` : '')
    await load()
  } catch (e) {
    error.value = meldung(e, 'Annehmen fehlgeschlagen.')
  } finally {
    busy.value = false
  }
}

async function ablehnen(s: Sighting): Promise<void> {
  // Die Notiz ist freiwillig; Abbrechen bricht das Ablehnen ab.
  const notiz = prompt(`Sichtung ${s.display} ablehnen. Notiz (optional):`, '')
  if (notiz === null) {
    return
  }

  busy.value = true
  error.value = null
  hinweis.value = null

  try {
    await rejectSightings([s.id], notiz)
    await load()
  } catch (e) {
    error.value = meldung(e, 'Ablehnen fehlgeschlagen.')
  } finally {
    busy.value = false
  }
}

/** Link auf die Fahrt im Fahrplan — dort lässt sich die Richtigkeit am besten beurteilen. */
function fahrplanLink(s: Sighting) {
  if (!s.trip) {
    return null
  }
  return {
    name: 'timetable',
    query: {
      line: s.trip.line,
      day_type: s.trip.day_type ?? undefined,
      version: String(s.trip.line_version_id),
      period: s.trip.period_id ? String(s.trip.period_id) : undefined,
      trip: String(s.trip.id),
    },
  }
}

/** Farbe der Zeile: rot = weicht ab, gelb = lokal kein Kurs, grau = keine Fahrt. */
function zeilenKlasse(s: Sighting): string {
  if (s.status !== 'pending') {
    return ''
  }
  switch (s.comparison) {
    case 'differs':
      return 'bg-red-50'
    case 'none':
      return 'bg-amber-50'
    case 'no_trip':
      return 'bg-slate-50 text-slate-500'
    default:
      return ''
  }
}

const matchLabel: Record<Sighting['match'], string> = {
  matched: '',
  matched_next_version: 'aus Folgeversion',
  waiting: 'wartet auf Fahrplan',
  no_trip: 'keine Fahrt gefunden',
  ambiguous: 'mehrdeutig',
}

const statusClass: Record<Sighting['status'], string> = {
  pending: 'bg-slate-100 text-slate-700',
  confirmed: 'bg-green-100 text-green-800',
  accepted: 'bg-emerald-100 text-emerald-800',
  rejected: 'bg-slate-200 text-slate-600',
}

function kannAnnehmen(s: Sighting): boolean {
  return s.status === 'pending' && s.trip !== null
}
</script>

<template>
  <div class="min-h-full bg-slate-100">
    <AppHeader />

    <main class="mx-auto max-w-7xl space-y-4 px-6 py-8">
      <div class="flex items-baseline justify-between">
        <h1 class="text-lg font-semibold text-slate-900">Sichtungen aus MDKursTracker</h1>
        <span v-if="result" class="text-sm text-slate-500">{{ result.meta.total }} Treffer</span>
      </div>

      <!-- Filterleiste -->
      <form class="flex flex-wrap items-end gap-3 rounded-lg bg-white p-4 text-sm shadow-sm" @submit.prevent="filtern">
        <label class="flex flex-col gap-1">
          <span class="text-xs font-medium uppercase text-slate-500">Status</span>
          <select v-model="state" class="rounded-md border border-slate-300 px-2 py-1.5" @change="filtern">
            <option v-for="s in SIGHTING_STATES" :key="s.value" :value="s.value">{{ s.label }}</option>
          </select>
        </label>
        <label class="flex flex-col gap-1">
          <span class="text-xs font-medium uppercase text-slate-500">Linie</span>
          <select v-model="line" class="rounded-md border border-slate-300 px-2 py-1.5" @change="filtern">
            <option value="">alle</option>
            <option v-for="l in lines" :key="l.route_short_name" :value="l.route_short_name">
              {{ l.route_short_name }}
            </option>
          </select>
        </label>
        <label class="flex flex-col gap-1">
          <span class="text-xs font-medium uppercase text-slate-500">Betriebstag von</span>
          <input v-model="dateFrom" type="date" class="rounded-md border border-slate-300 px-2 py-1" @change="filtern" />
        </label>
        <label class="flex flex-col gap-1">
          <span class="text-xs font-medium uppercase text-slate-500">bis</span>
          <input v-model="dateTo" type="date" class="rounded-md border border-slate-300 px-2 py-1" @change="filtern" />
        </label>
        <label class="flex items-center gap-2 pb-1.5">
          <input v-model="differsOnly" type="checkbox" @change="filtern" />
          <span>nur Abweichungen</span>
        </label>
        <div class="ml-auto flex items-center gap-3 pb-1 text-xs text-slate-500">
          <span class="inline-flex items-center gap-1"><span class="h-3 w-3 rounded-sm bg-red-200" /> weicht ab</span>
          <span class="inline-flex items-center gap-1"><span class="h-3 w-3 rounded-sm bg-amber-200" /> lokal kein Kurs</span>
          <span class="inline-flex items-center gap-1"><span class="h-3 w-3 rounded-sm bg-slate-300" /> keine Fahrt</span>
        </div>
      </form>

      <div v-if="error" class="rounded-md bg-red-50 px-4 py-3 text-red-700">{{ error }}</div>
      <div v-if="hinweis" class="rounded-md bg-emerald-50 px-4 py-3 text-emerald-800">{{ hinweis }}</div>

      <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
        <table class="w-full text-left text-sm">
          <thead class="border-b border-slate-200 text-xs uppercase text-slate-500">
            <tr>
              <th class="px-3 py-2">Betriebstag</th>
              <th class="px-3 py-2">Soll-Abfahrt</th>
              <th class="px-3 py-2">Linie</th>
              <th class="px-3 py-2">Halt</th>
              <th class="px-3 py-2">Gesichtet</th>
              <th class="px-3 py-2">Lokal</th>
              <th class="px-3 py-2">Fahrt</th>
              <th class="px-3 py-2">Status</th>
              <th class="px-3 py-2 text-right">Aktion</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="s in result?.items ?? []"
              :key="s.id"
              class="border-b border-slate-100 last:border-0"
              :class="zeilenKlasse(s)"
            >
              <td class="whitespace-nowrap px-3 py-2">{{ formatDate(s.service_date) }}</td>
              <td class="whitespace-nowrap px-3 py-2 tabular-nums" :title="`erfasst ${formatDateTime(s.observed_at)}`">
                {{ formatTime(s.departure_planned) }}
                <span v-if="s.departure_actual" class="text-xs text-slate-400">(ist {{ formatTime(s.departure_actual) }})</span>
              </td>
              <td class="px-3 py-2">
                <LineBadge v-if="lineByName.get(s.line)" :line="lineByName.get(s.line)!" size="sm" />
                <span v-else>{{ s.line }}</span>
              </td>
              <td class="px-3 py-2" :title="`HAFAS ${s.hafas_stop_id}`">{{ s.stop_name ?? s.hafas_stop_id }}</td>
              <td class="px-3 py-2">
                <span
                  class="rounded px-1.5 py-0.5 font-semibold tabular-nums"
                  :class="s.comparison === 'differs' ? 'bg-red-600 text-white' : 'bg-slate-800 text-white'"
                >
                  {{ s.display }}
                </span>
              </td>
              <td class="px-3 py-2 tabular-nums">
                <span v-if="s.local_course" :class="s.comparison === 'differs' ? 'font-semibold text-red-700 line-through' : ''">
                  {{ s.local_course.display }}
                </span>
                <span v-else-if="s.trip" class="text-amber-700">kein Kurs</span>
                <span v-else class="text-slate-400">—</span>
              </td>
              <td class="px-3 py-2">
                <template v-if="s.trip">
                  <RouterLink :to="fahrplanLink(s)!" class="text-slate-700 underline decoration-slate-300 hover:text-slate-900">
                    {{ formatClock(s.trip.departure_time) }} {{ s.trip.start_stop }} → {{ s.trip.end_stop }}
                  </RouterLink>
                  <span class="ml-1 text-xs text-slate-400">V{{ s.trip.version_no }}</span>
                </template>
                <span
                  v-if="matchLabel[s.match]"
                  class="ml-1 rounded bg-slate-200 px-1.5 py-0.5 text-xs text-slate-700"
                  :class="{ 'bg-violet-100 text-violet-800': s.match === 'matched_next_version' }"
                  :title="s.match === 'waiting' || s.match === 'no_trip' ? `${s.match_attempts} Import(e) ohne Treffer` : ''"
                >
                  {{ matchLabel[s.match] }}
                </span>
              </td>
              <td class="px-3 py-2">
                <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="statusClass[s.status]">
                  {{ s.status_label }}
                </span>
                <div v-if="s.decision_note" class="mt-0.5 text-xs text-slate-500">{{ s.decision_note }}</div>
              </td>
              <td class="whitespace-nowrap px-3 py-2 text-right">
                <template v-if="s.status === 'pending'">
                  <button
                    v-if="kannAnnehmen(s)"
                    class="mr-1 rounded-md bg-emerald-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-emerald-700 disabled:opacity-50"
                    :disabled="busy"
                    :title="s.chain_trip_count ? `setzt ${s.trip?.line}/${s.course_number} an ${s.chain_trip_count} Fahrt(en) der Kette` : ''"
                    @click="annehmen(s)"
                  >
                    ✓ Annehmen
                  </button>
                  <button
                    class="rounded-md border border-slate-300 bg-white px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50"
                    :disabled="busy"
                    @click="ablehnen(s)"
                  >
                    ✗ Ablehnen
                  </button>
                </template>
              </td>
            </tr>
            <tr v-if="!loading && (result?.items.length ?? 0) === 0">
              <td colspan="9" class="px-4 py-6 text-center text-slate-400">Keine Sichtungen für diesen Filter.</td>
            </tr>
            <tr v-if="loading && !result">
              <td colspan="9" class="px-4 py-6 text-center text-slate-400">Lädt…</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="result && result.meta.last_page > 1" class="flex items-center justify-between text-sm text-slate-600">
        <span>Seite {{ result.meta.current_page }} von {{ result.meta.last_page }}</span>
        <div class="flex gap-2">
          <button
            class="rounded-md border border-slate-300 bg-white px-3 py-1.5 font-medium hover:bg-slate-50 disabled:opacity-40"
            :disabled="result.meta.current_page <= 1 || loading"
            @click="goTo(result.meta.current_page - 1)"
          >
            Zurück
          </button>
          <button
            class="rounded-md border border-slate-300 bg-white px-3 py-1.5 font-medium hover:bg-slate-50 disabled:opacity-40"
            :disabled="result.meta.current_page >= result.meta.last_page || loading"
            @click="goTo(result.meta.current_page + 1)"
          >
            Weiter
          </button>
        </div>
      </div>
    </main>
  </div>
</template>
