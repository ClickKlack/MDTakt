<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import AppHeader from '../components/AppHeader.vue'
import {
  createDepot,
  deleteDepot,
  fetchDepots,
  updateDepot,
  type Depot,
  type DepotInput,
} from '../services/depots'
import { fetchStopGroups, type StopGroup } from '../services/stopGroups'

const hoefe = ref<Depot[]>([])
const haltestellen = ref<StopGroup[]>([])

const loading = ref(true)
const busy = ref(false)
const error = ref<string | null>(null)
const erfolg = ref<string | null>(null)

/** Die Zeile, die gerade bearbeitet wird. `0` = ein neuer Hof. */
const bearbeitet = ref<number | null>(null)
const entwurf = ref<DepotInput>(leererEntwurf())

function leererEntwurf(): DepotInput {
  return { name: '', short_name: null, stop_group_ids: [], modes: [], active: true, note: null }
}

/** Filter der Haltestellen-Auswahl — 315 Namen als Liste sind nicht zu bedienen. */
const haltSuche = ref('')

function meldung(e: unknown, fallback: string): string {
  const antwort = (e as { response?: { data?: { error?: { message?: string } } } }).response
  return antwort?.data?.error?.message ?? fallback
}

async function lade(): Promise<void> {
  loading.value = true

  try {
    // Alle Haltestellen, nicht nur Endstellen: Ein Betriebshof muss keine Fahrt beginnen oder
    // enden lassen, um der Ort zu sein, an dem er liegt.
    const [h, g] = await Promise.all([fetchDepots(), fetchStopGroups()])
    hoefe.value = h
    haltestellen.value = g
  } catch (e: unknown) {
    error.value = meldung(e, 'Die Betriebshöfe konnten nicht geladen werden.')
  } finally {
    loading.value = false
  }
}

onMounted(lade)

const haltestellenNachName = computed(() =>
  haltestellen.value.slice().sort((a, b) => a.name.localeCompare(b.name, 'de')),
)

function beginneNeu(): void {
  bearbeitet.value = 0
  entwurf.value = leererEntwurf()
  haltSuche.value = ''
  erfolg.value = null
  error.value = null
}

function bearbeite(hof: Depot): void {
  bearbeitet.value = hof.id
  entwurf.value = {
    name: hof.name,
    short_name: hof.short_name,
    stop_group_ids: hof.stop_groups.map((g) => g.id),
    modes: [...hof.modes],
    active: hof.active,
    note: hof.note,
  }
  haltSuche.value = ''
  erfolg.value = null
  error.value = null
}

/**
 * Eine Haltestelle zu- oder abwählen.
 *
 * Mehrere sind der Regelfall: Die Westerhüsener Fahrten rücken fast immer an der Schleswiger
 * Straße aus, nicht am Hof selbst — der Weg dorthin ist die Betriebsfahrt, die im Fahrplan gar
 * nicht steht.
 */
function schalteHaltestelle(id: number): void {
  const aktuell = entwurf.value.stop_group_ids ?? []
  entwurf.value.stop_group_ids = aktuell.includes(id)
    ? aktuell.filter((g) => g !== id)
    : [...aktuell, id]
}

/** Die gewählten zuerst, danach die Treffer der Suche — sonst sucht man das Gewählte. */
const haltAuswahl = computed(() => {
  const gewaehlt = new Set(entwurf.value.stop_group_ids ?? [])
  const begriff = haltSuche.value.trim().toLowerCase()

  const treffer = haltestellenNachName.value.filter(
    (h) => !gewaehlt.has(h.id) && begriff !== '' && h.name.toLowerCase().includes(begriff),
  )

  return [...haltestellenNachName.value.filter((h) => gewaehlt.has(h.id)), ...treffer.slice(0, 20)]
})

function brich(): void {
  bearbeitet.value = null
  entwurf.value = leererEntwurf()
  haltSuche.value = ''
}

