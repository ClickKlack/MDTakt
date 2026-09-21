<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import AppHeader from '../components/AppHeader.vue'
import { fetchLineVersions, type LineVersions } from '../services/scheduleVersions'
import { fetchSchedulePeriods, periodOptionLabel, type SchedulePeriod } from '../services/schedulePeriods'
import { FAHRPLAN_TYPEN } from '../services/lines'
import { applyCarryover, previewCarryover, type CarryoverResult } from '../services/courses'
import { formatDate } from '../utils/timezone'

const route = useRoute()
const router = useRouter()

const data = ref<LineVersions | null>(null)
const perioden = ref<SchedulePeriod[]>([])
const gewaehltePeriode = ref<number | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)
const dayType = ref<string | null>(null)

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    data.value = await fetchLineVersions(dayType.value, null, gewaehltePeriode.value)
    // Die Antwort nennt die tatsächlich gelieferte Periode — ohne Vorgabe ist das die laufende.
    gewaehltePeriode.value = data.value.period?.id ?? null
  } catch {
    error.value = 'Fahrplan-Versionen konnten nicht geladen werden.'
  } finally {
    loading.value = false
  }
}

function select(typ: string | null): void {
  dayType.value = typ
  void load()
}

/**
 * Periodenwechsel. Die A/B-Auswahl bleibt bewusst stehen: Genau so entsteht ein Vergleich über
 * die Periodengrenze — A in der alten Periode markieren, umschalten, B in der neuen.
 *
 * Bewusst am `change` der Auswahl statt an einem Watcher auf `gewaehltePeriode`: `load()`
 * schreibt die Auswahl aus der Antwort zurück, ein Watcher liefe daraufhin ein zweites Mal.
 */
async function waehlePeriode(): Promise<void> {
  const id = gewaehltePeriode.value
  if (id !== null) {
    void router.replace({ name: 'versions', query: { ...route.query, period: String(id) } })
  }
  await load()
}

/**
 * Auswahl für den Vergleich. Zwei Versionen sind nur vergleichbar, wenn sie zu derselben Linie
 * und demselben Fahrplantyp gehören — die Auswahl setzt das durch, statt den Nutzer in einen
 * 422-Fehler laufen zu lassen.
 *
 * Die **Periode** gehört seit dem 21.09.2026 nicht mehr dazu, muss aber mitgeführt werden:
 * `version_no` zählt je Periode wieder bei 1, „v3 gegen v1" wäre sonst nicht einzuordnen und
 * die Reihenfolge alt→neu nicht zu bestimmen.
 */
type Markierung = {
  id: number
  line: string
  day_type: string
  version_no: number
  period_id: number | null
  period_label: string | null
  period_valid_from: string | null
}

const vergleichA = ref<Markierung | null>(null)
const vergleichB = ref<Markierung | null>(null)

function waehlbar(version: { line: string; day_type: string; id: number }): boolean {
  const gesetzt = vergleichA.value ?? vergleichB.value
  if (!gesetzt) return true
  return gesetzt.line === version.line && gesetzt.day_type === version.day_type
}

/** Die ältere zuerst: erst nach Periodenbeginn, dann nach Versionsnummer. */
function aelterZuerst(x: Markierung, y: Markierung): number {
  return (x.period_valid_from ?? '').localeCompare(y.period_valid_from ?? '') || x.version_no - y.version_no
}

/** Baut die Markierung aus einer Tabellenzeile — die Periode kommt aus der geladenen Antwort. */
function markierung(version: { id: number; day_type: string; version_no: number }, line: string): Markierung {
  return {
    id: version.id,
    line,
    day_type: version.day_type,
    version_no: version.version_no,
    period_id: data.value?.period?.id ?? null,
    period_label: data.value?.period?.label ?? null,
    period_valid_from: data.value?.period?.valid_from ?? null,
  }
}

function markiere(version: Markierung): void {
  if (vergleichA.value?.id === version.id) {
    vergleichA.value = null
    return
  }
  if (vergleichB.value?.id === version.id) {
    vergleichB.value = null
    return
  }
  if (!waehlbar(version)) {
    // Andere Linie oder anderer Typ: Auswahl neu beginnen statt eine unmögliche zu bilden.
    vergleichA.value = version
    vergleichB.value = null
    return
  }
  if (!vergleichA.value) {
    vergleichA.value = version
  } else if (!vergleichB.value) {
    vergleichB.value = version
  } else {
    vergleichA.value = version
    vergleichB.value = null
  }
}

