<script setup lang="ts">
import { ref, watch } from 'vue'
import { fetchLineTrips, type LineTrips } from '../services/lines'
import { formatClock } from '../utils/timezone'

const props = defineProps<{ line: string }>()

const data = ref<LineTrips | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)
const expanded = ref<Record<string, boolean>>({})

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  expanded.value = {}
  try {
    data.value = await fetchLineTrips(props.line)
  } catch {
    error.value = 'Fahrten konnten nicht geladen werden.'
  } finally {
    loading.value = false
  }
}

function toggle(key: string): void {
  expanded.value[key] = !expanded.value[key]
}

watch(() => props.line, load, { immediate: true })
</script>

<template>
  <div>
    <div v-if="loading" class="text-slate-500">Lädt…</div>
    <div v-else-if="error" class="rounded-md bg-red-50 px-4 py-3 text-red-700">{{ error }}</div>

    <template v-else-if="data">
      <p class="text-sm text-slate-500">
        {{ data.trip_count }} Fahrten · {{ data.groups.length }} Richtungen/Varianten
      </p>

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
                  <th class="px-4 py-2">Dienst</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="trip in group.trips" :key="trip.trip_id" class="border-b border-slate-50 last:border-0">
                  <td class="px-4 py-1.5 tabular-nums text-slate-700">{{ formatClock(trip.departure_time) }}</td>
                  <td class="px-4 py-1.5 tabular-nums text-slate-500">{{ formatClock(trip.arrival_time) }}</td>
                  <td class="px-4 py-1.5 text-slate-400">{{ trip.service_id }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>