/** Ein Verkehrsmittel zu- oder abwählen. Keines gewählt heißt „gilt für alle". */
function schalteMittel(mittel: 'tram' | 'bus'): void {
  const aktuell = entwurf.value.modes ?? []
  entwurf.value.modes = aktuell.includes(mittel)
    ? aktuell.filter((m) => m !== mittel)
    : [...aktuell, mittel]
}

async function speichere(): Promise<void> {
  if (entwurf.value.name.trim() === '') {
    error.value = 'Ein Betriebshof braucht einen Namen.'
    return
  }

  busy.value = true
  error.value = null

  try {
    if (bearbeitet.value === 0) {
      const neu = await createDepot(entwurf.value)
      erfolg.value = `Betriebshof „${neu.name}" angelegt.`
    } else if (bearbeitet.value !== null) {
      const geaendert = await updateDepot(bearbeitet.value, entwurf.value)
      erfolg.value = `Betriebshof „${geaendert.name}" gespeichert.`
    }

    brich()
    await lade()
  } catch (e: unknown) {
    error.value = meldung(e, 'Der Betriebshof konnte nicht gespeichert werden.')
  } finally {
    busy.value = false
  }
}

async function loesche(hof: Depot): Promise<void> {
  busy.value = true
  error.value = null

  try {
    await deleteDepot(hof.id)
    erfolg.value = `Betriebshof „${hof.name}" gelöscht.`
    await lade()
  } catch (e: unknown) {
    // Der übliche Fall ist 409: Am Hof hängen Entscheidungen. Die Engine sagt dann, dass
    // Stilllegen der Weg ist — wir reichen ihre Begründung durch, statt sie zu doppeln.
    error.value = meldung(e, 'Der Betriebshof konnte nicht gelöscht werden.')
  } finally {
    busy.value = false
  }
}

async function schalteStilllegung(hof: Depot): Promise<void> {
  busy.value = true
  error.value = null

  try {
    await updateDepot(hof.id, {
      name: hof.name,
      short_name: hof.short_name,
      stop_group_ids: hof.stop_groups.map((g) => g.id),
      modes: hof.modes,
      active: !hof.active,
      note: hof.note,
    })
    erfolg.value = hof.active
      ? `„${hof.name}" ist stillgelegt — er bleibt an bestehenden Fahrten lesbar.`
      : `„${hof.name}" nimmt wieder Fahrten auf.`
    await lade()
  } catch (e: unknown) {
    error.value = meldung(e, 'Die Stilllegung konnte nicht geändert werden.')
  } finally {
    busy.value = false
  }
}

function mittelText(hof: Depot): string {
  if (hof.modes.length === 0) {
    return 'Tram und Bus'
  }
  return hof.modes.map((m) => (m === 'tram' ? 'Tram' : 'Bus')).join(' und ')
}
</script>