/**
 * Übernahme der Pflege von der älteren auf die neuere Version (KURSE §2 K4).
 *
 * Bewusst zweistufig: erst die Vorschau, die garantiert nichts ändert, dann das Anwenden. Eine
 * neue Version bedeutet geänderte Zeiten, und ob ein Anschluss das überlebt, hängt an der
 * Wendezeit — deshalb überträgt der Import von sich aus nichts.
 */
const vorschau = ref<CarryoverResult | null>(null)
const uebernahmeLaeuft = ref(false)
const uebernommen = ref(false)
const uebernahmeFehler = ref<string | null>(null)

/** Quelle ist immer die ältere, Ziel die neuere Version. */
const uebernahmePaar = computed(() => {
  if (!vergleichA.value || !vergleichB.value) {
    return null
  }
  const [von, nach] = [vergleichA.value, vergleichB.value].sort(aelterZuerst)
  return { von, nach }
})

async function zeigeVorschau(): Promise<void> {
  if (uebernahmePaar.value === null) {
    return
  }

  uebernahmeLaeuft.value = true
  uebernahmeFehler.value = null
  uebernommen.value = false

  try {
    vorschau.value = await previewCarryover(uebernahmePaar.value.nach.id, uebernahmePaar.value.von.id)
  } catch (e: unknown) {
    const antwort = (e as { response?: { data?: { error?: { message?: string } } } }).response
    uebernahmeFehler.value = antwort?.data?.error?.message ?? 'Die Vorschau konnte nicht geladen werden.'
  } finally {
    uebernahmeLaeuft.value = false
  }
}

async function wendeAn(): Promise<void> {
  if (uebernahmePaar.value === null) {
    return
  }

  uebernahmeLaeuft.value = true
  uebernahmeFehler.value = null

  try {
    vorschau.value = await applyCarryover(uebernahmePaar.value.nach.id, uebernahmePaar.value.von.id)
    uebernommen.value = true
  } catch (e: unknown) {
    const antwort = (e as { response?: { data?: { error?: { message?: string } } } }).response
    uebernahmeFehler.value = antwort?.data?.error?.message ?? 'Die Übernahme ist fehlgeschlagen.'
  } finally {
    uebernahmeLaeuft.value = false
  }
}

function verwirfVorschau(): void {
  vorschau.value = null
  uebernommen.value = false
  uebernahmeFehler.value = null
}

/** Die ältere Version steht links — sonst läse sich der Vergleich rückwärts. */
const vergleichZiel = computed(() => {
  if (!vergleichA.value || !vergleichB.value) return null
  const [von, nach] = [vergleichA.value, vergleichB.value].sort(aelterZuerst)
  return { name: 'versions-diff', query: { from: String(von.id), to: String(nach.id) } }
})

/** Liegen A und B in verschiedenen Perioden? Dann gehört das in die Beschriftung. */
const periodenuebergreifend = computed<boolean>(
  () =>
    vergleichA.value !== null &&
    vergleichB.value !== null &&
    vergleichA.value.period_id !== vergleichB.value.period_id,
)

/** Linien mit mehr als einer Version je Typ — dort hat sich der Fahrplan geändert. */
const geaenderteLinien = computed<number>(() => {
  if (!data.value) return 0
  return data.value.lines.filter((l) => {
    const proTyp = new Map<string, number>()
    l.versions.forEach((v) => proTyp.set(v.day_type, (proTyp.get(v.day_type) ?? 0) + 1))
    return [...proTyp.values()].some((n) => n > 1)
  }).length
})

onMounted(async () => {
  try {
    perioden.value = await fetchSchedulePeriods()
  } catch {
    // Ohne Periodenliste bleibt die Ansicht benutzbar — sie zeigt dann die laufende Periode.
    perioden.value = []
  }

  gewaehltePeriode.value = Number(route.query.period) || null
  await load()
})
</script>

