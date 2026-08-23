<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import AppHeader from '../components/AppHeader.vue'
import {
  acceptPeriodChangeOffer,
  createSchedulePeriod,
  declinePeriodChangeOffer,
  deleteSchedulePeriod,
  fetchPeriodChangeOffers,
  fetchSchedulePeriods,
  updateSchedulePeriod,
  type PeriodChangeOffer,
  type SchedulePeriod,
} from '../services/schedulePeriods'
import { formatDate } from '../utils/timezone'

const periods = ref<SchedulePeriod[]>([])
const offers = ref<PeriodChangeOffer[]>([])
const loading = ref(true)
const error = ref<string | null>(null)

// Systemvorschläge (§4.3) — je Vorschlag ein eigenes Label-Feld.
const offerLabels = ref<Record<number, string>>({})
const decidingId = ref<number | null>(null)

// Formular (Anlegen/Bearbeiten)
const editingId = ref<number | null>(null)
const form = ref({ label: '', valid_from: '' })
const formError = ref<string | null>(null)
const saving = ref(false)

const editing = computed(() => periods.value.find((p) => p.id === editingId.value) ?? null)

async function load(): Promise<void> {
  const [geladenePerioden, geladeneVorschlaege] = await Promise.all([
    fetchSchedulePeriods(),
    fetchPeriodChangeOffers(),
  ])
  periods.value = geladenePerioden
  offers.value = geladeneVorschlaege

  for (const offer of geladeneVorschlaege) {
    offerLabels.value[offer.id] ??= ''
  }
}

function anteil(offer: PeriodChangeOffer): string {
  return offer.share === null ? '—' : `${Math.round(offer.share * 100)} %`
}

async function acceptOffer(offer: PeriodChangeOffer): Promise<void> {
  const label = (offerLabels.value[offer.id] ?? '').trim()
  if (label === '') {
    error.value = 'Bitte zuerst eine Bezeichnung für die neue Periode eingeben.'
    return
  }

  decidingId.value = offer.id
  error.value = null
  try {
    await acceptPeriodChangeOffer(offer.id, label)
    await load()
  } catch (e: unknown) {
    error.value = fehlermeldung(e)
  } finally {
    decidingId.value = null
  }
}

async function declineOffer(offer: PeriodChangeOffer): Promise<void> {
  decidingId.value = offer.id
  error.value = null
  try {
    await declinePeriodChangeOffer(offer.id)
    await load()
  } catch (e: unknown) {
    error.value = fehlermeldung(e)
  } finally {
    decidingId.value = null
  }
}

onMounted(async () => {
  try {
    await load()
  } catch {
    error.value = 'Fahrplanperioden konnten nicht geladen werden.'
  } finally {
    loading.value = false
  }
})

function resetForm(): void {
  editingId.value = null
  form.value = { label: '', valid_from: '' }
  formError.value = null
}

function edit(period: SchedulePeriod): void {
  editingId.value = period.id
  form.value = { label: period.label, valid_from: period.valid_from }
  formError.value = null
}

function fehlermeldung(e: unknown): string {
  const message = (e as { response?: { data?: { error?: { message?: string } } } })?.response?.data?.error?.message
  return message ?? 'Speichern fehlgeschlagen.'
}

async function save(): Promise<void> {
  saving.value = true
  formError.value = null
  try {
    if (editingId.value === null) {
      await createSchedulePeriod(form.value)
    } else {
      await updateSchedulePeriod(editingId.value, form.value)
    }
    await load()
    resetForm()
  } catch (e: unknown) {
    formError.value = fehlermeldung(e)
  } finally {
    saving.value = false
  }
}

async function remove(period: SchedulePeriod): Promise<void> {
  if (!confirm(`Fahrplanperiode „${period.label}" löschen?`)) {
    return
  }
  try {
    await deleteSchedulePeriod(period.id)
    if (editingId.value === period.id) {
      resetForm()
    }
    await load()
  } catch (e: unknown) {
    error.value = fehlermeldung(e)
  }
}
</script>

