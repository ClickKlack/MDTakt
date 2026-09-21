<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppHeader from '../components/AppHeader.vue'
import CourseSequencePanel from '../components/CourseSequencePanel.vue'
import LineBadge from '../components/LineBadge.vue'
import TimetableGrid from '../components/TimetableGrid.vue'
import { assignCourse, detachCourse, type CourseSequenceResult } from '../services/courses'
import { fetchLines, FAHRPLAN_TYPEN, type FahrplanTyp, type Line } from '../services/lines'
import { fetchLineVersions, type LineVersion } from '../services/scheduleVersions'
import { fetchSchedulePeriods, periodOptionLabel, type SchedulePeriod } from '../services/schedulePeriods'
import { fetchTimetable, type Timetable } from '../services/timetable'
import { formatDate } from '../utils/timezone'
import { lineSortKey, lineTypeOrder } from '../utils/lineStyle'

const route = useRoute()
const router = useRouter()

const lines = ref<Line[]>([])
const versions = ref<LineVersion[]>([])
const perioden = ref<SchedulePeriod[]>([])
const timetable = ref<Timetable | null>(null)

const selectedLine = ref<string | null>(null)
const dayType = ref<FahrplanTyp>('mo_fr')
const selectedVersion = ref<number | null>(null)
const selectedPeriod = ref<number | null>(null)

const loading = ref(true)
const loadingTimetable = ref(false)
const error = ref<string | null>(null)
const busy = ref(false)
const hinweis = ref<string | null>(null)

const line = computed(() => lines.value.find((l) => l.route_short_name === selectedLine.value) ?? null)

onMounted(async () => {
  try {
    // Ohne Periodenliste bleibt die Ansicht benutzbar — sie zeigt dann die laufende Periode.
    perioden.value = await fetchSchedulePeriods().catch(() => [])

    const data = await fetchLines()
    lines.value = data
      .slice()
      .sort(
        (a, b) =>
          lineTypeOrder(a) - lineTypeOrder(b) ||
          lineSortKey(a) - lineSortKey(b) ||
          a.route_short_name.localeCompare(b.route_short_name),
      )

    // Auswahl aus der URL übernehmen, damit ein Fahrplan verlinkbar bleibt.
    selectedLine.value = (route.query.line as string) ?? lines.value[0]?.route_short_name ?? null
    dayType.value = ((route.query.day_type as FahrplanTyp) ?? 'mo_fr') as FahrplanTyp
    selectedPeriod.value = Number(route.query.period) || null

    await loadVersions(Number(route.query.version) || null)
  } catch {
    error.value = 'Linien konnten nicht geladen werden.'
  } finally {
    loading.value = false
  }
})

async function loadVersions(bevorzugt: number | null = null): Promise<void> {
  timetable.value = null
  versions.value = []
  selectedVersion.value = null

  if (!selectedLine.value) {
    return
  }

  const daten = await fetchLineVersions(dayType.value, selectedLine.value, selectedPeriod.value)
  // Ohne Vorgabe entscheidet die Engine (laufende Periode) — die Auswahl zieht nach.
  selectedPeriod.value = daten.period?.id ?? null
  versions.value = (daten.lines[0]?.versions ?? []).slice().sort((a, b) => a.version_no - b.version_no)

  if (versions.value.length === 0) {
    return
  }

  const treffer = versions.value.find((v) => v.id === bevorzugt)
  await selectVersion((treffer ?? versions.value[versions.value.length - 1]).id)
}

/**
 * Periodenwechsel. Bewusst am `change` der Auswahl statt an einem Watcher auf `selectedPeriod`:
 * `loadVersions` schreibt die Auswahl aus der Antwort zurück, ein Watcher liefe daraufhin ein
 * zweites Mal.
 */
async function waehlePeriode(): Promise<void> {
  await loadVersions()
}

