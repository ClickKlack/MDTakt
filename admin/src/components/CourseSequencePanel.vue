<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import {
  applyCourseSequence,
  previewCourseSequence,
  type CourseSequenceAction,
  type CourseSequenceResult,
} from '../services/courses'
import { formatClock } from '../utils/timezone'

const props = defineProps<{
  lineVersionId: number
  /** Die markierten Spaltengrenzen; `null`, solange der Bereich unvollständig ist. */
  fromTripId: number | null
  toTripId: number | null
  busy: boolean
}>()

const emit = defineEmits<{
  done: [ergebnis: CourseSequenceResult]
  fehler: [meldung: string]
}>()

const aktion = ref<CourseSequenceAction>('assign')
const muster = ref('1-8')

const vorschau = ref<CourseSequenceResult | null>(null)
const laeuft = ref(false)

// Ein Reiterwechsel oder eine neue Markierung macht die Vorschau ungültig — sonst stünde sie
// über einem Knopf, der etwas anderes täte.
watch([aktion, muster, () => props.fromTripId, () => props.toTripId], () => {
  vorschau.value = null
})

const bereit = computed(
  () => props.fromTripId !== null && props.toTripId !== null && (aktion.value === 'clear' || muster.value.trim() !== ''),
)

const gesperrt = computed(() => props.busy || laeuft.value || !bereit.value)

/** Die Zahl auf dem ausführenden Knopf — die der **Fahrten**, nicht der Spalten. */
const betroffen = computed(() => vorschau.value?.summary.trips_affected ?? 0)
const geplant = computed(() => vorschau.value?.summary.planned ?? 0)

function meldung(e: unknown, fallback: string): string {
  const antwort = (e as { response?: { data?: { error?: { message?: string } } } }).response
  return antwort?.data?.error?.message ?? fallback
}

function eingaben() {
  return {
    from_trip_id: props.fromTripId as number,
    to_trip_id: props.toTripId as number,
    action: aktion.value,
    pattern: aktion.value === 'assign' ? muster.value.trim() : undefined,
  }
}

async function hole(): Promise<void> {
  if (!bereit.value) {
    return
  }

  laeuft.value = true

  try {
    vorschau.value = await previewCourseSequence(props.lineVersionId, eingaben())
  } catch (e: unknown) {
    vorschau.value = null
    emit('fehler', meldung(e, 'Die Vorschau konnte nicht geladen werden.'))
  } finally {
    laeuft.value = false
  }
}

async function fuehreAus(): Promise<void> {
  if (!bereit.value) {
    return
  }

  laeuft.value = true

  try {
    const ergebnis = await applyCourseSequence(props.lineVersionId, eingaben())
    vorschau.value = null
    emit('done', ergebnis)
  } catch (e: unknown) {
    emit('fehler', meldung(e, 'Der Lauf konnte nicht ausgeführt werden.'))
  } finally {
    laeuft.value = false
  }
}

const eintraege = computed(() =>
  aktion.value === 'assign' ? (vorschau.value?.assignments ?? []) : (vorschau.value?.removals ?? []),
)
</script>