<template>
  <div class="min-h-full bg-slate-100">
    <AppHeader />

    <main class="mx-auto max-w-6xl px-6 py-8">
      <div class="mb-6">
        <h1 class="text-2xl font-semibold text-slate-900">Fahrplanperioden</h1>
        <p class="mt-1 max-w-3xl text-sm text-slate-600">
          Die netzweiten, kuratierten Perioden — orientiert an den veröffentlichten MVB-Fahrplänen.
          Eine neue Periode setzt die Linien-Versionen zurück: Jede Linie beginnt darin wieder bei
          Version&nbsp;1. Die Versionen der Vorperiode bleiben als Historie erhalten.
        </p>
      </div>

      <p v-if="error" class="mb-4 rounded-md bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ error }}</p>
      <p v-if="loading" class="text-sm text-slate-500">Wird geladen …</p>

      <template v-else>
        <!-- Systemvorschläge: viele Linien haben am selben Tag gewechselt (§4.3) -->
        <section
          v-for="offer in offers"
          :key="offer.id"
          class="mb-6 rounded-lg border border-amber-300 bg-amber-50 p-5"
        >
          <h2 class="text-sm font-semibold text-amber-900">
            Möglicher Fahrplanwechsel zum {{ formatDate(offer.suggested_from) }}
          </h2>
          <p class="mt-1 text-sm text-amber-900">
            An diesem Tag haben
            <strong>{{ offer.changed_line_count }} von {{ offer.active_line_count }}</strong>
            verkehrenden Linien gleichzeitig ihren Fahrplan geändert ({{ anteil(offer) }}) — das
            spricht für einen echten Fahrplanwechsel statt für einzelne Baustellen.
          </p>
          <p class="mt-2 text-xs text-amber-800">Betroffen: {{ offer.lines.join(', ') }}</p>

          <!-- Ein Wechseltag am Rand des Feed-Fensters ruht auf einer einzigen Beobachtung.
               Ihn als Fahrplanwechsel festzuschreiben, wäre verfrüht (§5.4 b). -->
          <p
            v-if="offer.single_day_observation"
            class="mt-3 rounded-md border border-amber-400 bg-amber-100 px-3 py-2 text-xs text-amber-900"
          >
            <strong>Nur ein beobachteter Tag.</strong> Der Wechsel liegt am Rand des
            Feed-Fensters — dahinter reichen die Daten nicht. Ob das ein echter Fahrplanwechsel
            ist oder ein Randeffekt, zeigt erst der nächste Import. Bis dahin besser abwarten.
          </p>
          <p v-else class="mt-3 text-xs text-amber-800">
            Beobachtet bis {{ formatDate(offer.observed_until) }} — der neue Fahrplan hat sich
            über den Wechseltag hinaus bestätigt.
          </p>

          <div class="mt-4 flex flex-wrap items-end gap-3">
            <label class="flex-1 min-w-64 text-sm">
              <span class="mb-1 block font-medium text-amber-900">Bezeichnung der neuen Periode</span>
              <input
                v-model="offerLabels[offer.id]"
                type="text"
                maxlength="255"
                placeholder="Jahresfahrplan 2026/27"
                class="w-full rounded-md border border-amber-300 bg-white px-3 py-2"
              />
            </label>
            <button
              type="button"
              :disabled="decidingId === offer.id"
              class="rounded-md px-4 py-2 text-sm font-medium disabled:opacity-50"
              :class="offer.single_day_observation
                ? 'border border-amber-400 text-amber-900 hover:bg-amber-100'
                : 'bg-amber-700 text-white hover:bg-amber-800'"
              :title="offer.single_day_observation ? 'Beleglage dünn — nur ein beobachteter Tag' : undefined"
              @click="acceptOffer(offer)"
            >
              Periodenwechsel anlegen
            </button>
            <button
              type="button"
              :disabled="decidingId === offer.id"
              class="rounded-md border border-amber-300 px-4 py-2 text-sm font-medium text-amber-900 hover:bg-amber-100 disabled:opacity-50"
              @click="declineOffer(offer)"
            >
              Ablehnen
            </button>
          </div>
          <p class="mt-3 text-xs text-amber-800">
            Beim Anlegen werden die Linien-Versionen ab dem {{ formatDate(offer.suggested_from) }}
            zurückgenommen und durch den Periodenwechsel ersetzt — jede Linie startet dann wieder
            bei Version&nbsp;1. Ablehnen belässt sie als gewöhnliche Versionen in der laufenden
            Periode.
          </p>
        </section>

        <!-- Formular -->
        <section class="mb-8 rounded-lg border border-slate-200 bg-white p-5">
          <h2 class="mb-4 text-sm font-semibold text-slate-900">
            {{ editingId === null ? 'Neue Periode anlegen' : `Periode „${editing?.label}" ändern` }}
          </h2>

          <form class="flex flex-wrap items-end gap-4" @submit.prevent="save">
            <label class="flex-1 min-w-64 text-sm">
              <span class="mb-1 block font-medium text-slate-700">Bezeichnung</span>
              <input
                v-model="form.label"
                type="text"
                required
                maxlength="255"
                placeholder="Jahresfahrplan 2026/27"
                class="w-full rounded-md border border-slate-300 px-3 py-2"
              />
            </label>

            <label class="text-sm">
              <span class="mb-1 block font-medium text-slate-700">Beginn</span>
              <input
                v-model="form.valid_from"
                type="date"
                required
                class="rounded-md border border-slate-300 px-3 py-2"
              />
            </label>

            <div class="flex gap-2">
              <button
                type="submit"
                :disabled="saving"
                class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-50"
              >
                {{ editingId === null ? 'Anlegen' : 'Speichern' }}
              </button>
              <button
                v-if="editingId !== null"
                type="button"
                class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
                @click="resetForm"
              >
                Abbrechen
              </button>
            </div>
          </form>

          <p v-if="formError" class="mt-3 text-sm text-rose-700">{{ formError }}</p>
          <p class="mt-3 text-xs text-slate-500">
            Ein Enddatum wird nicht eingegeben: Eine Periode gilt bis zum Vortag der nächsten, die
            jüngste bleibt offen.
          </p>
        </section>

        <!-- Liste -->
        <section class="overflow-hidden rounded-lg border border-slate-200 bg-white">
          <table class="w-full text-sm">
            <thead class="border-b border-slate-200 bg-slate-50 text-left text-slate-600">
              <tr>
                <th class="px-5 py-3 font-medium">Bezeichnung</th>
                <th class="px-5 py-3 font-medium">Gültigkeit</th>
                <th class="px-5 py-3 font-medium">Herkunft</th>
                <th class="px-5 py-3 text-right font-medium">Linien-Versionen</th>
                <th class="px-5 py-3"></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="period in periods" :key="period.id" class="hover:bg-slate-50">
                <td class="px-5 py-3">
                  <span class="font-medium text-slate-900">{{ period.label }}</span>
                  <span
                    v-if="period.status === 'current'"
                    class="ml-2 rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800"
                  >
                    laufend
                  </span>
                </td>
                <td class="px-5 py-3 text-slate-700">
                  {{ formatDate(period.valid_from) }} –
                  <span v-if="period.valid_to">{{ formatDate(period.valid_to) }}</span>
                  <span v-else class="text-slate-400">offen</span>
                </td>
                <td class="px-5 py-3 text-slate-600">{{ period.created_via_label }}</td>
                <td class="px-5 py-3 text-right tabular-nums text-slate-700">
                  {{ period.line_version_count }}
                </td>
                <td class="px-5 py-3 text-right whitespace-nowrap">
                  <button
                    class="rounded-md px-2 py-1 text-slate-600 hover:bg-slate-100"
                    @click="edit(period)"
                  >
                    Bearbeiten
                  </button>
                  <button
                    v-if="period.is_deletable"
                    class="ml-1 rounded-md px-2 py-1 text-rose-700 hover:bg-rose-50"
                    @click="remove(period)"
                  >
                    Löschen
                  </button>
                  <!-- Perioden mit Versionen sind nicht löschbar: Beobachtete Fahrplan-Historie
                       ist nicht wiederbeschaffbar. -->
                  <span v-else class="ml-1 px-2 py-1 text-xs text-slate-400" title="Trägt Fahrplan-Historie">
                    nicht löschbar
                  </span>
                </td>
              </tr>
              <tr v-if="periods.length === 0">
                <td colspan="5" class="px-5 py-6 text-center text-slate-500">
                  Noch keine Periode — die erste entsteht automatisch beim ersten Import.
                </td>
              </tr>
            </tbody>
          </table>
        </section>
      </template>
    </main>
  </div>
</template>
