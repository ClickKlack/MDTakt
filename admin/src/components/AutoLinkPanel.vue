<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import type { Line } from '../services/lines'
import {
  applyAutoLinks,
  previewAutoLinks,
  type AutoLinkAction,
  type AutoLinkParams,
  type AutoLinkResult,
  type AutoLinkSkip,
  type StopLinkTrip,
} from '../services/stopLinks'
import { formatClock, formatDuration } from '../utils/timezone'
import LineBadge from './LineBadge.vue'

const props = defineProps<{
  /**
   * Alles, was den Ausschnitt beschreibt — Haltestelle, Periode, Fahrplantyp, Stand, Filter und
   * die beiden Markierungen. `null`, solange der Bereich unvollstaendig ist.
   */
  params: AutoLinkParams | null
  lines: Record<string, Line>
  busy: boolean
}>()

const emit = defineEmits<{
  /** Es wurde geschrieben — die View laedt das Board neu und meldet das Ergebnis. */
  done: [ergebnis: AutoLinkResult]
  fehler: [meldung: string]
}>()

const aktion = ref<AutoLinkAction>('link')
const mindestwende = ref(3)
const hoechstwende = ref(20)
const auchBetriebsfahrten = ref(false)

const vorschau = ref<AutoLinkResult | null>(null)
const laeuft = ref(false)

/**
 * Ein Reiterwechsel verwirft die Vorschau. Sonst stuende die Vorschau der einen Richtung ueber
 * dem Knopf der anderen — und ein Klick taete etwas anderes, als daneben steht.
 */
watch(aktion, () => {
  vorschau.value = null
})

// Eine neue Markierung macht die alte Vorschau ungueltig.
watch(
  () => [props.params?.from_trip_id, props.params?.to_trip_id, props.params?.lines?.join(','), props.params?.mode],
  () => {
    vorschau.value = null
  },
)

const eingaben = computed<AutoLinkParams | null>(() => {
  if (props.params === null) {
    return null
  }

  return aktion.value === 'link'
    ? {
        ...props.params,
        action: 'link',
        min_turnaround_minutes: mindestwende.value,
        max_turnaround_minutes: hoechstwende.value,
      }
    : { ...props.params, action: 'unlink', include_terminals: auchBetriebsfahrten.value }
})

const gesperrt = computed(() => props.busy || laeuft.value || eingaben.value === null)

/** Die Zahl auf dem ausfuehrenden Knopf — sie muss benennen, was gleich geschieht. */
const anzahl = computed(() => (aktion.value === 'link' ? vorschau.value?.pairs.length : vorschau.value?.removals.length) ?? 0)

function meldung(e: unknown, fallback: string): string {
  const antwort = (e as { response?: { data?: { error?: { message?: string } } } }).response
  return antwort?.data?.error?.message ?? fallback
}

async function hole(): Promise<void> {
  const eingabe = eingaben.value

  if (eingabe === null) {
    return
  }

  laeuft.value = true

  try {
    vorschau.value = await previewAutoLinks(eingabe)

    // Die Engine kennt die konfigurierten Vorgaben — das Feld uebernimmt sie beim ersten Mal,
    // damit die Schwelle nur an einer Stelle steht.
    if (!beruehrt.value) {
      mindestwende.value = Math.round(vorschau.value.defaults.min_turnaround_seconds / 60)
      hoechstwende.value = Math.round(vorschau.value.defaults.max_turnaround_seconds / 60)
      beruehrt.value = true
    }
  } catch (e: unknown) {
    vorschau.value = null
    emit('fehler', meldung(e, 'Die Vorschau konnte nicht geladen werden.'))
  } finally {
    laeuft.value = false
  }
}

const beruehrt = ref(false)

async function fuehreAus(): Promise<void> {
  const eingabe = eingaben.value

  if (eingabe === null) {
    return
  }

  laeuft.value = true

  try {
    const ergebnis = await applyAutoLinks(eingabe)
    vorschau.value = null
    emit('done', ergebnis)
  } catch (e: unknown) {
    emit('fehler', meldung(e, 'Der Lauf konnte nicht ausgeführt werden.'))
  } finally {
    laeuft.value = false
  }
}

/** Faellt das Verzeichnis aus, traegt das Signet wenigstens die richtige Form. */
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

/**
 * Die Zeit, unter der eine uebersprungene Fahrt hier wiederzufinden ist.
 *
 * Sie haengt an der **betroffenen Seite**: Bei einer endenden Fahrt ist es ihre Ankunft, bei
 * einer beginnenden ihre Abfahrt. Eine beginnende Fahrt traegt zwar auch eine Ankunft — die am
 * anderen Ende ihres Laufs, und die steht an dieser Haltestelle nirgends. Wer sie zeigte, naennte
 * eine Uhrzeit, die der Pflegende im Board vergeblich sucht.
 */
