<script setup lang="ts">
import { onMounted, ref } from 'vue'
import AppHeader from '../components/AppHeader.vue'
import {
  createSchoolHoliday,
  deleteSchoolHoliday,
  fetchHolidays,
  fetchSchoolHolidays,
  updateSchoolHoliday,
  type Holiday,
  type SchoolHoliday,
} from '../services/calendar'
import { formatDate } from '../utils/timezone'

const holidays = ref<SchoolHoliday[]>([])
const feiertage = ref<Holiday[]>([])
const year = ref(new Date().getFullYear())
const loading = ref(true)
const error = ref<string | null>(null)

// Formular (Anlegen/Bearbeiten)
const editingId = ref<number | null>(null)
const form = ref({ name: '', start_date: '', end_date: '' })
const formError = ref<string | null>(null)
const saving = ref(false)

async function loadSchoolHolidays(): Promise<void> {
  holidays.value = await fetchSchoolHolidays()
}

async function loadFeiertage(): Promise<void> {
  feiertage.value = (await fetchHolidays(year.value)).holidays
}

onMounted(async () => {
  try {
    await Promise.all([loadSchoolHolidays(), loadFeiertage()])
  } catch {
    error.value = 'Daten konnten nicht geladen werden.'
  } finally {
    loading.value = false
  }
})

function resetForm(): void {
  editingId.value = null
  form.value = { name: '', start_date: '', end_date: '' }
  formError.value = null
}

function edit(holiday: SchoolHoliday): void {
  editingId.value = holiday.id
  form.value = { name: holiday.name, start_date: holiday.start_date, end_date: holiday.end_date }
  formError.value = null
}

async function save(): Promise<void> {
  saving.value = true
  formError.value = null
  try {
    if (editingId.value === null) {
      await createSchoolHoliday(form.value)
    } else {
      await updateSchoolHoliday(editingId.value, form.value)
    }
    await loadSchoolHolidays()
    resetForm()
  } catch (e: unknown) {
    const message = (e as { response?: { data?: { error?: { message?: string } } } })?.response?.data?.error?.message
    formError.value = message ?? 'Speichern fehlgeschlagen.'
  } finally {
    saving.value = false
  }
}

async function remove(holiday: SchoolHoliday): Promise<void> {
  if (!confirm(`Ferienzeit „${holiday.name}" löschen?`)) {
    return
  }
  await deleteSchoolHoliday(holiday.id)
  if (editingId.value === holiday.id) {
    resetForm()
  }
  await loadSchoolHolidays()
}
</script>

<template>
  <div class="min-h-full bg-slate-100">
    <AppHeader />

    <main class="mx-auto max-w-3xl space-y-10 px-6 py-8">
      <div v-if="loading" class="text-slate-500">Lädt…</div>
      <div v-else-if="error" class="rounded-md bg-red-50 px-4 py-3 text-red-700">{{ error }}</div>

      <template v-else>
        <!-- Schulferien (CRUD) -->
        <section>
          <h2 class="text-base font-semibold text-slate-900">Schulferien (Sachsen-Anhalt)</h2>
          <p class="mt-1 text-sm text-slate-500">Grundlage für den Fahrplantyp „Mo-Fr (Ferien)".</p>

          <form class="mt-4 flex flex-wrap items-end gap-3 rounded-lg bg-white p-4 shadow-sm" @submit.prevent="save">
            <label class="flex flex-col text-xs font-medium text-slate-600">
              Bezeichnung
              <input v-model="form.name" required class="mt-1 w-48 rounded-md border border-slate-300 px-2 py-1.5 text-sm" />
            </label>
            <label class="flex flex-col text-xs font-medium text-slate-600">
              Von
              <input v-model="form.start_date" type="date" required class="mt-1 rounded-md border border-slate-300 px-2 py-1.5 text-sm" />
            </label>
            <label class="flex flex-col text-xs font-medium text-slate-600">
              Bis
              <input v-model="form.end_date" type="date" required class="mt-1 rounded-md border border-slate-300 px-2 py-1.5 text-sm" />
            </label>
            <button
              type="submit"
              :disabled="saving"
              class="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-50"
            >
              {{ editingId === null ? 'Hinzufügen' : 'Speichern' }}
            </button>
            <button
              v-if="editingId !== null"
              type="button"
              class="text-sm text-slate-500 hover:text-slate-800"
              @click="resetForm"
            >
              Abbrechen
            </button>
            <p v-if="formError" class="w-full text-sm text-red-700">{{ formError }}</p>
          </form>

          <ul class="mt-3 divide-y divide-slate-100 overflow-hidden rounded-lg bg-white shadow-sm">
            <li v-for="h in holidays" :key="h.id" class="flex items-center gap-4 px-4 py-2.5 text-sm">
              <span class="flex-1 font-medium text-slate-800">{{ h.name }}</span>
              <span class="text-slate-500">{{ formatDate(h.start_date) }} – {{ formatDate(h.end_date) }}</span>
              <button class="text-xs text-slate-400 hover:text-slate-700" @click="edit(h)">Bearbeiten</button>
              <button class="text-xs text-red-400 hover:text-red-700" @click="remove(h)">Löschen</button>
            </li>
            <li v-if="holidays.length === 0" class="px-4 py-6 text-center text-slate-400">Noch keine Ferienzeiten.</li>
          </ul>
        </section>

        <!-- Feiertage (read-only) -->
        <section>
          <div class="flex items-center justify-between">
            <div>
              <h2 class="text-base font-semibold text-slate-900">Feiertage (Sachsen-Anhalt)</h2>
              <p class="mt-1 text-sm text-slate-500">Automatisch berechnet — nicht editierbar.</p>
            </div>
            <label class="text-sm text-slate-600">
              Jahr
              <input
                v-model.number="year"
                type="number"
                class="ml-2 w-24 rounded-md border border-slate-300 px-2 py-1.5 text-sm"
                @change="loadFeiertage"
              />
            </label>
          </div>

          <ul class="mt-3 divide-y divide-slate-100 overflow-hidden rounded-lg bg-white shadow-sm">
            <li v-for="f in feiertage" :key="f.date" class="flex items-center gap-4 px-4 py-2 text-sm">
              <span class="w-28 tabular-nums text-slate-500">{{ formatDate(f.date) }}</span>
              <span class="text-slate-800">{{ f.name }}</span>
            </li>
          </ul>
        </section>
      </template>
    </main>
  </div>
</template>