<template>
  <div class="min-h-full bg-slate-100">
    <AppHeader />

    <main class="mx-auto max-w-4xl px-6 py-8">
      <h1 class="text-2xl font-semibold text-slate-900">Betriebshöfe</h1>
      <p class="mt-1 max-w-3xl text-sm text-slate-600">
        Die Höfe, aus denen ausgerückt und in die eingerückt wird. Sie stehen an der einzelnen
        Entscheidung, nicht am Umlauf — <strong>Aus- und Einrückhof sind nicht zwangsläufig
        derselbe</strong>: Ein Fahrzeug rückt morgens aus Nord aus und abends in Westerhüsen ein,
        wenn der Umlauf es dorthin trägt.
      </p>
      <p class="mt-2 max-w-3xl text-sm text-slate-600">
        Ordne einem Hof die Haltestellen zu, an denen seine Fahrzeuge aus- und einrücken. Wer dort eine
        Fahrt als Betriebsfahrt markiert, bekommt den Hof gleich mitgesetzt — sofern das Verkehrsmittel
        passt. <strong>Westerhüsen gehört deshalb auch die Schleswiger Straße:</strong> Von dort rücken die
        Fahrten fast immer aus, der Weg zum Hof steht im Fahrplan gar nicht. Passen mehrere Höfe, wird nichts
        gesetzt — eine offene Angabe ist richtiger als eine geratene.
      </p>

      <p v-if="loading" class="mt-4 text-sm text-slate-500">Wird geladen …</p>

      <template v-else>
        <p v-if="error" class="mt-4 rounded-md bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ error }}</p>
        <p v-if="erfolg" class="mt-4 rounded-md bg-emerald-50 px-4 py-2 text-sm text-emerald-900">{{ erfolg }}</p>

        <div class="mt-6 space-y-2">
          <div
            v-for="hof in hoefe"
            :key="hof.id"
            class="rounded-lg bg-white p-4 shadow-sm"
            :class="hof.active ? '' : 'opacity-60'"
          >
            <!-- Bearbeitung dieser Zeile -->
            <template v-if="bearbeitet === hof.id">
              <div class="grid gap-3 sm:grid-cols-2">
                <label class="block">
                  <span class="block text-xs font-medium uppercase tracking-wide text-slate-500">Name</span>
                  <input
                    v-model="entwurf.name"
                    type="text"
                    maxlength="120"
                    class="mt-1 w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm focus:border-slate-800 focus:outline-none"
                  />
                </label>
                <label class="block">
                  <span class="block text-xs font-medium uppercase tracking-wide text-slate-500">
                    Kurzform
                    <span class="font-normal normal-case text-slate-400">— für enge Stellen</span>
                  </span>
                  <input
                    v-model="entwurf.short_name"
                    type="text"
                    maxlength="16"
                    placeholder="z. B. Nord"
                    class="mt-1 w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm focus:border-slate-800 focus:outline-none"
                  />
                </label>
              </div>

              <div class="mt-3">
                <span class="block text-xs font-medium uppercase tracking-wide text-slate-500">
                  Haltestellen
                  <span class="font-normal normal-case text-slate-400">
                    — hier wird der Hof beim Markieren von selbst gesetzt
                  </span>
                </span>
                <input
                  v-model="haltSuche"
                  type="search"
                  placeholder="Haltestelle suchen …"
                  class="mt-1 w-full max-w-sm rounded-md border border-slate-300 px-3 py-1.5 text-sm focus:border-slate-800 focus:outline-none"
                />
                <div class="mt-2 flex flex-wrap gap-1.5">
                  <button
                    v-for="halt in haltAuswahl"
                    :key="halt.id"
                    type="button"
                    class="rounded-md border px-2.5 py-1 text-sm transition"
                    :class="
                      entwurf.stop_group_ids?.includes(halt.id)
                        ? 'border-slate-800 bg-slate-800 font-medium text-white'
                        : 'border-slate-200 text-slate-700 hover:border-slate-400'
                    "
                    @click="schalteHaltestelle(halt.id)"
                  >
                    {{ halt.name }}
                  </button>
                  <span v-if="haltAuswahl.length === 0" class="text-xs text-slate-500">
                    Such eine Haltestelle, um sie zuzuordnen. Keine zuzuordnen ist in Ordnung — dann wird der
                    Hof von Hand gewählt.
                  </span>
                </div>
              </div>

              <div class="mt-3">
                <span class="block text-xs font-medium uppercase tracking-wide text-slate-500">Verkehrsmittel</span>
                <div class="mt-1 flex flex-wrap items-center gap-2">
                  <button
                    v-for="mittel in (['tram', 'bus'] as const)"
                    :key="mittel"
                    type="button"
                    class="rounded-md border px-3 py-1 text-sm transition"
                    :class="
                      entwurf.modes?.includes(mittel)
                        ? 'border-slate-800 bg-slate-800 font-medium text-white'
                        : 'border-slate-200 text-slate-700 hover:border-slate-400'
                    "
                    @click="schalteMittel(mittel)"
                  >
                    {{ mittel === 'tram' ? 'Tram' : 'Bus' }}
                  </button>
                  <span class="text-xs text-slate-500">
                    Keines gewählt heißt: Der Hof nimmt <strong>alles</strong> auf.
                  </span>
                </div>
              </div>

              <label class="mt-3 block">
                <span class="block text-xs font-medium uppercase tracking-wide text-slate-500">Notiz</span>
                <input
                  v-model="entwurf.note"
                  type="text"
                  maxlength="255"
                  class="mt-1 w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm focus:border-slate-800 focus:outline-none"
                />
              </label>

              <div class="mt-3 flex gap-2">
                <button
                  type="button"
                  class="rounded-md bg-slate-800 px-3 py-1.5 text-sm font-medium text-white transition hover:bg-slate-700 disabled:opacity-40"
                  :disabled="busy"
                  @click="speichere"
                >
                  Speichern
                </button>
                <button
                  type="button"
                  class="rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-700 transition hover:bg-slate-50"
                  @click="brich"
                >
                  Abbrechen
                </button>
              </div>
            </template>

            <!-- Anzeige -->
            <template v-else>
              <div class="flex flex-wrap items-baseline justify-between gap-2">
                <div>
                  <h2 class="text-base font-medium text-slate-900">
                    {{ hof.name }}
                    <span v-if="hof.short_name" class="ml-1 text-sm font-normal text-slate-500">
                      kurz „{{ hof.short_name }}"
                    </span>
                    <span
                      v-if="!hof.active"
                      class="ml-2 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-900"
                    >
                      stillgelegt
                    </span>
                  </h2>
                  <p class="mt-0.5 text-sm text-slate-600">
                    {{ mittelText(hof) }}
                    <template v-if="hof.stop_groups.length">
                      · an {{ hof.stop_groups.map((g) => g.name).join(', ') }}
                    </template>
                    <template v-else> · keiner Haltestelle zugeordnet</template>
                    ·
                    <span :class="hof.usage_count > 0 ? 'text-slate-900' : 'text-slate-400'">
                      {{ hof.usage_count }} {{ hof.usage_count === 1 ? 'Fahrt' : 'Fahrten' }}
                    </span>
                  </p>
                  <p v-if="hof.note" class="mt-0.5 text-sm text-slate-500">{{ hof.note }}</p>
                </div>

                <div class="flex gap-2 text-sm">
                  <button
                    type="button"
                    class="text-slate-500 underline underline-offset-2 hover:text-slate-900 disabled:opacity-40"
                    :disabled="busy"
                    @click="bearbeite(hof)"
                  >
                    Bearbeiten
                  </button>
                  <button
                    type="button"
                    class="text-slate-500 underline underline-offset-2 hover:text-slate-900 disabled:opacity-40"
                    :disabled="busy"
                    :title="
                      hof.active
                        ? 'Verschwindet aus der Auswahl, bleibt an bestehenden Fahrten lesbar.'
                        : 'Nimmt wieder neue Fahrten auf.'
                    "
                    @click="schalteStilllegung(hof)"
                  >
                    {{ hof.active ? 'Stilllegen' : 'Wieder aufnehmen' }}
                  </button>
                  <!-- Löschen nur, solange nichts daranhängt. Sonst ginge die Angabe
                       „ausgerückt aus Nord" still verloren — dafür gibt es das Stilllegen. -->
                  <button
                    v-if="hof.usage_count === 0"
                    type="button"
                    class="text-rose-600 underline underline-offset-2 hover:text-rose-800 disabled:opacity-40"
                    :disabled="busy"
                    @click="loesche(hof)"
                  >
                    Löschen
                  </button>
                </div>
              </div>
            </template>
          </div>
        </div>

        <!-- Neuer Hof -->
        <div v-if="bearbeitet === 0" class="mt-4 rounded-lg border border-slate-300 bg-white p-4 shadow-sm">
          <h2 class="text-base font-medium text-slate-900">Neuer Betriebshof</h2>
          <div class="mt-3 grid gap-3 sm:grid-cols-2">
            <label class="block">
              <span class="block text-xs font-medium uppercase tracking-wide text-slate-500">Name</span>
              <input
                v-model="entwurf.name"
                type="text"
                maxlength="120"
                class="mt-1 w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm focus:border-slate-800 focus:outline-none"
              />
            </label>
            <label class="block">
              <span class="block text-xs font-medium uppercase tracking-wide text-slate-500">Kurzform</span>
              <input
                v-model="entwurf.short_name"
                type="text"
                maxlength="16"
                class="mt-1 w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm focus:border-slate-800 focus:outline-none"
              />
            </label>
          </div>

          <div class="mt-3">
            <span class="block text-xs font-medium uppercase tracking-wide text-slate-500">
              Haltestellen
              <span class="font-normal normal-case text-slate-400">
                — hier wird der Hof beim Markieren von selbst gesetzt
              </span>
            </span>
            <input
              v-model="haltSuche"
              type="search"
              placeholder="Haltestelle suchen …"
              class="mt-1 w-full max-w-sm rounded-md border border-slate-300 px-3 py-1.5 text-sm focus:border-slate-800 focus:outline-none"
            />
            <div class="mt-2 flex flex-wrap gap-1.5">
              <button
                v-for="halt in haltAuswahl"
                :key="halt.id"
                type="button"
                class="rounded-md border px-2.5 py-1 text-sm transition"
                :class="
                  entwurf.stop_group_ids?.includes(halt.id)
                    ? 'border-slate-800 bg-slate-800 font-medium text-white'
                    : 'border-slate-200 text-slate-700 hover:border-slate-400'
                "
                @click="schalteHaltestelle(halt.id)"
              >
                {{ halt.name }}
              </button>
              <span v-if="haltAuswahl.length === 0" class="text-xs text-slate-500">
                Such eine Haltestelle, um sie zuzuordnen. Keine zuzuordnen ist in Ordnung — dann wird der Hof
                von Hand gewählt.
              </span>
            </div>
          </div>

          <div class="mt-3">
            <span class="block text-xs font-medium uppercase tracking-wide text-slate-500">Verkehrsmittel</span>
            <div class="mt-1 flex flex-wrap items-center gap-2">
              <button
                v-for="mittel in (['tram', 'bus'] as const)"
                :key="mittel"
                type="button"
                class="rounded-md border px-3 py-1 text-sm transition"
                :class="
                  entwurf.modes?.includes(mittel)
                    ? 'border-slate-800 bg-slate-800 font-medium text-white'
                    : 'border-slate-200 text-slate-700 hover:border-slate-400'
                "
                @click="schalteMittel(mittel)"
              >
                {{ mittel === 'tram' ? 'Tram' : 'Bus' }}
              </button>
              <span class="text-xs text-slate-500">Keines gewählt heißt: Der Hof nimmt alles auf.</span>
            </div>
          </div>

          <div class="mt-3 flex gap-2">
            <button
              type="button"
              class="rounded-md bg-slate-800 px-3 py-1.5 text-sm font-medium text-white transition hover:bg-slate-700 disabled:opacity-40"
              :disabled="busy"
              @click="speichere"
            >
              Anlegen
            </button>
            <button
              type="button"
              class="rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-700 transition hover:bg-slate-50"
              @click="brich"
            >
              Abbrechen
            </button>
          </div>
        </div>

        <button
          v-else
          type="button"
          class="mt-4 rounded-md border border-slate-800 px-3 py-1.5 text-sm font-medium text-slate-800 transition hover:bg-slate-800 hover:text-white"
          @click="beginneNeu"
        >
          Betriebshof hinzufügen
        </button>
      </template>
    </main>
  </div>
</template>
