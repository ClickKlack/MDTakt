<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import AppHeader from '../components/AppHeader.vue'
import {
  fetchStopGroup,
  fetchStopGroups,
  mergeStopGroup,
  renameStopGroup,
  detachStop,
  type StopGroup,
  type StopGroupDetail,
} from '../services/stopGroups'

const haltestellen = ref<StopGroup[]>([])
const detail = ref<StopGroupDetail | null>(null)

const suche = ref('')
const nurEndstellen = ref(false)
const nurEinseitige = ref(false)
const gewaehlt = ref<number | null>(null)
const neuerName = ref('')

const loading = ref(true)
const loadingDetail = ref(false)
const busy = ref(false)
const error = ref<string | null>(null)

const gefiltert = computed(() => {
  const begriff = suche.value.trim().toLowerCase()

  return haltestellen.value
    .filter((h) => (nurEinseitige.value ? h.one_sided : true))
    .filter(
      (h) =>
        begriff === '' ||
        h.name.toLowerCase().includes(begriff) ||
        h.stops.some((s) => s.name.toLowerCase().includes(begriff)),
    )
    .slice(0, 120)
})

const einseitige = computed(() => haltestellen.value.filter((h) => h.one_sided).length)
const zusammengesetzte = computed(() => haltestellen.value.filter((h) => h.stop_count > 1).length)

onMounted(() => void lade())

async function lade(): Promise<void> {
  loading.value = true
  try {
    haltestellen.value = await fetchStopGroups(null, nurEndstellen.value)
  } catch {
    error.value = 'Die Haltestellen konnten nicht geladen werden.'
  } finally {
    loading.value = false
  }
}

async function oeffne(id: number): Promise<void> {
  gewaehlt.value = id
  loadingDetail.value = true
  error.value = null

  try {
    detail.value = await fetchStopGroup(id)
    neuerName.value = detail.value.name
  } catch {
    error.value = 'Die Haltestelle konnte nicht geladen werden.'
  } finally {
    loadingDetail.value = false
  }
}

function meldung(e: unknown, fallback: string): string {
  const antwort = (e as { response?: { data?: { error?: { message?: string } } } }).response
  return antwort?.data?.error?.message ?? fallback
}

async function fuegeEin(quelleId: number): Promise<void> {
  if (gewaehlt.value === null) {
    return
  }

  busy.value = true
  error.value = null

  try {
    await mergeStopGroup(gewaehlt.value, quelleId)
    await lade()
    await oeffne(gewaehlt.value)
  } catch (e: unknown) {
    error.value = meldung(e, 'Die Haltestellen konnten nicht zusammengelegt werden.')
  } finally {
    busy.value = false
  }
}

async function loese(stopId: number): Promise<void> {
  if (gewaehlt.value === null) {
    return
  }

  busy.value = true
  error.value = null

  try {
    await detachStop(gewaehlt.value, stopId)
    await lade()
    await oeffne(gewaehlt.value)
  } catch (e: unknown) {
    error.value = meldung(e, 'Der Halt konnte nicht gelöst werden.')
  } finally {
    busy.value = false
  }
}

