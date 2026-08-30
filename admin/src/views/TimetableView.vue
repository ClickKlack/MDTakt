<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppHeader from '../components/AppHeader.vue'
import LineBadge from '../components/LineBadge.vue'
import TimetableGrid from '../components/TimetableGrid.vue'
import { fetchLines, FAHRPLAN_TYPEN, type FahrplanTyp, type Line } from '../services/lines'
import { fetchLineVersions, type LineVersion } from '../services/scheduleVersions'
import { fetchTimetable, type Timetable } from '../services/timetable'
import { formatDate } from '../utils/timezone'
import { lineSortKey, lineTypeOrder } from '../utils/lineStyle'

const route = useRoute()
const router = useRouter()

const lines = ref<Line[]>([])
const versions = ref<LineVersion[]>([])
const timetable = ref<Timetable | null>(null)

const selectedLine = ref<string | null>(null)
const dayType = ref<FahrplanTyp>('mo_fr')
const selectedVersion = ref<number | null>(null)

const loading = ref(true)
const loadingTimetable = ref(false)
const error = ref<string | null>(null)

const line = computed(() => lines.value.find((l) => l.route_short_name === selectedLine.value) ?? null)

onMounted(async () => {
  try {
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

  const daten = await fetchLineVersions(dayType.value, selectedLine.value)
  versions.value = (daten.lines[0]?.versions ?? []).slice().sort((a, b) => a.version_no - b.version_no)

  if (versions.value.length === 0) {
    return
  }

  const treffer = versions.value.find((v) => v.id === bevorzugt)
  await selectVersion((treffer ?? versions.value[versions.value.length - 1]).id)
}

async function selectVersion(id: number): Promise<void> {
  selectedVersion.value = id
  loadingTimetable.value = true
  error.value = null

  try {
    timetable.value = await fetchTimetable(id)
    void router.replace({
      name: 'timetable',
      query: { line: selectedLine.value, day_type: dayType.value, version: String(id) },
    })
  } catch {
    error.value = 'Fahrplan konnte nicht geladen werden.'
  } finally {
    loadingTimetable.value = false
  }
}

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
            „{{ FAHRPLAN_TYPEN.find((t) => t.value === dayType)?.label }}" noch nichts
            konsolidiert vor. Das Konsolidat wächst über viele Importe zusammen — der Reiter
            „Abdeckung" zeigt, welche Zeiträume schon vorliegen.
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

      <TimetableGrid
        v-for="richtung in timetable?.directions ?? []"
        :key="richtung.key"
        :direction="richtung"
      />
    </main>
  </div>
</template>