async function selectVersion(id: number): Promise<void> {
  selectedVersion.value = id
  loadingTimetable.value = true
  error.value = null

  try {
    timetable.value = await fetchTimetable(id)
    void router.replace({
      name: 'timetable',
      query: {
        line: selectedLine.value,
        day_type: dayType.value,
        version: String(id),
        period: selectedPeriod.value ? String(selectedPeriod.value) : undefined,
      },
    })
  } catch {
    error.value = 'Fahrplan konnte nicht geladen werden.'
  } finally {
    loadingTimetable.value = false
  }
}

function meldung(e: unknown, fallback: string): string {
  const antwort = (e as { response?: { data?: { error?: { message?: string } } } }).response
  return antwort?.data?.error?.message ?? fallback
}

/**
 * Der Kurs haengt an der Kette, nicht an der Fahrt: Ein Eintrag hier gilt fuer alle Fahrten
 * des Umlaufs. Deshalb wird der ganze Fahrplan neu geladen — die Aenderung betrifft in aller
 * Regel mehr als die angeklickte Spalte, oft auch eine andere Richtungsgruppe.
 */
async function setzeKurs(tripId: number, nummer: string): Promise<void> {
  busy.value = true
  error.value = null
  hinweis.value = null

  try {
    const ergebnis = await assignCourse(tripId, nummer)
    hinweis.value =
      ergebnis.trips_assigned > 1
        ? `Kurs ${ergebnis.course?.number} gilt jetzt für ${ergebnis.trips_assigned} Fahrten dieses Umlaufs.`
        : `Kurs ${ergebnis.course?.number} gesetzt. Diese Fahrt ist noch mit keiner anderen verknüpft — die Kette entsteht unter „Anschlüsse".`

    if (ergebnis.course?.duplicate) {
      hinweis.value += ' Achtung: Diese Nummer trägt bereits ein anderer Umlauf.'
    }

    await ladeFahrplan()
  } catch (e: unknown) {
    error.value = meldung(e, 'Der Kurs konnte nicht gesetzt werden.')
  } finally {
    busy.value = false
  }
}

async function loeseKurs(tripId: number): Promise<void> {
  busy.value = true
  error.value = null
  hinweis.value = null

  try {
    const ergebnis = await detachCourse(tripId)
    hinweis.value = `Kurs von ${ergebnis.trips_assigned} Fahrten gelöst.`
    await ladeFahrplan()
  } catch (e: unknown) {
    error.value = meldung(e, 'Der Kurs konnte nicht gelöst werden.')
  } finally {
    busy.value = false
  }
}

/** Nur den Fahrplan nachladen, ohne die Auswahl anzufassen. */
async function ladeFahrplan(): Promise<void> {
  if (selectedVersion.value === null) {
    return
  }
  timetable.value = await fetchTimetable(selectedVersion.value)
}

// ---------------------------------------------------------------- Nummernfolge fortschreiben

/**
 * Nur **eine** Richtung kann gleichzeitig markiert sein: Eine Nummernfolge läuft innerhalb einer
 * Richtung um, und zwei gleichzeitige Bereiche wären nicht anwendbar.
 */
const bereich = ref<{ key: string; von: number | null; bis: number | null } | null>(null)

function schalteBereich(key: string): void {
  bereich.value = bereich.value?.key === key ? null : { key, von: null, bis: null }
}

/** Erster Klick setzt die Startspalte, zweiter die letzte, dritter beginnt neu. */
function waehleSpalte(tripId: number): void {
  if (bereich.value === null) {
    return
  }

  if (bereich.value.von === null) {
    bereich.value = { ...bereich.value, von: tripId }
    return
  }

  if (bereich.value.bis === null) {
    bereich.value =
      bereich.value.von === tripId
        ? { ...bereich.value, von: null }
        : { ...bereich.value, bis: tripId }
    return
  }

  bereich.value = { ...bereich.value, von: tripId, bis: null }
}

