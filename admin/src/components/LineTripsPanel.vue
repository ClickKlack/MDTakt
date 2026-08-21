<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import {
  fetchLineTrips,
  FAHRPLAN_TYPEN,
  type FahrplanTyp,
  type LineTripItem,
  type LineTrips,
  type ScheduleSource,
} from '../services/lines'
import { formatClock, formatDate } from '../utils/timezone'

/**
 * Verkehrstage einer Fahrt. Ohne Wochenmuster verkehrt sie nur an Einzelterminen —
 * bis zu zwei werden ausgeschrieben, darüber gezählt (vollständig im Tooltip).
 */
function verkehrstage(trip: LineTripItem): string | null {
  if (trip.day_pattern) {
    return trip.day_pattern
  }
  const tage = trip.service_dates ?? []
  if (tage.length === 0) {
    return null
  }
  return tage.length <= 2
    ? `nur ${tage.map(formatDate).join(', ')}`
    : `nur ${tage.length} Einzeltage`
}

/** Vollständige Terminliste als Tooltip, wenn die Kurzform sie nicht alle zeigt. */
function verkehrstageDetail(trip: LineTripItem): string | undefined {
  const tage = trip.service_dates ?? []

  return !trip.day_pattern && tage.length > 2 ? tage.map(formatDate).join(', ') : undefined
}

/**
 * Im Konsolidat gibt es keine Wochenmuster — dort trägt die Fahrt ihre beobachtete
 * Gültigkeit. Ein „ab"/„bis" markiert eine Grenze, die an der Feed-Fensterkante lag und
 * damit nur eine Untergrenze ist (FAHRPLANPERIODEN §5.4 b).
 */
function gueltigkeit(trip: LineTripItem): string | null {
  const intervalle = trip.validity ?? []
  if (intervalle.length === 0) {
    return null
  }

  return intervalle
    .map((i) => {
      const von = `${i.from_confirmed ? '' : 'ab '}${formatDate(i.valid_from)}`
      const bis = `${i.to_confirmed ? '' : 'mind. '}${formatDate(i.valid_to)}`
      return `${von} – ${bis}`
    })
    .join(', ')
}

function gueltigkeitDetail(trip: LineTripItem): string | undefined {
  const offen = (trip.validity ?? []).some((i) => !i.from_confirmed || !i.to_confirmed)

  return offen
    ? 'Eine Grenze lag an der Feed-Fensterkante — sie ist nur eine Untergrenze, kein beobachteter Fahrplanwechsel.'
    : undefined
}

const props = defineProps<{ line: string; source?: ScheduleSource }>()

const data = ref<LineTrips | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)
const expanded = ref<Record<string, boolean>>({})
// null = alle Betriebstage nebeneinander (ungefiltert).
const dayType = ref<FahrplanTyp | null>(null)

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  expanded.value = {}
  try {
    data.value = await fetchLineTrips(props.line, dayType.value, props.source ?? 'consolidated')
  } catch {
    error.value = 'Fahrten konnten nicht geladen werden.'
  } finally {
    loading.value = false
  }
}

// Nur wenn eine Linienbezeichnung auf mehrere Routen zeigt (z. B. Schienenersatzverkehr
// als Bus unter derselben Nummer), ist das Verkehrsmittel eine nötige Unterscheidung.
const zeigeVerkehrsmittel = computed<boolean>(() => (data.value?.modes.length ?? 0) > 1)

const VERKEHRSMITTEL: Record<string, string> = { tram: 'Tram', bus: 'Bus', other: 'Sonstige' }

function toggle(key: string): void {
  expanded.value[key] = !expanded.value[key]
}

function select(typ: FahrplanTyp | null): void {
  dayType.value = typ
  void load()
}

const istKonsolidat = computed<boolean>(() => (props.source ?? 'consolidated') === 'consolidated')

// Linien- oder Quellenwechsel setzt den Filter zurück — sonst steht ein Typ ohne Abdeckung im Weg.
watch(
  () => [props.line, props.source],
  () => {
    dayType.value = null
    void load()
  },
  { immediate: true },
)
</script>

