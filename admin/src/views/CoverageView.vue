<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import AppHeader from '../components/AppHeader.vue'
import { fetchCoverage, type Coverage, type CoverageDayType } from '../services/coverage'
import { formatDate } from '../utils/timezone'

const coverage = ref<Coverage | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)
const nurLuecken = ref(false)

onMounted(async () => {
  try {
    coverage.value = await fetchCoverage()
  } catch {
    error.value = 'Abdeckung konnte nicht geladen werden.'
  } finally {
    loading.value = false
  }
})

/** Linien mit mindestens einer Lücke — der eigentliche Grund, hier hinzusehen. */
const linienMitLuecken = computed(
  () => coverage.value?.lines.filter((l) => l.day_types.some((t) => t.gaps.length > 0)) ?? [],
)

const sichtbareLinien = computed(() =>
  nurLuecken.value ? linienMitLuecken.value : (coverage.value?.lines ?? []),
)

const luecken = computed(() =>
  (coverage.value?.lines ?? []).reduce(
    (summe, l) => summe + l.day_types.reduce((s, t) => s + t.gaps.length, 0),
    0,
  ),
)

function bereich(typ: CoverageDayType): string {
  if (typ.ranges.length === 0) {
    return 'kein Inhalt'
  }
  return typ.ranges
    .map((r) => `${formatDate(r.from)}–${formatDate(r.to)}`)
    .join(', ')
}

/**
 * Eine Grenze an der Feed-Fensterkante ist nur eine Untergrenze, kein Fahrplanwechsel
 * (FAHRPLANPERIODEN §5.4 b). Das muss sichtbar bleiben, sonst liest man eine Fensterkante
 * als Datum, an dem sich etwas geändert hätte.
 */
function offeneGrenzen(typ: CoverageDayType): number {
  return typ.ranges.reduce((s, r) => s + (r.from_confirmed ? 0 : 1) + (r.to_confirmed ? 0 : 1), 0)
}
</script>

