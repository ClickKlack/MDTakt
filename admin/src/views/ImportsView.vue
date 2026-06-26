<script setup lang="ts">
import { onMounted, ref } from 'vue'
import AppHeader from '../components/AppHeader.vue'
import { fetchImportStatus, type ImportStatus, type ImportStatusResponse } from '../services/imports'
import { formatDate, formatDateTime, formatFeedVersion } from '../utils/timezone'

const PER_PAGE = 10

const status = ref<ImportStatusResponse | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)
const page = ref(1)

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    status.value = await fetchImportStatus(page.value, PER_PAGE)
  } catch {
    error.value = 'Daten konnten nicht geladen werden.'
  } finally {
    loading.value = false
  }
}

function goTo(target: number): void {
  const last = status.value?.pagination.last_page ?? 1
  const clamped = Math.min(Math.max(target, 1), last)
  if (clamped !== page.value) {
    page.value = clamped
    void load()
  }
}

onMounted(load)

const badgeClass: Record<ImportStatus, string> = {
  success: 'bg-green-100 text-green-800',
  failed: 'bg-red-100 text-red-800',
  running: 'bg-amber-100 text-amber-800',
}

const tableLabels: Record<string, string> = {
  routes: 'Linien',
  stops: 'Haltestellen',
  trips: 'Fahrten',
  stop_times: 'Haltezeiten',
  calendar: 'Kalender',
  calendar_dates: 'Kalender-Ausnahmen',
}

function num(value: number | undefined | null): string {
  return value == null ? '—' : value.toLocaleString('de-DE')
}
</script>

<template>
  <div class="min-h-full bg-slate-100">
    <AppHeader />

    <main class="mx-auto max-w-6xl space-y-8 px-6 py-8">
      <div v-if="loading && !status" class="text-slate-500">Lädt…</div>
      <div v-else-if="error" class="rounded-md bg-red-50 px-4 py-3 text-red-700">{{ error }}</div>

      <template v-else-if="status">
        <!-- Datenstand -->
        <section>
          <div class="flex items-center justify-between">
            <h2 class="text-base font-semibold text-slate-900">Datenstand</h2>
            <button
              class="text-sm text-slate-500 hover:text-slate-800 disabled:opacity-50"
              :disabled="loading"
              @click="load"
            >
              {{ loading ? 'Aktualisiere…' : 'Aktualisieren' }}
            </button>
          </div>

          <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
            <div
              v-for="(count, key) in status.current.tables"
              :key="key"
              class="rounded-lg bg-white p-4 shadow-sm"
            >
              <div class="text-2xl font-semibold text-slate-900">{{ num(count) }}</div>
              <div class="text-xs text-slate-500">{{ tableLabels[key] ?? key }}</div>
            </div>
          </div>

          <p class="mt-3 text-sm text-slate-500">
            Letzter erfolgreicher Import:
            <strong>{{ formatDateTime(status.current.last_success_at) }}</strong>
            <template v-if="status.current.feed_version"> · Feed {{ formatFeedVersion(status.current.feed_version) }}</template>
            <template v-if="status.current.feed_start_date">
              · gültig {{ formatDate(status.current.feed_start_date) }} – {{ formatDate(status.current.feed_end_date) }}
            </template>
          </p>
        </section>

        <!-- Import-Historie -->
        <section>
          <h2 class="text-base font-semibold text-slate-900">Import-Historie</h2>

          <div class="mt-3 overflow-x-auto rounded-lg bg-white shadow-sm">
            <table class="w-full text-left text-sm">
              <thead class="border-b border-slate-200 text-xs uppercase text-slate-500">
                <tr>
                  <th class="px-4 py-2">Status</th>
                  <th class="px-4 py-2">Start</th>
                  <th class="px-4 py-2">Ende</th>
                  <th class="px-4 py-2">Feed</th>
                  <th class="px-4 py-2 text-right">Fahrten</th>
                  <th class="px-4 py-2">Fehler</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="run in status.runs"
                  :key="run.id"
                  class="border-b border-slate-100 last:border-0"
                >
                  <td class="px-4 py-2">
                    <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="badgeClass[run.status]">
                      {{ run.status }}
                    </span>
                  </td>
                  <td class="px-4 py-2 text-slate-600">{{ formatDateTime(run.started_at) }}</td>
                  <td class="px-4 py-2 text-slate-600">{{ formatDateTime(run.finished_at) }}</td>
                  <td class="px-4 py-2 text-slate-600">{{ formatFeedVersion(run.feed_version) }}</td>
                  <td class="px-4 py-2 text-right text-slate-600">{{ num(run.counts?.trips) }}</td>
                  <td class="px-4 py-2 text-red-600">{{ run.error_message ?? '' }}</td>
                </tr>
                <tr v-if="status.runs.length === 0">
                  <td colspan="6" class="px-4 py-6 text-center text-slate-400">Noch keine Importe.</td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- Seitennavigation -->
          <div
            v-if="status.pagination.last_page > 1"
            class="mt-3 flex items-center justify-between text-sm text-slate-600"
          >
            <span>
              Seite {{ status.pagination.current_page }} von {{ status.pagination.last_page }}
              <span class="text-slate-400">({{ status.pagination.total }} Läufe)</span>
            </span>
            <div class="flex gap-2">
              <button
                class="rounded-md border border-slate-300 px-3 py-1.5 font-medium hover:bg-slate-50 disabled:opacity-40"
                :disabled="status.pagination.current_page <= 1 || loading"
                @click="goTo(status.pagination.current_page - 1)"
              >
                Zurück
              </button>
              <button
                class="rounded-md border border-slate-300 px-3 py-1.5 font-medium hover:bg-slate-50 disabled:opacity-40"
                :disabled="status.pagination.current_page >= status.pagination.last_page || loading"
                @click="goTo(status.pagination.current_page + 1)"
              >
                Weiter
              </button>
            </div>
          </div>
        </section>
      </template>
    </main>
  </div>
</template>
