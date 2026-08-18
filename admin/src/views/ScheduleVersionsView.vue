<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import AppHeader from '../components/AppHeader.vue'
import { fetchLineVersions, type LineVersions } from '../services/scheduleVersions'
import { FAHRPLAN_TYPEN } from '../services/lines'
import { formatDate } from '../utils/timezone'

const data = ref<LineVersions | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)
const dayType = ref<string | null>(null)

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    data.value = await fetchLineVersions(dayType.value)
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

/** Linien mit mehr als einer Version je Typ — dort hat sich der Fahrplan geändert. */
const geaenderteLinien = computed<number>(() => {
  if (!data.value) return 0
  return data.value.lines.filter((l) => {
    const proTyp = new Map<string, number>()
    l.versions.forEach((v) => proTyp.set(v.day_type, (proTyp.get(v.day_type) ?? 0) + 1))
    return [...proTyp.values()].some((n) => n > 1)
  }).length
})

onMounted(load)
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
        <!-- Periode + Abdeckung -->
        <div v-if="data.period" class="mt-4 grid gap-4 sm:grid-cols-3">
          <div class="rounded-lg bg-white px-4 py-3 shadow-sm">
            <div class="text-xs uppercase text-slate-500">Periode</div>
            <div class="mt-1 font-medium text-slate-900">{{ data.period.label }}</div>
            <div class="text-xs text-slate-400">
              ab {{ formatDate(data.period.valid_from) }}
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
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </template>
    </main>
  </div>
</template>
