<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import AppHeader from '../components/AppHeader.vue'
import LineBadge from '../components/LineBadge.vue'
import LineTripsPanel from '../components/LineTripsPanel.vue'
import { fetchLines, type Line } from '../services/lines'
import { lineSortKey, lineTypeOrder } from '../utils/lineStyle'

const lines = ref<Line[]>([])
const loading = ref(true)
const error = ref<string | null>(null)
const selected = ref<Line | null>(null)

onMounted(async () => {
  try {
    const data = await fetchLines()
    // Primär nach Typ (Tram→Bus→Nacht), dann nach Nummer (Buchstaben ignoriert), dann Name.
    lines.value = data
      .slice()
      .sort(
        (a, b) =>
          lineTypeOrder(a) - lineTypeOrder(b) ||
          lineSortKey(a) - lineSortKey(b) ||
          a.route_short_name.localeCompare(b.route_short_name),
      )
  } catch {
    error.value = 'Linien konnten nicht geladen werden.'
  } finally {
    loading.value = false
  }
})

function select(line: Line): void {
  selected.value = line
}
</script>

<template>
  <div class="min-h-full bg-slate-100">
    <AppHeader />

    <main class="mx-auto max-w-6xl px-6 py-8">
      <div class="flex items-start justify-between">
        <div>
          <h2 class="text-base font-semibold text-slate-900">Linien</h2>
          <p class="mt-1 text-sm text-slate-500">Linie wählen — die Fahrten erscheinen darunter.</p>
        </div>
        <RouterLink to="/lines/colors" class="text-sm text-slate-500 hover:text-slate-800">Farben verwalten</RouterLink>
      </div>

      <div v-if="loading" class="mt-4 text-slate-500">Lädt…</div>
      <div v-else-if="error" class="mt-4 rounded-md bg-red-50 px-4 py-3 text-red-700">{{ error }}</div>

      <template v-else>
        <!-- Linienauswahl (bleibt stehen) -->
        <div class="mt-4 flex flex-wrap gap-2">
          <button
            v-for="line in lines"
            :key="line.route_short_name"
            class="rounded-lg p-1 transition"
            :class="selected?.route_short_name === line.route_short_name ? 'ring-2 ring-slate-800' : 'hover:ring-2 hover:ring-slate-300'"
            @click="select(line)"
          >
            <LineBadge :line="line" size="lg" />
          </button>
        </div>

        <!-- Detail unter der Auswahl -->
        <section v-if="selected" class="mt-8">
          <div class="flex items-center gap-3">
            <LineBadge :line="selected" />
            <h3 class="text-base font-semibold text-slate-900">Linie {{ selected.route_short_name }}</h3>
          </div>
          <div class="mt-4">
            <LineTripsPanel :line="selected.route_short_name" />
          </div>
        </section>
        <p v-else class="mt-8 text-sm text-slate-400">Noch keine Linie ausgewählt.</p>
      </template>
    </main>
  </div>
</template>