async function benenneUm(): Promise<void> {
  if (gewaehlt.value === null || neuerName.value.trim() === '') {
    return
  }

  busy.value = true
  error.value = null

  try {
    await renameStopGroup(gewaehlt.value, neuerName.value.trim())
    await lade()
    await oeffne(gewaehlt.value)
  } catch (e: unknown) {
    error.value = meldung(e, 'Die Haltestelle konnte nicht umbenannt werden.')
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="min-h-full bg-slate-100">
    <AppHeader />

    <main class="mx-auto max-w-6xl px-6 py-8">
      <h1 class="text-2xl font-semibold text-slate-900">Haltestellen</h1>
      <p class="mt-1 max-w-3xl text-sm text-slate-600">
        Eine Haltestelle fasst die Halte zusammen, die im Betrieb derselbe Ort sind — üblicherweise die
        Richtungs-Bahnsteige. Der Fahrplan-Import trennt sie, weil er nur verschmilzt, was näher als 12 Meter
        beieinander liegt und gleich heißt. Für die Umlauf-Pflege muss aber eine Fahrt, die auf dem einen Bahnsteig
        endet, an eine anschließen können, die auf dem anderen beginnt.
      </p>
      <p class="mt-2 max-w-3xl text-sm text-slate-600">
        Gleichnamige Halte werden automatisch zusammengefasst. Hier ist der Rest zu pflegen: „Rothensee" und
        „Rothensee (Schleife)" sind dieselbe Haltestelle, heißen aber verschieden. Eine Zuordnung von Hand bleibt
        bestehen — der nächste Import nimmt sie nicht zurück.
      </p>

      <p v-if="error" class="mt-4 rounded-md bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ error }}</p>
      <p v-if="loading" class="mt-4 text-sm text-slate-500">Wird geladen …</p>

      <template v-else>
        <div class="mt-6 flex flex-wrap gap-6 text-sm">
          <span class="text-slate-600">
            <strong class="text-slate-900">{{ haltestellen.length }}</strong> Haltestellen
          </span>
          <span class="text-slate-600">
            <strong class="text-slate-900">{{ zusammengesetzte }}</strong> mit mehreren Bahnsteigen
          </span>
          <span :class="einseitige > 0 ? 'text-amber-800' : 'text-emerald-700'">
            <strong>{{ einseitige }}</strong> einseitig — dort endet nur oder beginnt nur etwas
          </span>
        </div>

        <div class="mt-4 grid gap-6 lg:grid-cols-[1fr_1.2fr]">
          <!-- Liste -->
          <section>
            <input
              v-model="suche"
              type="search"
              placeholder="Haltestelle oder Halt suchen …"
              class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm focus:border-slate-800 focus:outline-none"
            />

            <div class="mt-2 flex flex-wrap gap-4 text-xs text-slate-600">
              <label class="flex items-center gap-1.5">
                <input v-model="nurEndstellen" type="checkbox" @change="lade" />
                Nur Endstellen
              </label>
              <label class="flex items-center gap-1.5">
                <input v-model="nurEinseitige" type="checkbox" />
                Nur einseitige
              </label>
            </div>

            <p v-if="gefiltert.length === 0" class="mt-3 rounded-lg bg-slate-50 px-4 py-3 text-sm text-slate-600">
              Keine Haltestelle gefunden.
            </p>

            <ul v-else class="mt-3 max-h-[32rem] space-y-1 overflow-y-auto pr-1">
              <li v-for="halt in gefiltert" :key="halt.id">
                <button
                  type="button"
                  class="w-full rounded-lg border px-3 py-2 text-left transition"
                  :class="
                    gewaehlt === halt.id
                      ? 'border-slate-800 bg-white'
                      : 'border-slate-200 bg-white hover:border-slate-400'
                  "
                  @click="oeffne(halt.id)"
                >
                  <span class="flex items-baseline justify-between gap-2">
                    <span class="truncate text-sm font-medium text-slate-900">{{ halt.name }}</span>
                    <span class="shrink-0 text-xs text-slate-500">
                      {{ halt.stop_count }} {{ halt.stop_count === 1 ? 'Halt' : 'Halte' }}
                    </span>
                  </span>
                  <span class="mt-0.5 flex flex-wrap gap-2 text-xs">
                    <span v-if="halt.ending_lines.length" class="text-slate-500">
                      endet: {{ halt.ending_lines.join(', ') }}
                    </span>
                    <span v-if="halt.starting_lines.length" class="text-slate-500">
                      beginnt: {{ halt.starting_lines.join(', ') }}
                    </span>
                    <span v-if="halt.one_sided" class="rounded bg-amber-50 px-1.5 text-amber-900">einseitig</span>
                    <span v-if="halt.manual_count > 0" class="rounded bg-sky-50 px-1.5 text-sky-900">
                      {{ halt.manual_count }}× von Hand
                    </span>
                  </span>
                </button>
              </li>
            </ul>
          </section>

          <!-- Detail -->
          <section>
            <p v-if="gewaehlt === null" class="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900">
              Wähle links eine Haltestelle. Am dringendsten sind die einseitigen: Dort endet nur oder beginnt nur
              etwas, was meist heißt, dass der Gegen-Bahnsteig noch als eigene Haltestelle geführt wird.
            </p>

            <p v-else-if="loadingDetail" class="text-sm text-slate-500">Wird geladen …</p>

            <div v-else-if="detail" class="rounded-lg bg-white p-4 shadow-sm">
              <div class="flex flex-wrap items-end gap-2">
                <div class="flex-1">
                  <label class="block text-xs font-medium uppercase tracking-wide text-slate-500">Name</label>
                  <input
                    v-model="neuerName"
                    type="text"
                    class="mt-1 w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm focus:border-slate-800 focus:outline-none"
                  />
                </div>
                <button
                  type="button"
                  class="rounded-md bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-900 disabled:opacity-50"
                  :disabled="busy || neuerName.trim() === '' || neuerName === detail.name"
                  @click="benenneUm"
                >
                  Umbenennen
                </button>
              </div>

              <h3 class="mt-4 text-xs font-medium uppercase tracking-wide text-slate-500">
                Halte dieser Haltestelle
              </h3>
              <ul class="mt-1 space-y-1">
                <li
                  v-for="halt in detail.stops"
                  :key="halt.id"
                  class="flex flex-wrap items-center gap-2 rounded-md bg-slate-50 px-3 py-1.5 text-sm"
                >
                  <span class="text-slate-900">{{ halt.name }}</span>
                  <span v-if="halt.ending_lines.length" class="text-xs text-slate-500">
                    endet: {{ halt.ending_lines.join(', ') }}
                  </span>
                  <span v-if="halt.starting_lines.length" class="text-xs text-slate-500">
                    beginnt: {{ halt.starting_lines.join(', ') }}
                  </span>
                  <span v-if="halt.assigned_via === 'manual'" class="rounded bg-sky-50 px-1.5 text-xs text-sky-900">
                    von Hand
                  </span>
                  <button
                    v-if="detail.stops.length > 1"
                    type="button"
                    class="ml-auto text-xs text-slate-500 underline underline-offset-2 hover:text-slate-900 disabled:opacity-50"
                    :disabled="busy"
                    @click="loese(halt.id)"
                  >
                    Herauslösen
                  </button>
                </li>
              </ul>

              <h3 class="mt-5 text-xs font-medium uppercase tracking-wide text-slate-500">In der Nähe</h3>
              <p class="mt-1 text-xs text-slate-500">
                Vorschläge, keine Entscheidung: Ein naher Punkt kann der Gegen-Bahnsteig sein — oder ein anderer
                Ort, zu dem das Fahrzeug als Betriebsfahrt fährt. Einfügen legt die andere Haltestelle in diese
                hinein.
              </p>

              <p v-if="detail.suggestions.length === 0" class="mt-2 text-sm text-slate-500">
                Keine andere Haltestelle in Laufweite.
              </p>

              <ul v-else class="mt-2 space-y-1">
                <li
                  v-for="vorschlag in detail.suggestions"
                  :key="vorschlag.id"
                  class="flex items-center gap-2 rounded-md border border-slate-200 px-3 py-1.5 text-sm"
                >
                  <span class="text-slate-900">{{ vorschlag.name }}</span>
                  <span class="text-xs tabular-nums text-slate-500">{{ vorschlag.distance_meters }} m</span>
                  <button
                    type="button"
                    class="ml-auto rounded-md border border-slate-300 px-2 py-0.5 text-xs text-slate-700 hover:border-slate-500 disabled:opacity-50"
                    :disabled="busy"
                    @click="fuegeEin(vorschlag.id)"
                  >
                    Hier einfügen
                  </button>
                </li>
              </ul>
            </div>
          </section>
        </div>
      </template>
    </main>
  </div>
</template>
