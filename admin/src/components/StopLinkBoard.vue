<script setup lang="ts">
import { computed } from 'vue'
import type { Line } from '../services/lines'
import type { StopLinkBoard, StopLinkTrip } from '../services/stopLinks'
import { formatClock, formatDuration } from '../utils/timezone'
import LineBadge from './LineBadge.vue'

const props = defineProps<{
  board: StopLinkBoard
  /** Liniensignete brauchen Farbe und Verkehrsmittel — beides kommt aus dem Linienverzeichnis. */
  lines: Record<string, Line>
  /** Die links ausgewählte endende Fahrt, die auf einen Anschluss wartet. */
  selected: number | null
  busy: boolean
}>()

const emit = defineEmits<{
  select: [tripId: number | null]
  link: [fromTripId: number, toTripId: number]
  mark: [tripId: number, kind: 'start' | 'end']
  unlink: [linkId: number]
}>()

/**
 * Eine Zeile der Gegenüberstellung. Verknüpfte Fahrten stehen **nebeneinander**, auch wenn
 * dadurch auf der Gegenseite eine Lücke entsteht — nur so ist auf einen Blick zu sehen, was
 * woran hängt. Ungepaarte Fahrten belegen ihre Seite allein.
 */
interface Zeile {
  key: string
  ending: StopLinkTrip | null
  starting: StopLinkTrip | null
  /** Betriebstag-Sortierschlüssel aus der Engine — nicht die angezeigte Uhrzeit. */
  sort: number
}

/** Fällt das Verzeichnis aus, trägt das Signet wenigstens die richtige Form. */
function signet(trip: StopLinkTrip): Line {
  return (
    props.lines[trip.line] ?? {
      route_short_name: trip.line,
      route_type: trip.mode === 'tram' ? 0 : 3,
      mode: trip.mode,
      modes: [trip.mode],
      route_ids: [],
      color: null,
    }
  )
}

const zeilen = computed<Zeile[]>(() => {
  const rows: Zeile[] = []
  const bereitsRechts = new Set<number>()

  for (const endet of props.board.ending) {
    const partnerId = endet.decision?.kind === 'link' ? (endet.decision.partner?.id ?? null) : null
    const partner = partnerId === null ? null : (props.board.starting.find((s) => s.id === partnerId) ?? null)

    if (partner) {
      bereitsRechts.add(partner.id)
    }

    rows.push({
      key: `e${endet.id}`,
      ending: endet,
      starting: partner,
      sort: endet.arrival_sort,
    })
  }

  for (const beginnt of props.board.starting) {
    if (bereitsRechts.has(beginnt.id)) {
      continue
    }
    rows.push({
      key: `s${beginnt.id}`,
      ending: null,
      starting: beginnt,
      sort: beginnt.departure_sort,
    })
  }

  return rows.sort((a, b) => a.sort - b.sort || a.key.localeCompare(b.key))
})

const offeneEnden = computed(() => props.board.ending.filter((f) => f.decision === null).length)
const offeneAnfaenge = computed(() => props.board.starting.filter((f) => f.decision === null).length)

/** Nur eine offene beginnende Fahrt kann einen Anschluss aufnehmen. */
function istZiel(trip: StopLinkTrip | null): boolean {
  return trip !== null && props.selected !== null && trip.decision === null
}

function klickEndend(trip: StopLinkTrip): void {
  if (trip.decision !== null) {
    return
  }
  emit('select', props.selected === trip.id ? null : trip.id)
}

function klickBeginnend(trip: StopLinkTrip): void {
  if (!istZiel(trip) || props.selected === null) {
    return
  }
  emit('link', props.selected, trip.id)
}

function kurz(wendezeit: number | null): boolean {
  return wendezeit !== null && wendezeit < 180
}
</script>