function zeitDerSeite(zeile: AutoLinkSkip): string {
  return formatClock(zeile.side === 'starting' ? zeile.trip.departure_time : zeile.trip.arrival_time)
}

/** Gleiche Gruende zusammenfassen — zwoelf gleichlautende Zeilen sagen nicht mehr als eine. */
const uebersprungen = computed(() => {
  const gruppen = new Map<string, { reason: string; zeiten: string[] }>()

  for (const zeile of vorschau.value?.skipped ?? []) {
    const eintrag = gruppen.get(zeile.reason_code) ?? { reason: zeile.reason, zeiten: [] }
    eintrag.zeiten.push(zeitDerSeite(zeile))
    gruppen.set(zeile.reason_code, eintrag)
  }

  return [...gruppen.entries()].map(([code, eintrag]) => ({ code, ...eintrag }))
})
</script>

<template>
  <div class="mt-4 rounded-lg border border-slate-300 bg-white p-4 shadow-sm">
    <div class="flex flex-wrap items-center gap-3">
      <div class="flex gap-1 rounded-lg bg-slate-200/60 p-1">
        <button
          v-for="reiter in [
            { wert: 'link' as const, text: 'Verknüpfen' },
            { wert: 'unlink' as const, text: 'Auflösen' },
          ]"
          :key="reiter.wert"
          type="button"
          class="rounded-md px-3 py-1 text-sm transition"
          :class="
            aktion === reiter.wert
              ? 'bg-white font-medium text-slate-900 shadow-sm'
              : 'text-slate-600 hover:bg-white/60'
          "
          @click="aktion = reiter.wert"
        >
          {{ reiter.text }}
        </button>
      </div>

      <p v-if="params === null" class="text-sm text-amber-800">
        Klicke links die Fahrt an, ab der begonnen werden soll — und danach die letzte.
      </p>
      <p v-else class="text-sm text-slate-600">
        <template v-if="params.lines?.length">Linie {{ params.lines.join(' und ') }} · </template>
        Bereich markiert
      </p>
    </div>

    <!-- Die Grenzen des Laufs. Beim Verknuepfen die Wendezeit, beim Aufloesen die Frage,
         ob Betriebsfahrten mitgehen. -->
    <div v-if="aktion === 'link'" class="mt-3 flex flex-wrap items-end gap-4">
      <label class="block">
        <span class="block text-xs font-medium uppercase tracking-wide text-slate-500">Mindestwende</span>
        <span class="mt-1 flex items-baseline gap-1">
          <input
            v-model.number="mindestwende"
            type="number"
            min="0"
            max="600"
            class="w-20 rounded-md border border-slate-300 px-2 py-1 text-sm tabular-nums focus:border-slate-800 focus:outline-none"
          />
          <span class="text-xs text-slate-500">Min</span>
        </span>
      </label>
      <label class="block">
        <span class="block text-xs font-medium uppercase tracking-wide text-slate-500">Höchstwende</span>
        <span class="mt-1 flex items-baseline gap-1">
          <input
            v-model.number="hoechstwende"
            type="number"
            min="1"
            max="600"
            class="w-20 rounded-md border border-slate-300 px-2 py-1 text-sm tabular-nums focus:border-slate-800 focus:outline-none"
          />
          <span class="text-xs text-slate-500">Min</span>
        </span>
      </label>
      <p class="max-w-md text-xs text-slate-500">
        Jede Ankunft bekommt die früheste noch freie Abfahrt in diesem Fenster. Reicht keine heran, bleibt die Fahrt
        offen — sonst griffe der Lauf über eine Taktlücke hinweg nach dem falschen Fahrzeug.
      </p>
    </div>

    <div v-else class="mt-3">
      <label class="flex items-start gap-2 text-sm text-slate-700">
        <input v-model="auchBetriebsfahrten" type="checkbox" class="mt-0.5" />
        <span>
          Auch Aus- und Einrücken lösen
          <span class="block text-xs text-slate-500">
            Ohne Haken bleiben Betriebsfahrten stehen — sie sind eine eigenständige Aussage und sollen nicht
            nebenbei verschwinden.
          </span>
        </span>
      </label>
      <p class="mt-2 max-w-2xl rounded-md bg-slate-50 px-3 py-2 text-xs text-slate-600">
        Die Kursnummern bleiben dabei erhalten, und zwar an <strong>beiden</strong> Hälften der zerschnittenen Kette.
        Wer auch die Nummer loswerden will, nutzt „Kursnummern entfernen“ im Fahrplan.
      </p>
    </div>

    <div class="mt-3 flex flex-wrap items-center gap-2">
      <button
        type="button"
        class="rounded-md border border-slate-800 px-3 py-1.5 text-sm font-medium text-slate-800 transition hover:bg-slate-800 hover:text-white disabled:opacity-40"
        :disabled="gesperrt"
        @click="hole"
      >
        Vorschau
      </button>

      <button
        v-if="vorschau"
        type="button"
        class="rounded-md px-3 py-1.5 text-sm font-medium text-white transition disabled:opacity-40"
        :class="aktion === 'link' ? 'bg-slate-800 hover:bg-slate-700' : 'bg-rose-700 hover:bg-rose-600'"
        :disabled="gesperrt || anzahl === 0"
        @click="fuehreAus"
      >
        {{ aktion === 'link' ? `${anzahl} Anschlüsse anlegen` : `${anzahl} Anschlüsse auflösen` }}
      </button>

      <span v-if="laeuft" class="text-sm text-slate-500">Wird gerechnet …</span>
    </div>

    <!-- Die Vorschau selbst -->
    <template v-if="vorschau">
      <p class="mt-3 text-sm text-slate-700">
        {{ vorschau.summary.candidates }} Fahrten im Bereich ·
        <strong>{{ anzahl }}</strong> {{ aktion === 'link' ? 'Übergänge' : 'Auflösungen' }} ·
        {{ vorschau.summary.skipped }} übersprungen
      </p>

      <p v-if="anzahl === 0" class="mt-2 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-900">
        Hier gibt es nichts zu tun — siehe die Begründungen unten.
      </p>

      <ul v-else class="mt-2 divide-y divide-slate-100 rounded-md border border-slate-200">
        <li
          v-for="paar in vorschau.pairs"
          :key="`p${paar.from_trip.id}`"
          class="flex flex-wrap items-center gap-2 px-3 py-1.5 text-sm"
        >
          <LineBadge :line="signet(paar.from_trip)" size="sm" />
          <span class="tabular-nums">{{ formatClock(paar.from_trip.arrival_time) }}</span>
          <span class="text-slate-400">→</span>
          <span class="tabular-nums">{{ formatClock(paar.to_trip.departure_time) }}</span>
          <LineBadge :line="signet(paar.to_trip)" size="sm" />
          <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs tabular-nums text-slate-600">
            {{ formatDuration(paar.turnaround_seconds) }} Wende
          </span>
          <span
            v-for="hinweis in paar.warnings"
            :key="hinweis.code"
            class="rounded-full px-2 py-0.5 text-xs"
            :class="hinweis.code === 'line_change' ? 'bg-violet-100 text-violet-900' : 'bg-amber-100 text-amber-900'"
            :title="hinweis.message"
          >
            {{ hinweis.code === 'line_change' ? 'Linienwechsel' : 'knappe Wende' }}
          </span>
        </li>

        <li
          v-for="zeile in vorschau.removals"
          :key="`r${zeile.trip_link_id}`"
          class="flex flex-wrap items-center gap-2 px-3 py-1.5 text-sm"
        >
          <template v-if="zeile.from_trip">
            <LineBadge :line="signet(zeile.from_trip)" size="sm" />
            <span class="tabular-nums">{{ formatClock(zeile.from_trip.arrival_time) }}</span>
          </template>
          <span class="text-slate-400">{{ zeile.kind === 'link' ? '→' : '·' }}</span>
          <template v-if="zeile.to_trip">
            <span class="tabular-nums">{{ formatClock(zeile.to_trip.departure_time) }}</span>
            <LineBadge :line="signet(zeile.to_trip)" size="sm" />
          </template>
          <span v-if="zeile.kind !== 'link'" class="rounded-full bg-sky-50 px-2 py-0.5 text-xs text-sky-900">
            {{ zeile.kind === 'end' ? 'Einrücken' : 'Ausrücken' }}
          </span>
          <span
            v-if="zeile.partner_outside_range"
            class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600"
            title="Die Gegenfahrt liegt außerhalb des markierten Bereichs — gelöst wird trotzdem."
          >
            Partner außerhalb
          </span>
        </li>
      </ul>

      <!-- Was nicht geschieht, und warum. Gleiche Gruende zusammengefasst. -->
      <ul v-if="uebersprungen.length" class="mt-2 space-y-1">
        <li
          v-for="gruppe in uebersprungen"
          :key="gruppe.code"
          class="rounded-md bg-slate-50 px-3 py-2 text-xs text-slate-600"
        >
          <strong class="text-slate-800">
            {{ gruppe.zeiten.length }} {{ gruppe.zeiten.length === 1 ? 'Fahrt' : 'Fahrten' }}:
          </strong>
          {{ gruppe.reason }}
          <span class="text-slate-400">
            ({{ gruppe.zeiten.slice(0, 6).join(', ')
            }}<template v-if="gruppe.zeiten.length > 6"> …</template>)
          </span>
        </li>
      </ul>
    </template>
  </div>
</template>