async function folgeFertig(ergebnis: CourseSequenceResult): Promise<void> {
  hinweis.value =
    ergebnis.action === 'assign'
      ? `${ergebnis.summary.written} Umläufe benummert — ${ergebnis.summary.trips_affected} Fahrten betroffen.` +
        (ergebnis.summary.skipped > 0 ? ` ${ergebnis.summary.skipped} übersprungen.` : '') +
        (ergebnis.summary.conflicts > 0
          ? ` ${ergebnis.summary.conflicts} Umläufe blieben wegen widersprüchlicher Nummern unangetastet.`
          : '')
      : `Kursnummern von ${ergebnis.summary.trips_affected} Fahrten entfernt. Die Anschlüsse bleiben bestehen.`

  if (ergebnis.warnings.length > 0) {
    hinweis.value += ` ${ergebnis.warnings[0].message}`
  }

  bereich.value = null
  await ladeFahrplan()
}

// Ein Versions-, Linien- oder Periodenwechsel zeigt andere Spalten — eine Markierung darin
// wäre Zufall.
watch([selectedVersion, selectedLine, dayType, selectedPeriod], () => {
  bereich.value = null
})

watch([selectedLine, dayType], () => {
  if (!loading.value) {
    void loadVersions()
  }
})

/** Gültigkeit einer Version; „ab"/„mind." markieren Grenzen, die an der Feed-Fensterkante lagen. */
function gueltigkeit(version: LineVersion): string {
  if (version.intervals.length === 0) {
    return 'ohne Gültigkeit'
  }

  return version.intervals
    .map((i) => {
      const von = `${i.from_confirmed ? '' : 'ab '}${formatDate(i.valid_from)}`
      const bis = `${i.to_confirmed ? '' : 'mind. '}${formatDate(i.valid_to)}`
      return `${von} – ${bis}`
    })
    .join(', ')
}
</script>