<template>
  <div class="mt-6">
    <div class="grid grid-cols-2 gap-4 border-b border-slate-200 pb-1">
      <h2 class="flex items-baseline justify-between text-sm font-medium text-slate-900">
        Endet hier
        <span class="text-xs font-normal text-slate-500">
          {{ board.ending.length }} Fahrten · {{ offeneEnden }} offen
        </span>
      </h2>
      <h2 class="flex items-baseline justify-between text-sm font-medium text-slate-900">
        Beginnt hier
        <span class="text-xs font-normal text-slate-500">
          {{ board.starting.length }} Fahrten · {{ offeneAnfaenge }} offen
        </span>
      </h2>
    </div>

    <p
      v-if="zeilen.length === 0"
      class="mt-3 rounded-lg bg-slate-50 px-4 py-3 text-sm text-slate-600"
    >
      An dieser Haltestelle beginnt und endet in diesem Versionsstand keine Fahrt.
    </p>

    <ul v-else class="mt-1 divide-y divide-slate-100">
      <li v-for="zeile in zeilen" :key="zeile.key" class="grid grid-cols-2 items-stretch gap-4 py-1">
        <!-- Linke Seite: endende Fahrt -->
        <div
          v-if="zeile.ending"
          class="rounded-lg border px-3 py-2 transition"
          :class="[
            zeile.ending.decision === null
              ? 'cursor-pointer border-slate-200 bg-white hover:border-slate-400'
              : 'border-slate-100 bg-white',
            selected === zeile.ending.id ? 'border-slate-800 ring-2 ring-slate-800' : '',
          ]"
          @click="klickEndend(zeile.ending)"
        >
          <div class="flex items-center gap-2">
            <LineBadge :line="signet(zeile.ending)" size="sm" />
            <!-- Der Kurs ist die eigentliche Auskunft dieser Ansicht: Er sagt, zu welchem
                 Umlauf die Fahrt gehoert. Deshalb steht er direkt neben dem Signet. -->
            <span
              v-if="zeile.ending.course"
              class="rounded bg-slate-800 px-1.5 py-0.5 text-xs font-semibold tabular-nums text-white"
              :title="`Kurs ${zeile.ending.course.number}`"
            >
              {{ zeile.ending.course.display }}
            </span>
            <span v-else class="rounded border border-dashed border-slate-300 px-1.5 py-0.5 text-xs text-slate-400">
              ohne Kurs
            </span>
            <span class="ml-auto shrink-0 text-sm tabular-nums font-medium text-slate-900">
              {{ formatClock(zeile.ending.arrival_time) }}
            </span>
          </div>
          <div class="mt-1 flex items-center gap-2 text-xs">
            <span class="min-w-0 flex-1 truncate text-slate-600">aus {{ zeile.ending.start_stop ?? '—' }}</span>
            <button
              v-if="zeile.ending.decision === null"
              type="button"
              class="shrink-0 text-slate-500 underline underline-offset-2 hover:text-slate-900 disabled:opacity-50"
              :disabled="busy"
              @click.stop="emit('mark', zeile.ending.id, 'end')"
            >
              Einrücken
            </button>
            <span v-else-if="zeile.ending.decision.kind === 'end'" class="shrink-0 rounded bg-sky-50 px-1.5 text-sky-900">
              Einrücken
            </span>
          </div>
        </div>
        <div v-else />

        <!-- Rechte Seite: beginnende Fahrt -->
        <div
          v-if="zeile.starting"
          class="rounded-lg border px-3 py-2 transition"
          :class="[
            istZiel(zeile.starting)
              ? 'cursor-pointer border-emerald-400 bg-emerald-50/40 hover:border-emerald-600'
              : 'border-slate-100 bg-white',
          ]"
          @click="klickBeginnend(zeile.starting)"
        >
          <div class="flex items-center gap-2">
            <span class="shrink-0 text-sm tabular-nums font-medium text-slate-900">
              {{ formatClock(zeile.starting.departure_time) }}
            </span>
            <LineBadge :line="signet(zeile.starting)" size="sm" />
            <span
              v-if="zeile.starting.course"
              class="rounded bg-slate-800 px-1.5 py-0.5 text-xs font-semibold tabular-nums text-white"
              :title="`Kurs ${zeile.starting.course.number}`"
            >
              {{ zeile.starting.course.display }}
            </span>
            <span v-else class="rounded border border-dashed border-slate-300 px-1.5 py-0.5 text-xs text-slate-400">
              ohne Kurs
            </span>
          </div>
          <div class="mt-1 flex items-center gap-2 text-xs">
            <span class="min-w-0 flex-1 truncate text-slate-600">nach {{ zeile.starting.end_stop ?? '—' }}</span>
            <button
              v-if="zeile.starting.decision === null"
              type="button"
              class="shrink-0 text-slate-500 underline underline-offset-2 hover:text-slate-900 disabled:opacity-50"
              :disabled="busy"
              @click.stop="emit('mark', zeile.starting.id, 'start')"
            >
              Ausrücken
            </button>
            <span
              v-else-if="zeile.starting.decision.kind === 'start'"
              class="shrink-0 rounded bg-sky-50 px-1.5 text-sky-900"
            >
              Ausrücken
            </span>
          </div>
        </div>
        <div v-else />

        <!-- Die Verbindung selbst, ueber beide Spalten: Wendezeit und Loesen -->
        <div
          v-if="zeile.ending && zeile.starting && zeile.ending.decision?.kind === 'link'"
          class="col-span-2 -mt-0.5 flex items-center justify-center gap-2 text-xs"
        >
          <span class="h-px flex-1 bg-slate-200" />
          <span
            class="rounded-full px-2 py-0.5 tabular-nums"
            :class="
              kurz(zeile.ending.decision.turnaround_seconds)
                ? 'bg-amber-100 text-amber-900'
                : 'bg-slate-100 text-slate-600'
            "
          >
            {{ formatDuration(zeile.ending.decision.turnaround_seconds) }} Wende
          </span>
          <span v-if="zeile.ending.line !== zeile.starting.line" class="rounded-full bg-violet-100 px-2 py-0.5 text-violet-900">
            Linienwechsel
          </span>
          <button
            type="button"
            class="text-slate-500 underline underline-offset-2 hover:text-slate-900 disabled:opacity-50"
            :disabled="busy"
            @click="emit('unlink', zeile.ending.decision.id)"
          >
            Lösen
          </button>
          <span class="h-px flex-1 bg-slate-200" />
        </div>
      </li>
    </ul>
  </div>
</template>