<template>
  <div class="min-h-full bg-slate-100">
    <AppHeader />

    <main class="mx-auto max-w-6xl px-6 py-8">
      <div class="mb-6">
        <h1 class="text-2xl font-semibold text-slate-900">Abdeckung</h1>
        <p class="mt-1 max-w-3xl text-sm text-slate-600">
          Für welche Zeiträume gibt das Konsolidat den Fahrplan wirklich her? Der Feed ist ein
          rollierendes Fenster — jede nicht importierte Woche fehlt endgültig. Lücken werden
          deshalb ausgewiesen, nicht geglättet.
        </p>
      </div>

      <p v-if="error" class="mb-4 rounded-md bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ error }}</p>
      <p v-if="loading" class="text-sm text-slate-500">Wird geladen …</p>

      <template v-else-if="coverage">
        <!-- Kennzahlen -->
        <section class="mb-6 grid grid-cols-2 gap-4 md:grid-cols-4">
          <div class="rounded-lg border border-slate-200 bg-white p-4">
            <div class="text-xs text-slate-500">Konsolidierter Zeitraum</div>
            <div class="mt-1 text-sm font-semibold text-slate-900">
              <template v-if="coverage.window">
                {{ formatDate(coverage.window.from) }} – {{ formatDate(coverage.window.to) }}
              </template>
              <template v-else>— noch nichts konsolidiert</template>
            </div>
          </div>
          <div class="rounded-lg border border-slate-200 bg-white p-4">
            <div class="text-xs text-slate-500">Linien</div>
            <div class="mt-1 text-lg font-semibold tabular-nums text-slate-900">
              {{ coverage.totals.lines }}
            </div>
          </div>
          <div class="rounded-lg border border-slate-200 bg-white p-4">
            <div class="text-xs text-slate-500">Konsolidierte Fahrten</div>
            <div class="mt-1 text-lg font-semibold tabular-nums text-slate-900">
              {{ coverage.totals.consolidated_trips.toLocaleString('de-DE') }}
            </div>
          </div>
          <div
            class="rounded-lg border p-4"
            :class="luecken > 0 ? 'border-amber-300 bg-amber-50' : 'border-slate-200 bg-white'"
          >
            <div class="text-xs" :class="luecken > 0 ? 'text-amber-800' : 'text-slate-500'">Lücken</div>
            <div
              class="mt-1 text-lg font-semibold tabular-nums"
              :class="luecken > 0 ? 'text-amber-900' : 'text-slate-900'"
            >
              {{ luecken }}
            </div>
          </div>
        </section>

        <p
          v-if="coverage.totals.versions_without_content > 0"
          class="mb-6 rounded-md bg-amber-50 px-4 py-3 text-sm text-amber-900"
        >
          <strong>{{ coverage.totals.versions_without_content }}</strong> Linien-Versionen haben
          zwar einen bekannten Zeitraum, aber keine konsolidierten Fahrten — für diese Tage ist
          bekannt, <em>dass</em> ein eigener Fahrplan galt, aber nicht <em>welcher</em>.
        </p>

        <label class="mb-4 flex items-center gap-2 text-sm text-slate-700">
          <input v-model="nurLuecken" type="checkbox" class="rounded border-slate-300" />
          Nur Linien mit Lücken zeigen ({{ linienMitLuecken.length }})
        </label>

        <!-- Je Linie und Fahrplantyp -->
        <section class="overflow-hidden rounded-lg border border-slate-200 bg-white">
          <table class="w-full text-sm">
            <thead class="border-b border-slate-200 bg-slate-50 text-left text-slate-600">
              <tr>
                <th class="px-5 py-3 font-medium">Linie</th>
                <th class="px-5 py-3 font-medium">Fahrplantyp</th>
                <th class="px-5 py-3 font-medium">Abgedeckt</th>
                <th class="px-5 py-3 font-medium">Lücken</th>
                <th class="px-5 py-3 text-right font-medium">Offene Grenzen</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <template v-for="linie in sichtbareLinien" :key="linie.line">
                <tr
                  v-for="(typ, index) in linie.day_types"
                  :key="`${linie.line}-${typ.day_type}`"
                  class="hover:bg-slate-50"
                >
                  <td class="px-5 py-2">
                    <span
                      v-if="index === 0"
                      class="inline-block rounded bg-slate-800 px-2 py-0.5 text-xs font-semibold text-white"
                    >
                      {{ linie.line }}
                    </span>
                  </td>
                  <td class="px-5 py-2 text-slate-700">{{ typ.day_type_label }}</td>
                  <td class="px-5 py-2 text-slate-700">{{ bereich(typ) }}</td>
                  <td class="px-5 py-2">
                    <span v-if="typ.gaps.length === 0" class="text-slate-400">—</span>
                    <span
                      v-for="gap in typ.gaps"
                      :key="gap.from"
                      class="mr-1 inline-block rounded bg-amber-100 px-2 py-0.5 text-xs text-amber-900"
                      :title="`${gap.days} fehlende Tage dieses Fahrplantyps`"
                    >
                      {{ formatDate(gap.from) }}–{{ formatDate(gap.to) }} ({{ gap.days }})
                    </span>
                  </td>
                  <td class="px-5 py-2 text-right tabular-nums text-slate-500">
                    {{ offeneGrenzen(typ) }}
                  </td>
                </tr>
              </template>
              <tr v-if="sichtbareLinien.length === 0">
                <td colspan="5" class="px-5 py-6 text-center text-slate-500">
                  {{ nurLuecken ? 'Keine Linie hat Lücken.' : 'Noch nichts konsolidiert.' }}
                </td>
              </tr>
            </tbody>
          </table>
        </section>

        <p class="mt-4 text-xs text-slate-500">
          „Offene Grenzen" zählt Ränder, die an einer Feed-Fensterkante liegen. Sie sind nur
          Untergrenzen — kein beobachteter Fahrplanwechsel. Ein späterer Import, dessen Fenster
          weiter reicht, kann sie zu gesicherten Grenzen verdichten.
        </p>
      </template>
    </main>
  </div>
</template>