<template>
  <div>
    <!-- Fahrplantyp-Filter -->
    <div class="flex flex-wrap gap-1 rounded-lg bg-slate-200/60 p-1">
      <button
        class="rounded-md px-3 py-1.5 text-sm transition"
        :class="dayType === null ? 'bg-white font-medium text-slate-900 shadow-sm' : 'text-slate-600 hover:bg-white/60'"
        @click="select(null)"
      >
        Alle
      </button>
      <button
        v-for="typ in FAHRPLAN_TYPEN"
        :key="typ.value"
        class="rounded-md px-3 py-1.5 text-sm transition"
        :class="dayType === typ.value ? 'bg-white font-medium text-slate-900 shadow-sm' : 'text-slate-600 hover:bg-white/60'"
        @click="select(typ.value)"
      >
        {{ typ.label }}
      </button>
    </div>

    <div v-if="loading" class="mt-3 text-slate-500">Lädt…</div>
    <div v-else-if="error" class="mt-3 rounded-md bg-red-50 px-4 py-3 text-red-700">{{ error }}</div>

    <template v-else-if="data">
      <p class="mt-3 text-sm text-slate-500">
        {{ data.trip_count }} Fahrten · {{ data.groups.length }} Richtungen/Varianten
        <span v-if="data.reference_date" class="text-slate-400">
          · Stichtag {{ formatDate(data.reference_date) }}
        </span>
      </p>

      <!-- Ungefiltert stehen alle Betriebstage nebeneinander — Uhrzeiten wiederholen sich. -->
      <p v-if="data.day_type === null" class="mt-1 text-xs text-slate-400">
        Alle Betriebstage zusammen — dieselbe Abfahrtszeit kann mehrfach erscheinen (Spalte „Verkehrt").
      </p>

      <!-- Dieselbe Liniennummer auf mehreren Routen: Uhrzeiten wiederholen sich je Verkehrsmittel. -->
      <p v-if="zeigeVerkehrsmittel" class="mt-1 text-xs text-slate-500">
        Diese Linienbezeichnung wird von mehreren Routen geführt
        ({{ data.modes.map((m) => VERKEHRSMITTEL[m] ?? m).join(' und ') }}) — etwa bei
        Schienenersatzverkehr unter derselben Nummer. Siehe Spalte „Mittel“.
      </p>

      <!-- Typ ohne Abdeckung im Feed: kein Fehler, sondern Eigenschaft des Feed-Fensters. -->
      <div
        v-if="data.day_type !== null && data.trip_count === 0"
        class="mt-3 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800"
      >
        <template v-if="istKonsolidat">
          Für den Typ „{{ data.day_type_label }}“ ist bei dieser Linie noch nichts
          konsolidiert. Das Konsolidat wächst über viele Importe zusammen — solange kein
          Feed-Fenster einen Tag dieses Typs enthielt, gibt es dafür keinen Fahrplan.
          Der Reiter „Abdeckung“ zeigt, welche Zeiträume schon vorliegen.
        </template>
        <template v-else>
          Der aktuelle Feed enthält keinen Tag vom Typ „{{ data.day_type_label }}“.
          Der GTFS-Feed deckt nur ein rollierendes Zeitfenster ab — für diesen Fahrplantyp
          liegen darin keine Daten. Er füllt sich erst mit einem Import, dessen Zeitraum
          solche Tage enthält.
        </template>
      </div>

      <div class="mt-3 space-y-2">
        <div
          v-for="group in data.groups"
          :key="`${group.start_stop}→${group.end_stop}`"
          class="overflow-hidden rounded-lg bg-white shadow-sm"
        >
          <button
            class="flex w-full items-center justify-between px-4 py-3 text-left hover:bg-slate-50"
            @click="toggle(`${group.start_stop}→${group.end_stop}`)"
          >
            <span class="flex items-center gap-3">
              <span class="text-slate-400">{{ expanded[`${group.start_stop}→${group.end_stop}`] ? '▾' : '▸' }}</span>
              <span class="font-medium text-slate-900">{{ group.start_stop }} → {{ group.end_stop }}</span>
            </span>
            <span class="text-sm text-slate-500">
              {{ group.trip_count }} Fahrten
              <span class="text-slate-400">
                ({{ formatClock(group.trips[0]?.departure_time) }}–{{ formatClock(group.trips[group.trips.length - 1]?.departure_time) }})
              </span>
            </span>
          </button>

          <div
            v-if="expanded[`${group.start_stop}→${group.end_stop}`]"
            class="max-h-96 overflow-y-auto border-t border-slate-100"
          >
            <table class="w-full text-left text-sm">
              <thead class="sticky top-0 bg-slate-50 text-xs uppercase text-slate-500">
                <tr>
                  <th class="px-4 py-2">Abfahrt</th>
                  <th class="px-4 py-2">Ankunft</th>
                  <th class="px-4 py-2">{{ istKonsolidat ? 'Gültig' : 'Verkehrt' }}</th>
                  <th v-if="zeigeVerkehrsmittel" class="px-4 py-2">Mittel</th>
                  <th class="px-4 py-2">{{ istKonsolidat ? 'Version' : 'Dienst' }}</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="trip in group.trips"
                  :key="trip.trip_id ?? trip.id"
                  class="border-b border-slate-50 last:border-0"
                >
                  <td class="px-4 py-1.5 tabular-nums text-slate-700">{{ formatClock(trip.departure_time) }}</td>
                  <td class="px-4 py-1.5 tabular-nums text-slate-500">{{ formatClock(trip.arrival_time) }}</td>
                  <td class="px-4 py-1.5">
                    <template v-if="istKonsolidat">
                      <span
                        v-if="gueltigkeit(trip)"
                        class="rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600"
                        :title="gueltigkeitDetail(trip)"
                      >
                        {{ gueltigkeit(trip) }}
                      </span>
                      <span v-else class="text-xs text-slate-300">—</span>
                    </template>
                    <template v-else>
                      <span
                        v-if="verkehrstage(trip)"
                        class="rounded px-1.5 py-0.5 text-xs"
                        :class="trip.day_pattern ? 'bg-slate-100 text-slate-600' : 'bg-sky-50 text-sky-700'"
                        :title="verkehrstageDetail(trip)"
                      >
                        {{ verkehrstage(trip) }}
                      </span>
                      <span v-else class="text-xs text-slate-300">—</span>
                    </template>
                  </td>
                  <td v-if="zeigeVerkehrsmittel" class="px-4 py-1.5">
                    <span
                      class="rounded px-1.5 py-0.5 text-xs"
                      :class="trip.mode === 'tram' ? 'bg-emerald-50 text-emerald-700' : 'bg-orange-50 text-orange-700'"
                    >
                      {{ VERKEHRSMITTEL[trip.mode] ?? trip.mode }}
                    </span>
                  </td>
                  <td class="px-4 py-1.5 text-slate-400">
                    <span v-if="istKonsolidat" :title="trip.signature">V{{ trip.version_no }}</span>
                    <span v-else>{{ trip.service_id }}</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>