<template>
  <div class="min-h-full bg-slate-100">
    <AppHeader />

    <!-- Kein max-w-6xl auf dem main: Die Fahrplantabellen brauchen die volle Breite.
         Kopf und Auswahl bleiben über eigene Container im gewohnten Raster. -->
    <main class="py-8">
      <div class="mx-auto max-w-6xl px-6">
        <h1 class="text-2xl font-semibold text-slate-900">Fahrplan</h1>
        <p class="mt-1 max-w-3xl text-sm text-slate-600">
          Der Fahrplan einer Linien-Version aus dem Konsolidat — Halte als Zeilen, Fahrten als
          Spalten. Auch Versionen, die der aktuelle Feed nicht mehr enthält, bleiben abrufbar.
        </p>

        <p v-if="error" class="mt-4 rounded-md bg-rose-50 px-4 py-3 text-sm text-rose-700">
          {{ error }}
        </p>
        <p v-if="loading" class="mt-4 text-sm text-slate-500">Wird geladen …</p>

        <template v-else>
          <!-- Linie -->
          <div class="mt-6 flex flex-wrap gap-2">
            <button
              v-for="l in lines"
              :key="l.route_short_name"
              class="rounded-lg p-1 transition"
              :class="
                selectedLine === l.route_short_name
                  ? 'ring-2 ring-slate-800'
                  : 'hover:ring-2 hover:ring-slate-300'
              "
              @click="selectedLine = l.route_short_name"
            >
              <LineBadge :line="l" size="lg" />
            </button>
          </div>

          <!-- Fahrplantyp -->
          <div class="mt-4 flex flex-wrap gap-1 rounded-lg bg-slate-200/60 p-1">
            <button
              v-for="typ in FAHRPLAN_TYPEN"
              :key="typ.value"
              class="rounded-md px-3 py-1.5 text-sm transition"
              :class="
                dayType === typ.value
                  ? 'bg-white font-medium text-slate-900 shadow-sm'
                  : 'text-slate-600 hover:bg-white/60'
              "
              @click="dayType = typ.value"
            >
              {{ typ.label }}
            </button>
          </div>

          <!-- Periode: ohne sie zeigte die Ansicht nur die laufende, und alles davor war weg.
               Als Auswahlliste, weil die Zahl der Perioden mit jedem Fahrplanwechsel waechst. -->
          <div v-if="perioden.length > 0" class="mt-4">
            <label class="block text-xs font-medium uppercase tracking-wide text-slate-500">Periode</label>
            <select
              v-model.number="selectedPeriod"
              class="mt-1 rounded-md border border-slate-300 px-3 py-1.5 text-sm focus:border-slate-800 focus:outline-none"
              @change="waehlePeriode"
            >
              <option v-for="p in perioden" :key="p.id" :value="p.id">
                {{ periodOptionLabel(p) }}
              </option>
            </select>
          </div>

          <!-- Version -->
          <div v-if="versions.length > 0" class="mt-4 flex flex-wrap items-center gap-2">
            <span class="text-sm text-slate-500">Version:</span>
            <button
              v-for="v in versions"
              :key="v.id"
              class="rounded-md border px-3 py-1.5 text-sm transition"
              :class="
                selectedVersion === v.id
                  ? 'border-slate-800 bg-white font-medium text-slate-900'
                  : 'border-slate-300 text-slate-600 hover:bg-white'
              "
              :title="gueltigkeit(v)"
              @click="selectVersion(v.id)"
            >
              v{{ v.version_no }}
              <span class="ml-1 text-xs text-slate-400">{{ v.trip_count ?? '—' }} Fahrten</span>
            </button>
          </div>

          <p
            v-else-if="line"
            class="mt-6 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900"
          >
            Für Linie {{ line.route_short_name }} liegt zum Typ
            „{{ FAHRPLAN_TYPEN.find((t) => t.value === dayType)?.label }}" in dieser Periode
            noch nichts konsolidiert vor. Das Konsolidat wächst über viele Importe zusammen —
            der Reiter „Abdeckung" zeigt, welche Zeiträume schon vorliegen.
            <template v-if="perioden.length > 1">
              Eine frühere Periode hat den Fahrplan möglicherweise schon.
            </template>
          </p>

          <p v-if="timetable" class="mt-4 text-sm text-slate-500">
            Gültig {{ gueltigkeit(versions.find((v) => v.id === selectedVersion)!) }}
            <span v-if="timetable.period?.status === 'frozen'" class="text-amber-700">
              · aus einer eingefrorenen Periode
            </span>
          </p>
        </template>
      </div>

      <p v-if="loadingTimetable" class="mx-auto mt-6 max-w-6xl px-6 text-sm text-slate-500">
        Fahrplan wird geladen …
      </p>

      <p v-if="hinweis" class="mx-auto mt-4 max-w-6xl rounded-md bg-emerald-50 px-4 py-2 text-sm text-emerald-900">
        {{ hinweis }}
      </p>

      <TimetableGrid
        v-for="richtung in timetable?.directions ?? []"
        :key="richtung.key"
        :direction="richtung"
        :busy="busy"
        :range-mode="bereich?.key === richtung.key"
        :range-from="bereich?.key === richtung.key ? bereich.von : null"
        :range-to="bereich?.key === richtung.key ? bereich.bis : null"
        @assign="setzeKurs"
        @detach="loeseKurs"
        @range-pick="waehleSpalte"
      >
        <template #werkzeuge>
          <div class="mt-2 flex flex-wrap items-center gap-3">
            <button
              type="button"
              class="rounded-md border px-3 py-1.5 text-sm transition"
              :class="
                bereich?.key === richtung.key
                  ? 'border-emerald-600 bg-emerald-600 font-medium text-white'
                  : 'border-slate-300 text-slate-700 hover:border-slate-500'
              "
              @click="schalteBereich(richtung.key)"
            >
              {{ bereich?.key === richtung.key ? 'Fertig' : 'Kursnummern fortschreiben …' }}
            </button>
            <p v-if="bereich?.key !== richtung.key" class="max-w-2xl text-xs text-slate-500">
              Laufen die Kurse dieser Linie in fester Abfolge (etwa 1–8), lässt sich das über einen Spaltenbereich
              fortschreiben — statt jede Spalte einzeln zu tippen.
            </p>
          </div>

          <CourseSequencePanel
            v-if="bereich?.key === richtung.key && timetable"
            :line-version-id="timetable.line_version.id"
            :from-trip-id="bereich.von"
            :to-trip-id="bereich.bis"
            :busy="busy"
            @done="folgeFertig"
            @fehler="error = $event"
          />
        </template>
      </TimetableGrid>
    </main>
  </div>
</template>