<template>
  <div class="mt-3 rounded-lg border border-slate-300 bg-white p-4 shadow-sm">
    <div class="flex flex-wrap items-center gap-3">
      <div class="flex gap-1 rounded-lg bg-slate-200/60 p-1">
        <button
          v-for="reiter in [
            { wert: 'assign' as const, text: 'Fortschreiben' },
            { wert: 'clear' as const, text: 'Entfernen' },
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

      <label v-if="aktion === 'assign'" class="flex items-center gap-2">
        <span class="text-xs font-medium uppercase tracking-wide text-slate-500">Folge</span>
        <input
          v-model="muster"
          type="text"
          maxlength="120"
          placeholder="1-8"
          class="w-40 rounded-md border border-slate-300 px-2 py-1 text-sm tabular-nums focus:border-slate-800 focus:outline-none"
        />
      </label>

      <p v-if="fromTripId === null || toTripId === null" class="text-sm text-amber-800">
        Markiere oben die Startspalte und die letzte Spalte.
      </p>
    </div>

    <p v-if="aktion === 'assign'" class="mt-2 max-w-3xl text-xs text-slate-500">
      Die Nummern laufen ab der Startspalte <strong>zyklisch</strong> durch — etwa „1-8“, „31-35“ oder
      „1-4, 7, 9-12“. Führende Nullen bleiben erhalten: „01-08“ ergibt 01…08, „1-8“ dagegen 1…8.
      <span class="text-slate-400">Für die Datenbank sind „03“ und „3“ zwei verschiedene Kurse.</span>
    </p>
    <p v-else class="mt-2 max-w-3xl rounded-md bg-slate-50 px-3 py-2 text-xs text-slate-600">
      Die Anschlüsse bleiben bestehen — entfernt wird nur das Etikett. Weil der Kurs an der ganzen Kette hängt,
      verlieren dabei auch Fahrten der Gegenrichtung ihre Nummer.
    </p>

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
        :class="aktion === 'assign' ? 'bg-slate-800 hover:bg-slate-700' : 'bg-rose-700 hover:bg-rose-600'"
        :disabled="gesperrt || geplant === 0"
        @click="fuehreAus"
      >
        {{
          aktion === 'assign'
            ? `Nummern für ${betroffen} Fahrten setzen`
            : `Kursnummern von ${betroffen} Fahrten entfernen`
        }}
      </button>

      <span v-if="laeuft" class="text-sm text-slate-500">Wird gerechnet …</span>
    </div>

    <template v-if="vorschau">
      <p class="mt-3 text-sm text-slate-700">
        {{ vorschau.summary.columns }} Spalten markiert ·
        <strong>{{ vorschau.summary.trips_affected }}</strong> Fahrten betroffen
        <span v-if="vorschau.summary.outside_range > 0" class="text-amber-800">
          (davon {{ vorschau.summary.outside_range }} außerhalb der Markierung — die Kette zieht mit)
        </span>
        <template v-if="vorschau.summary.unchanged > 0"> · {{ vorschau.summary.unchanged }} unverändert</template>
        <template v-if="vorschau.summary.skipped > 0"> · {{ vorschau.summary.skipped }} übersprungen</template>
      </p>

      <p
        v-if="vorschau.pattern"
        class="mt-1 text-xs text-slate-500"
      >
        {{ vorschau.pattern.numbers.join(' · ') }} — {{ vorschau.pattern.count }} Nummern, zyklisch über
        {{ vorschau.summary.columns }} Spalten
      </p>

      <!-- Widersprüche zuerst: Sie verhindern, dass die Kette geschrieben wird. -->
      <ul v-if="vorschau.conflicts.length" class="mt-2 space-y-1">
        <li
          v-for="(konflikt, i) in vorschau.conflicts"
          :key="`k${i}`"
          class="rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-900"
        >
          {{ konflikt.reason }}
        </li>
      </ul>

      <ul v-if="eintraege.length" class="mt-2 divide-y divide-slate-100 rounded-md border border-slate-200">
        <li
          v-for="eintrag in eintraege"
          :key="eintrag.trip.id"
          class="flex flex-wrap items-center gap-2 px-3 py-1.5 text-sm"
        >
          <span class="w-10 shrink-0 text-xs text-slate-400">Sp. {{ eintrag.column + 1 }}</span>
          <span class="tabular-nums">{{ formatClock(eintrag.trip.departure_time) }}</span>
          <span
            class="rounded px-1.5 py-0.5 text-xs font-semibold tabular-nums"
            :class="aktion === 'assign' ? 'bg-slate-800 text-white' : 'bg-rose-100 text-rose-900 line-through'"
          >
            {{ eintrag.number }}
          </span>
          <span v-if="eintrag.outside_range.length" class="text-xs text-amber-800">
            zieht {{ eintrag.outside_range.length }}
            {{ eintrag.outside_range.length === 1 ? 'weitere Fahrt' : 'weitere Fahrten' }} mit
          </span>
        </li>
      </ul>

      <ul v-if="vorschau.skipped.length" class="mt-2 space-y-1">
        <li
          v-for="zeile in vorschau.skipped"
          :key="`s${zeile.trip.id}`"
          class="rounded-md bg-slate-50 px-3 py-1.5 text-xs text-slate-600"
        >
          Spalte {{ zeile.column + 1 }} ({{ formatClock(zeile.trip.departure_time) }}): {{ zeile.reason }}
        </li>
      </ul>

      <ul v-if="vorschau.warnings.length" class="mt-2 space-y-1">
        <li
          v-for="hinweis in vorschau.warnings"
          :key="hinweis.code"
          class="rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-900"
        >
          {{ hinweis.message }}
        </li>
      </ul>
    </template>
  </div>
</template>