<template>
  <div class="min-h-full bg-slate-100">
    <AppHeader />

    <main class="mx-auto max-w-6xl px-6 py-8">
      <h2 class="text-base font-semibold text-slate-900">Fahrplan-Versionen</h2>
      <p class="mt-1 text-sm text-slate-500">
        Änderungshistorie je Linie und Betriebstag-Typ, aufgebaut aus den GTFS-Importen.
      </p>

      <div v-if="loading" class="mt-4 text-slate-500">Lädt…</div>
      <div v-else-if="error" class="mt-4 rounded-md bg-red-50 px-4 py-3 text-red-700">{{ error }}</div>

      <template v-else-if="data">
        <!-- Periodenauswahl: ohne sie waere die Historie vor einem Wechsel unerreichbar.
             Als Auswahlliste, weil die Zahl der Perioden mit jedem Fahrplanwechsel waechst. -->
        <div v-if="perioden.length > 0" class="mt-4">
          <label class="block text-xs font-medium uppercase tracking-wide text-slate-500">Periode</label>
          <select
            v-model.number="gewaehltePeriode"
            class="mt-1 rounded-md border border-slate-300 px-3 py-1.5 text-sm focus:border-slate-800 focus:outline-none"
            @change="waehlePeriode"
          >
            <option v-for="p in perioden" :key="p.id" :value="p.id">
              {{ periodOptionLabel(p) }}
            </option>
          </select>
        </div>

        <!-- Periode + Abdeckung -->
        <div v-if="data.period" class="mt-4 grid gap-4 sm:grid-cols-3">
          <div class="rounded-lg bg-white px-4 py-3 shadow-sm">
            <div class="text-xs uppercase text-slate-500">Periode</div>
            <div class="mt-1 font-medium text-slate-900">{{ data.period.label }}</div>
            <div class="text-xs text-slate-400">
              {{ formatDate(data.period.valid_from) }} –
              {{ data.period.valid_to ? formatDate(data.period.valid_to) : 'offen' }}
              <span v-if="data.period.status === 'frozen'" class="text-amber-600"> · eingefroren</span>
              <span v-if="data.period.created_via === 'bootstrap'"> · automatisch angelegt</span>
            </div>
          </div>
          <div v-if="data.coverage" class="rounded-lg bg-white px-4 py-3 shadow-sm">
            <div class="text-xs uppercase text-slate-500">Konsolidiert</div>
            <div class="mt-1 font-medium text-slate-900">
              {{ formatDate(data.coverage.from) }} – {{ formatDate(data.coverage.to) }}
            </div>
            <div class="text-xs text-slate-400">{{ data.lines.length }} Linien · {{ geaenderteLinien }} mit Änderung</div>
          </div>
          <div v-if="data.coverage" class="rounded-lg bg-white px-4 py-3 shadow-sm">
            <div class="text-xs uppercase text-slate-500">Grenzen</div>
            <div class="mt-1 font-medium text-slate-900">
              {{ data.coverage.confirmed_boundaries }} gesichert · {{ data.coverage.open_boundaries }} offen
            </div>
            <div class="text-xs text-slate-400">offen = am Rand des Feed-Fensters, wahre Grenze unbekannt</div>
          </div>
        </div>

        <!-- Vergleichsleiste — erscheint, sobald eine Version markiert ist -->
        <div
          v-if="vergleichA"
          class="mt-6 flex flex-wrap items-center gap-3 rounded-lg border border-slate-300 bg-white px-4 py-3"
        >
          <span class="text-sm text-slate-700">
            Vergleich: Linie {{ vergleichA.line }} ·
            <strong>v{{ vergleichA.version_no }}</strong>
            <span v-if="periodenuebergreifend" class="text-slate-500">({{ vergleichA.period_label }})</span>
            <template v-if="vergleichB">
              gegen <strong>v{{ vergleichB.version_no }}</strong>
              <span v-if="periodenuebergreifend" class="text-slate-500">({{ vergleichB.period_label }})</span>
            </template>
            <span v-else class="text-slate-500">
              — zweite Version wählen<template v-if="perioden.length > 1">, auch in einer anderen Periode</template>
            </span>
          </span>

          <RouterLink
            v-if="vergleichZiel"
            :to="vergleichZiel"
            class="rounded-md bg-slate-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-slate-700"
          >
            Unterschiede zeigen
          </RouterLink>

          <button
            v-if="uebernahmePaar"
            class="rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:border-slate-500 disabled:opacity-50"
            :disabled="uebernahmeLaeuft"
            @click="zeigeVorschau"
          >
            Kurse übernehmen …
          </button>

          <button
            class="text-sm text-slate-500 hover:text-slate-800"
            @click="vergleichA = null; vergleichB = null; verwirfVorschau()"
          >
            Auswahl aufheben
          </button>
        </div>

        <!-- Übernahme: erst die folgenlose Vorschau, dann das Anwenden -->
        <div
          v-if="uebernahmeFehler"
          class="mt-3 rounded-md bg-rose-50 px-4 py-3 text-sm text-rose-700"
        >
          {{ uebernahmeFehler }}
        </div>

        <div v-if="vorschau" class="mt-3 rounded-lg border border-slate-300 bg-white px-4 py-3">
          <h3 class="text-sm font-medium text-slate-900">
            {{ uebernommen ? 'Übernommen' : 'Vorschau' }}: Linie {{ vorschau.from.line }},
            v{{ vorschau.from.version_no }} → v{{ vorschau.to.version_no }}
          </h3>

          <p v-if="!uebernommen" class="mt-1 text-xs text-slate-500">
            Diese Ansicht ändert nichts. Erst „Übernehmen" schreibt.
          </p>

          <dl class="mt-3 grid grid-cols-2 gap-x-6 gap-y-1 text-sm sm:grid-cols-3">
            <div class="flex justify-between gap-2">
              <dt class="text-slate-600">Kurse</dt>
              <dd class="font-medium tabular-nums text-slate-900">{{ vorschau.summary.courses_carried }}</dd>
            </div>
            <div class="flex justify-between gap-2">
              <dt class="text-slate-600">Anschlüsse</dt>
              <dd class="font-medium tabular-nums text-slate-900">{{ vorschau.summary.links_carried }}</dd>
            </div>
            <div class="flex justify-between gap-2">
              <dt class="text-slate-600">Aus-/Einrücken</dt>
              <dd class="font-medium tabular-nums text-slate-900">{{ vorschau.summary.terminals_carried }}</dd>
            </div>
            <div class="flex justify-between gap-2">
              <dt class="text-slate-600">verschobene Fahrten</dt>
              <dd class="tabular-nums text-slate-700">{{ vorschau.summary.changed }}</dd>
            </div>
            <div class="flex justify-between gap-2">
              <dt class="text-slate-600">neue Fahrten</dt>
              <dd class="tabular-nums text-slate-700">{{ vorschau.summary.added }}</dd>
            </div>
            <div class="flex justify-between gap-2">
              <dt class="text-slate-600">entfallene Fahrten</dt>
              <dd class="tabular-nums text-slate-700">{{ vorschau.summary.removed }}</dd>
            </div>
          </dl>

          <p
            v-if="vorschau.summary.blocked > 0"
            class="mt-3 rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-900"
          >
            <strong>{{ vorschau.summary.blocked }} Anschlüsse lassen sich nicht übertragen.</strong>
            Sie zeigen auf eine Fahrt außerhalb dieser Version — der Linienwechsel-Fall. Die Gegenfahrt hängt noch an
            der alten Fahrt, und ein Fahrzeug hat höchstens einen Vorgänger. Diese Anschlüsse sind unter „Anschlüsse"
            neu zu setzen.
          </p>

          <p
            v-if="vorschau.summary.lost > 0"
            class="mt-2 rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-900"
          >
            {{ vorschau.summary.lost }} Entscheidungen hängen an Fahrten, die es in der neuen Version nicht mehr gibt.
            Sie gehen mit ihnen verloren.
          </p>

          <div class="mt-3 flex flex-wrap gap-3">
            <button
              v-if="!uebernommen"
              class="rounded-md bg-slate-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-50"
              :disabled="uebernahmeLaeuft"
              @click="wendeAn"
            >
              Übernehmen
            </button>
            <button class="text-sm text-slate-500 hover:text-slate-800" @click="verwirfVorschau">
              {{ uebernommen ? 'Schließen' : 'Abbrechen' }}
            </button>
          </div>
        </div>

        <!-- Typ-Filter -->
        <div class="mt-6 flex flex-wrap gap-1 rounded-lg bg-slate-200/60 p-1">
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

        <p v-if="data.lines.length === 0" class="mt-6 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800">
          Für diesen Fahrplantyp liegen keine Versionen vor. Der GTFS-Feed deckt ein rollierendes
          Zeitfenster ab — enthält es keinen Tag dieses Typs, entsteht auch keine Version.
        </p>

        <!-- Versionen je Linie -->
        <div class="mt-4 space-y-2">
          <div v-for="linie in data.lines" :key="linie.line" class="overflow-hidden rounded-lg bg-white shadow-sm">
            <div class="border-b border-slate-100 px-4 py-2.5 font-medium text-slate-900">
              Linie {{ linie.line }}
            </div>
            <table class="w-full text-left text-sm">
              <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                <tr>
                  <th class="px-4 py-2">Betriebstag</th>
                  <th class="px-4 py-2">Version</th>
                  <th class="px-4 py-2">Fahrten</th>
                  <th class="px-4 py-2">Gültig</th>
                  <th class="px-4 py-2"></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="version in linie.versions" :key="version.id" class="border-b border-slate-50 last:border-0">
                  <td class="px-4 py-1.5 text-slate-600">{{ version.day_type_label }}</td>
                  <td class="px-4 py-1.5 text-slate-500">v{{ version.version_no }}</td>
                  <td class="px-4 py-1.5 tabular-nums text-slate-500">{{ version.trip_count ?? '—' }}</td>
                  <td class="px-4 py-1.5">
                    <span
                      v-for="(i, index) in version.intervals"
                      :key="index"
                      class="mr-1.5 inline-block rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600"
                      :title="`Beginn ${i.from_confirmed ? 'gesichert' : 'offen (Rand des Feed-Fensters)'}, Ende ${i.to_confirmed ? 'gesichert' : 'offen'}`"
                    >
                      <span :class="i.from_confirmed ? 'text-slate-400' : 'text-amber-600'">{{ i.from_confirmed ? '|' : '~' }}</span>
                      {{ formatDate(i.valid_from) }}–{{ formatDate(i.valid_to) }}
                      <span :class="i.to_confirmed ? 'text-slate-400' : 'text-amber-600'">{{ i.to_confirmed ? '|' : '~' }}</span>
                    </span>
                  </td>
                  <td class="px-4 py-1.5 text-right whitespace-nowrap">
                    <!-- Vergleichsauswahl: A und B müssen dieselbe Linie und denselben Typ haben. -->
                    <button
                      class="mr-3 rounded border px-2 py-0.5 text-xs transition"
                      :class="
                        vergleichA?.id === version.id || vergleichB?.id === version.id
                          ? 'border-slate-800 bg-slate-800 text-white'
                          : waehlbar({ line: linie.line, day_type: version.day_type, id: version.id })
                            ? 'border-slate-300 text-slate-500 hover:bg-slate-100'
                            : 'border-slate-200 text-slate-300'
                      "
                      :title="
                        waehlbar({ line: linie.line, day_type: version.day_type, id: version.id })
                          ? 'Für den Vergleich auswählen'
                          : 'Nur Versionen derselben Linie und desselben Fahrplantyps sind vergleichbar'
                      "
                      @click="markiere(markierung(version, linie.line))"
                    >
                      {{
                        vergleichA?.id === version.id
                          ? 'A'
                          : vergleichB?.id === version.id
                            ? 'B'
                            : 'vergleichen'
                      }}
                    </button>

                    <!-- Von „hier hat sich etwas geändert" direkt in den Fahrplan dieser Version. -->
                    <RouterLink
                      :to="{
                        name: 'timetable',
                        query: {
                          line: linie.line,
                          day_type: version.day_type,
                          version: String(version.id),
                          period: data.period ? String(data.period.id) : undefined,
                        },
                      }"
                      class="text-slate-500 hover:text-slate-900"
                    >
                      Fahrplan →
                    </RouterLink>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </template>
    </main>
  </div>
</template>
