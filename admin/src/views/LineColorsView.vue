<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import AppHeader from '../components/AppHeader.vue'
import LineBadge from '../components/LineBadge.vue'
import { fetchLines, resetLineColor, setLineColor, type Line } from '../services/lines'
import { lineColor, lineSortKey, lineTypeOrder } from '../utils/lineStyle'

const lines = ref<Line[]>([])
const loading = ref(true)
const error = ref<string | null>(null)
const saving = ref<string | null>(null)

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
  } catch {
    error.value = 'Linien konnten nicht geladen werden.'
  } finally {
    loading.value = false
  }
})

/** Startwert des Farbwählers: gepflegte Farbe, sonst neutraler Wert. */
function pickerValue(line: Line): string {
  return /^#[0-9a-f]{6}$/i.test(line.color ?? '') ? (line.color as string) : '#888888'
}

async function save(line: Line, color: string): Promise<void> {
  saving.value = line.route_short_name
  try {
    await setLineColor(line.route_short_name, color)
    line.color = color
  } catch {
    // 401 → Axios-Interceptor leitet zum Login.
  } finally {
    saving.value = null
  }
}

async function reset(line: Line): Promise<void> {
  saving.value = line.route_short_name
  try {
    await resetLineColor(line.route_short_name)
    line.color = null
  } catch {
    // ignore
  } finally {
    saving.value = null
  }
}
</script>

<template>
  <div class="min-h-full bg-slate-100">
    <AppHeader />

    <main class="mx-auto max-w-3xl px-6 py-8">
      <RouterLink to="/lines" class="text-sm text-slate-500 hover:text-slate-800">← Zu den Linien</RouterLink>
      <h2 class="mt-2 text-base font-semibold text-slate-900">Liniendesign — Farben pflegen</h2>
      <p class="mt-1 text-sm text-slate-500">
        Farbe je Linie. Nicht gepflegte Linien (z. B. neue) nutzen eine Ersatzfarbe und sind als
        <em>Fallback</em> markiert.
      </p>

      <div v-if="loading" class="mt-4 text-slate-500">Lädt…</div>
      <div v-else-if="error" class="mt-4 rounded-md bg-red-50 px-4 py-3 text-red-700">{{ error }}</div>

      <ul v-else class="mt-4 divide-y divide-slate-100 overflow-hidden rounded-lg bg-white shadow-sm">
        <li v-for="line in lines" :key="line.route_short_name" class="flex items-center gap-4 px-4 py-2.5">
          <LineBadge :line="line" />
          <span class="w-16 text-sm font-medium text-slate-700">Linie {{ line.route_short_name }}</span>

          <input
            type="color"
            class="h-8 w-10 cursor-pointer rounded border border-slate-200 bg-white"
            :value="pickerValue(line)"
            :disabled="saving === line.route_short_name"
            @change="save(line, ($event.target as HTMLInputElement).value)"
          />
          <span class="w-20 font-mono text-xs text-slate-500">{{ line.color ?? lineColor(line) }}</span>

          <span
            class="rounded-full px-2 py-0.5 text-xs font-medium"
            :class="line.color ? 'bg-green-100 text-green-800' : 'bg-slate-100 text-slate-500'"
          >
            {{ line.color ? 'gepflegt' : 'Fallback' }}
          </span>

          <button
            v-if="line.color"
            class="ml-auto text-xs text-slate-400 hover:text-slate-700"
            :disabled="saving === line.route_short_name"
            @click="reset(line)"
          >
            Zurücksetzen
          </button>
        </li>
      </ul>
    </main>
  </div>
</template>
