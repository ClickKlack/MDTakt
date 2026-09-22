<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import AppHeader from '../components/AppHeader.vue'
import AutoLinkPanel from '../components/AutoLinkPanel.vue'
import StopLinkBoard from '../components/StopLinkBoard.vue'
import { FAHRPLAN_TYPEN, fetchLines, type FahrplanTyp, type Line } from '../services/lines'
import { fetchSchedulePeriods, periodOptionLabel, type SchedulePeriod } from '../services/schedulePeriods'
import { assignCourse, detachCourse } from '../services/courses'
import { fetchDepots, setTripLinkDepot, type Depot } from '../services/depots'
import { fetchStopGroups, type StopGroup } from '../services/stopGroups'
import {
  createTripLink,
  deleteTripLink,
  fetchStopLinkBoard,
  type AutoLinkParams,
  type AutoLinkResult,
  type StopLinkBoard as Board,
  type TripLinkWarning,
} from '../services/stopLinks'
import { formatDate } from '../utils/timezone'

const route = useRoute()
const router = useRouter()

const haltestellen = ref<StopGroup[]>([])
const perioden = ref<SchedulePeriod[]>([])
const linienVerzeichnis = ref<Record<string, Line>>({})

const suche = ref('')
const gewaehlteHaltestelle = ref<number | null>(null)
const gewaehltePeriode = ref<number | null>(null)
const dayType = ref<FahrplanTyp>('mo_fr')
const gewaehlterStand = ref<number | null>(null)
const modeFilter = ref<'tram' | 'bus' | null>(null)
/**
 * Leer = alle Linien. Bewusst eine Mehrfachauswahl: Ein Linienwechsel 1 → 13 ist manchmal
 * Absicht und manchmal nicht, und nur wer pflegt, weiss welcher Fall vorliegt. Mit genau einer
 * waehlbaren Linie liesse sich der gewollte Wechsel nur ueber „Alle" automatisieren — und damit
 * liefen alle uebrigen Linien der Haltestelle mit.
 */
const lineFilter = ref<string[]>([])

const board = ref<Board | null>(null)
const auswahl = ref<number | null>(null)
/**
 * Nur die aktiven Hoefe: Ein stillgelegter bleibt an bestehenden Entscheidungen lesbar, nimmt
 * aber nichts Neues mehr auf — er gehoert deshalb nicht in die Auswahl.
 */
const betriebshoefe = ref<Depot[]>([])

const loading = ref(true)
const loadingBoard = ref(false)
const busy = ref(false)
const error = ref<string | null>(null)
const hinweise = ref<TripLinkWarning[]>([])
/** Positive Rueckmeldung — getrennt von den Warnungen, damit beides sein Gewicht behaelt. */
const erfolg = ref<string | null>(null)

const gefiltert = computed(() => {
  const begriff = suche.value.trim().toLowerCase()
  const liste =
    begriff === '' ? haltestellen.value : haltestellen.value.filter((h) => h.name.toLowerCase().includes(begriff))
  return liste.slice(0, 40)
})

/** Haltestellen, an denen nur endet oder nur beginnt — dort fehlt meist ein Bahnsteig. */
const einseitige = computed(() => haltestellen.value.filter((h) => h.one_sided).length)

/** Die Linien, die an diesem Halt tatsächlich beginnen oder enden — nur die sind filterbar. */
const linienAmHalt = computed(() => {
  if (board.value === null) {
    return []
  }

  const alle = [...board.value.ending, ...board.value.starting]
    .filter((f) => modeFilter.value === null || f.mode === modeFilter.value)
    .map((f) => f.line)

  return [...new Set(alle)].sort((a, b) => a.localeCompare(b, 'de', { numeric: true }))
})

/** Verkehrsmittel, die hier überhaupt vorkommen — ein Filter auf Leeres wäre irreführend. */
const mittelAmHalt = computed(() => {
  if (board.value === null) {
    return []
  }
  const alle = [...board.value.ending, ...board.value.starting].map((f) => f.mode)
  return [...new Set(alle)].filter((m): m is 'tram' | 'bus' => m === 'tram' || m === 'bus')
})

onMounted(async () => {
  try {
    // Nur Endstellen: An einem reinen Durchfahrts-Halt kann die Umlauf-Pflege nichts tun,
    // und netzweit sind das rund 250 von 315 Haltestellen.
    const [h, p, l, b] = await Promise.all([
      fetchStopGroups(null, true),
      fetchSchedulePeriods(),
      fetchLines(),
      fetchDepots(true),
    ])
    haltestellen.value = h
    perioden.value = p
    linienVerzeichnis.value = Object.fromEntries(l.map((linie) => [linie.route_short_name, linie]))
    betriebshoefe.value = b

    // Auswahl aus der URL übernehmen, damit eine Haltestelle verlinkbar bleibt.
    gewaehlteHaltestelle.value = Number(route.query.stop_group) || null
    gewaehltePeriode.value =
      Number(route.query.period) || p.find((periode) => periode.status === 'current')?.id || p[0]?.id || null
    dayType.value = ((route.query.day_type as FahrplanTyp) ?? 'mo_fr') as FahrplanTyp
    gewaehlterStand.value = route.query.stand === undefined ? null : Number(route.query.stand)

    if (gewaehlteHaltestelle.value !== null) {
      await ladeBoard()
    }
  } catch {
    error.value = 'Haltestellen, Perioden oder Linien konnten nicht geladen werden.'
  } finally {
    loading.value = false
  }
})

async function ladeBoard(): Promise<void> {
  if (gewaehlteHaltestelle.value === null || gewaehltePeriode.value === null) {
    board.value = null
    return
  }

  loadingBoard.value = true
  error.value = null

  try {
    board.value = await fetchStopLinkBoard(
      gewaehlteHaltestelle.value,
      gewaehltePeriode.value,
      dayType.value,
      gewaehlterStand.value,
    )
    gewaehlterStand.value = board.value.stand?.index ?? null
    auswahl.value = null
    spiegelUrl()
  } catch (e: unknown) {
    board.value = null
    error.value = meldung(e, 'Der Haltestellen-Editor konnte nicht geladen werden.')
  } finally {
    loadingBoard.value = false
  }
}

function spiegelUrl(): void {
  void router.replace({
    name: 'stop-links',
    query: {
      stop_group: gewaehlteHaltestelle.value ?? undefined,
      period: gewaehltePeriode.value ?? undefined,
      day_type: dayType.value,
      stand: gewaehlterStand.value ?? undefined,
    },
  })
}

function meldung(e: unknown, fallback: string): string {
  const antwort = (e as { response?: { data?: { error?: { message?: string } } } }).response
  return antwort?.data?.error?.message ?? fallback
}

async function waehleHaltestelle(id: number): Promise<void> {
  gewaehlteHaltestelle.value = id
  // Ein anderer Halt hat andere Linien — ein mitgeschleppter Linienfilter zeigte dort nichts.
  lineFilter.value = []
  leereBereich()
  // Eine andere Haltestelle hat eigene Versionsstände — der alte Index sagt dort nichts.
  gewaehlterStand.value = null
  await ladeBoard()
}

async function verknuepfe(fromTripId: number, toTripId: number): Promise<void> {
  busy.value = true
  error.value = null
  hinweise.value = []
  erfolg.value = null

  try {
    const ergebnis = await createTripLink({ kind: 'link', from_trip_id: fromTripId, to_trip_id: toTripId })
    hinweise.value = ergebnis.warnings

    // Zwei verknuepfte Fahrten sind dasselbe Fahrzeug, also derselbe Kurs. Wurde er dabei
    // uebertragen, sagen wir das — sonst wirkt es, als haette die App etwas eigenmaechtig getan.
    if (ergebnis.course !== null && ergebnis.course_trips_assigned > 0) {
      erfolg.value = `Kurs ${ergebnis.course.number} auf ${ergebnis.course_trips_assigned} weitere Fahrten des Umlaufs übertragen.`
    }

    await ladeBoard()
  } catch (e: unknown) {
    error.value = meldung(e, 'Der Anschluss konnte nicht angelegt werden.')
  } finally {
    busy.value = false
  }
}

async function markiere(tripId: number, kind: 'start' | 'end'): Promise<void> {
  busy.value = true
  error.value = null
  hinweise.value = []
  erfolg.value = null

  try {
    // Bewusst **ohne** `depot_id`: Fehlt das Feld, schlaegt die Engine den Hof aus der
    // Haltestelle vor. Ein mitgeschicktes `null` hiesse dagegen „bewusst offen" und
    // unterdrueckte die Automatik.
    const ergebnis = await createTripLink(
      kind === 'start' ? { kind, to_trip_id: tripId } : { kind, from_trip_id: tripId },
    )

    // Wurde der Hof dabei von selbst gesetzt, sagen wir das — sonst wirkt es, als haette die
    // App etwas eigenmaechtig getan.
    if (ergebnis.depot !== null) {
      erfolg.value = `Betriebshof ${ergebnis.depot.name} aus der Haltestelle übernommen.`
    }

    await ladeBoard()
  } catch (e: unknown) {
    error.value = meldung(e, 'Die Entscheidung konnte nicht gespeichert werden.')
  } finally {
    busy.value = false
  }
}

async function setzeKurs(tripId: number, nummer: string): Promise<void> {
  busy.value = true
  error.value = null
  hinweise.value = []
  erfolg.value = null

  try {
    const ergebnis = await assignCourse(tripId, nummer)

    erfolg.value =
      ergebnis.trips_assigned > 1
        ? `Kurs ${ergebnis.course?.number} gilt jetzt für ${ergebnis.trips_assigned} Fahrten dieses Umlaufs.`
        : `Kurs ${ergebnis.course?.number} gesetzt.`

    if (ergebnis.course?.duplicate) {
      hinweise.value = [
        {
          code: 'course_conflict',
          message: `Die Nummer ${ergebnis.course.number} trägt bereits ein anderer Umlauf.`,
        },
      ]
    }

    await ladeBoard()
  } catch (e: unknown) {
    error.value = meldung(e, 'Der Kurs konnte nicht gesetzt werden.')
  } finally {
    busy.value = false
  }
}

async function loeseKurs(tripId: number): Promise<void> {
  busy.value = true
  error.value = null
  hinweise.value = []
  erfolg.value = null

  try {
    await detachCourse(tripId)
    await ladeBoard()
  } catch (e: unknown) {
    error.value = meldung(e, 'Der Kurs konnte nicht gelöst werden.')
  } finally {
    busy.value = false
  }
}

async function loese(linkId: number): Promise<void> {
  busy.value = true
  error.value = null
  hinweise.value = []
  erfolg.value = null

  try {
    await deleteTripLink(linkId)
    await ladeBoard()
  } catch (e: unknown) {
    error.value = meldung(e, 'Die Entscheidung konnte nicht gelöst werden.')
  } finally {
    busy.value = false
  }
}

/**
 * Den Betriebshof einer Betriebsfahrt setzen — `null` laesst ihn wieder offen.
 *
 * Getrennt vom Markieren, weil es die uebliche Reihenfolge ist: Erst wird markiert — das ist
 * die Aussage, die zaehlt —, der Hof kommt dazu, sobald er feststeht.
 */
async function setzeBetriebshof(linkId: number, depotId: number | null): Promise<void> {
  busy.value = true
  error.value = null
  hinweise.value = []
  erfolg.value = null

  try {
    await setTripLinkDepot(linkId, depotId)
    await ladeBoard()
  } catch (e: unknown) {
    error.value = meldung(e, 'Der Betriebshof konnte nicht gesetzt werden.')
  } finally {
    busy.value = false
  }
}

/** Eine Linie zu- oder abwaehlen. Keine mehr gewaehlt heisst wieder „Alle". */
function schalteLinie(linie: string): void {
  lineFilter.value = lineFilter.value.includes(linie)
    ? lineFilter.value.filter((l) => l !== linie)
    : [...lineFilter.value, linie]

  // Ein anderer Filter zeigt einen anderen Ausschnitt — eine Markierung darin waere Zufall.
  leereBereich()
}

// ---------------------------------------------------------------- Bereichsmodus

const autoModus = ref(false)
const bereichVon = ref<number | null>(null)
const bereichBis = ref<number | null>(null)

function leereBereich(): void {
  bereichVon.value = null
  bereichBis.value = null
}

function schalteAutoModus(): void {
  autoModus.value = !autoModus.value
  leereBereich()
  // Die beiden Bedienarten duerfen nie gleichzeitig scharf sein: Im Bereichsmodus waere eine
  // stehengebliebene Einzelauswahl ein Klick vom ungewollten Anschluss entfernt.
  auswahl.value = null
}

/**
 * Erster Klick setzt den Start, zweiter das Ende, dritter beginnt neu. Ein Klick auf den
 * gesetzten Start hebt ihn auf — so kommt man ohne Umweg aus einem Fehlgriff heraus.
 */
function waehleBereich(tripId: number): void {
  if (bereichVon.value === null) {
    bereichVon.value = tripId
    return
  }

  if (bereichBis.value === null) {
    if (bereichVon.value === tripId) {
      bereichVon.value = null
      return
    }
    bereichBis.value = tripId
    return
  }

  bereichVon.value = tripId
  bereichBis.value = null
}

/** Der Ausschnitt fuer den Mengen-Lauf; `null`, solange der Bereich unvollstaendig ist. */
const autoParams = computed<AutoLinkParams | null>(() => {
  if (
    gewaehlteHaltestelle.value === null ||
    gewaehltePeriode.value === null ||
    bereichVon.value === null ||
    bereichBis.value === null
  ) {
    return null
  }

  return {
    stop_group: gewaehlteHaltestelle.value,
    period: gewaehltePeriode.value,
    day_type: dayType.value,
    stand: gewaehlterStand.value,
    from_trip_id: bereichVon.value,
    to_trip_id: bereichBis.value,
    lines: lineFilter.value,
    mode: modeFilter.value,
  }
})

async function autoFertig(ergebnis: AutoLinkResult): Promise<void> {
  hinweise.value = []

  erfolg.value =
    ergebnis.action === 'link'
      ? `${ergebnis.summary.created} Anschlüsse angelegt, ${ergebnis.summary.skipped} übersprungen.` +
        (ergebnis.summary.courses_unified > 0
          ? ` Dabei wurde die Kursnummer auf ${ergebnis.summary.courses_unified} weitere Fahrten übertragen.`
          : '')
      : `${ergebnis.summary.removed} Anschlüsse aufgelöst. Die Kursnummern bleiben an beiden Kettenhälften stehen.`

  if (ergebnis.summary.course_conflicts > 0) {
    hinweise.value = [
      {
        code: 'course_conflict',
        message:
          `Bei ${ergebnis.summary.course_conflicts} Übergängen trugen beide Ketten bereits verschiedene ` +
          'Kursnummern. Es wurde nichts überschrieben — die richtige ist von Hand einzutragen.',
      },
    ]
  }

  leereBereich()
  await ladeBoard()
}

watch([gewaehltePeriode, dayType], () => {
  if (loading.value) {
    return
  }
  // Perioden und Fahrplantypen haben je eigene Versionsstände und je eigene Linien.
  gewaehlterStand.value = null
  lineFilter.value = []
  leereBereich()
  void ladeBoard()
})

watch(gewaehlterStand, (neu, alt) => {
  if (loading.value || loadingBoard.value || neu === alt) {
    return
  }
  void ladeBoard()
})
</script>

<template>
  <div class="min-h-full bg-slate-100">
    <AppHeader />

    <main class="mx-auto max-w-6xl px-6 py-8">
      <h1 class="text-2xl font-semibold text-slate-900">Anschlüsse</h1>
      <p class="mt-1 max-w-3xl text-sm text-slate-600">
        Hier entstehen die Umläufe: Fahrten, die an einer Haltestelle enden, werden mit Fahrten verknüpft, die dort
        beginnen — auch über Linien hinweg, denn eine 1 wird in Sudenburg durchaus zur 13. Beginnt oder endet eine
        Kette bewusst ohne Anschluss, ist das eine Betriebsfahrt und wird als solche festgehalten. Das ist etwas
        anderes als „noch nicht gepflegt“. An einer solchen Marke steht der
        <RouterLink to="/betriebshoefe" class="underline underline-offset-2">Betriebshof</RouterLink> — von selbst,
        wenn die Haltestelle einem zugeordnet ist, sonst von Hand und freiwillig: An einer Endstelle ohne
        zugeordneten Hof steht er oft nicht fest.
      </p>

      <p v-if="loading" class="mt-4 text-sm text-slate-500">Wird geladen …</p>

      <template v-else>
        <p v-if="error" class="mt-4 rounded-md bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ error }}</p>

        <p v-if="erfolg" class="mt-4 rounded-md bg-emerald-50 px-4 py-2 text-sm text-emerald-900">
          {{ erfolg }}
        </p>

        <ul v-if="hinweise.length" class="mt-4 space-y-1">
          <li
            v-for="hinweis in hinweise"
            :key="hinweis.code"
            class="rounded-md bg-amber-50 px-4 py-2 text-sm text-amber-900"
          >
            {{ hinweis.message }}
          </li>
        </ul>

        <!-- Auswahl: Haltestelle, Periode, Fahrplantyp, Versionsstand -->
        <div class="mt-6 rounded-lg bg-white p-4 shadow-sm">
          <label class="block text-xs font-medium uppercase tracking-wide text-slate-500">Haltestelle</label>
          <input
            v-model="suche"
            type="search"
            placeholder="Haltestelle suchen …"
            class="mt-1 w-full max-w-sm rounded-md border border-slate-300 px-3 py-1.5 text-sm focus:border-slate-800 focus:outline-none"
          />

          <div class="mt-2 flex flex-wrap gap-1.5">
            <button
              v-for="halt in gefiltert"
              :key="halt.id"
              type="button"
              class="rounded-md border px-2.5 py-1 text-sm transition"
              :class="
                gewaehlteHaltestelle === halt.id
                  ? 'border-slate-800 bg-slate-800 font-medium text-white'
                  : 'border-slate-200 text-slate-700 hover:border-slate-400'
              "
              @click="waehleHaltestelle(halt.id)"
            >
              {{ halt.name }}
              <span v-if="halt.stop_count > 1" class="ml-1 text-xs opacity-60">{{ halt.stop_count }} Bahnsteige</span>
              <span v-if="halt.one_sided" class="ml-1 text-xs text-amber-600" title="Hier endet nur oder beginnt nur etwas">!</span>
            </button>
            <span v-if="gefiltert.length === 0" class="text-sm text-slate-500">
              Keine Endstelle gefunden. Gezeigt werden nur Haltestellen, an denen Fahrten beginnen oder enden — an
              reinen Durchfahrts-Halten gibt es keine Umläufe zu pflegen.
            </span>
          </div>

          <div class="mt-4 flex flex-wrap items-end gap-6">
            <div>
              <label class="block text-xs font-medium uppercase tracking-wide text-slate-500">Periode</label>
              <select
                v-model.number="gewaehltePeriode"
                class="mt-1 rounded-md border border-slate-300 px-3 py-1.5 text-sm focus:border-slate-800 focus:outline-none"
              >
                <option v-for="periode in perioden" :key="periode.id" :value="periode.id">
                  {{ periodOptionLabel(periode) }}
                </option>
              </select>
            </div>

            <div>
              <label class="block text-xs font-medium uppercase tracking-wide text-slate-500">Fahrplantyp</label>
              <div class="mt-1 flex flex-wrap gap-1 rounded-lg bg-slate-200/60 p-1">
                <button
                  v-for="typ in FAHRPLAN_TYPEN"
                  :key="typ.value"
                  type="button"
                  class="rounded-md px-3 py-1 text-sm transition"
                  :class="
                    dayType === typ.value
                      ? 'bg-white font-medium text-slate-900 shadow-sm'
                      : 'text-slate-600 hover:bg-white/60'
                  "
                  @click="dayType = typ.value"
                >
                  {{ typ.label }}
                </button>
              </div>
            </div>
          </div>

          <!-- Versionsstände: nur nötig, wenn die beteiligten Linien in dieser Periode
               überhaupt mehr als einen Fahrplanstand hatten. -->
          <div v-if="board && board.stands.length > 1" class="mt-4">
            <label class="block text-xs font-medium uppercase tracking-wide text-slate-500">Versionsstand</label>
            <p class="mt-1 max-w-2xl text-xs text-slate-500">
              An dieser Haltestelle hat sich der Fahrplan innerhalb der Periode geändert. Anschlüsse gelten je Stand —
              ein Stand ist der Zeitraum, in dem alle beteiligten Linien denselben Fahrplan fahren. Ein Stand kann
              mehrfach gelten: Nachtlinien fahren montags anders als Di–Fr, weil die Nacht von Sonntag auf Montag eine
              Sonntagnacht ist.
            </p>
            <div class="mt-1.5 flex flex-wrap gap-2">
              <button
                v-for="stand in board.stands"
                :key="stand.index"
                type="button"
                class="rounded-md border px-3 py-1.5 text-sm transition"
                :class="
                  gewaehlterStand === stand.index
                    ? 'border-slate-800 bg-white font-medium text-slate-900'
                    : 'border-slate-200 text-slate-600 hover:border-slate-400'
                "
                @click="gewaehlterStand = stand.index"
              >
                {{ formatDate(stand.valid_from) }} – {{ formatDate(stand.valid_to) }}
                <span class="ml-1 text-xs text-slate-400">
                {{ stand.line_count }} {{ stand.line_count === 1 ? 'Linie' : 'Linien' }}<template
                  v-if="stand.ranges.length > 1"
                  >, {{ stand.ranges.length }} Zeiträume</template
                >
              </span>
              </button>
            </div>
          </div>
        </div>

        <p v-if="gewaehlteHaltestelle === null" class="mt-6 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900">
          Wähle eine Haltestelle, um die dort endenden und beginnenden Fahrten zu sehen. Gezeigt werden nur
          Endstellen — an reinen Durchfahrts-Halten gibt es keine Umläufe zu pflegen.
          <template v-if="einseitige > 0">
            <br />
            An {{ einseitige }} Haltestellen endet nur oder beginnt nur etwas (mit <strong>!</strong> markiert). Das
            heißt meist, dass der Gegen-Bahnsteig noch als eigene Haltestelle geführt wird — das lässt sich unter
            <RouterLink to="/haltestellen" class="underline underline-offset-2">Haltestellen</RouterLink> zuordnen.
            Bleibt es dabei, ist es eine Betriebsfahrt.
          </template>
        </p>

        <!-- Beim Nachladen bleibt das Board stehen und wird nur gedimmt. Wuerde es durch
             einen Ladehinweis ersetzt, faellt die Seitenhoehe zusammen und der Browser
             verliert die Scrollposition — genau dort, wo man gerade verknuepft hat. -->
        <p v-else-if="board === null && loadingBoard" class="mt-6 text-sm text-slate-500">
          Fahrten werden geladen …
        </p>

        <template v-else-if="board">
          <div class="mt-6 flex flex-wrap items-baseline justify-between gap-2">
            <div>
              <h2 class="text-lg font-medium text-slate-900">{{ board.stop_group.name }}</h2>
              <!-- Die Bahnsteige bleiben sichtbar: An einer Endstelle liegen Ankunft und
                   Abfahrt oft auf verschiedenen Punkten. -->
              <p v-if="board.stop_group.stops.length > 1" class="text-xs text-slate-500">
                {{ board.stop_group.stops.map((h) => h.name).join(' · ') }}
              </p>
            </div>
            <!-- Bewusst die Zahl der **ganzen** Haltestelle, auch bei aktivem Filter: Der
                 Pflegestand soll sich nicht schoenrechnen lassen, indem man etwas ausblendet. -->
            <p class="text-sm" :class="board.open_count > 0 ? 'text-amber-800' : 'text-emerald-700'">
              {{
                board.open_count > 0
                  ? `Noch offen: ${board.open_count} Fahrten`
                  : 'Alle Fahrten an dieser Haltestelle sind entschieden.'
              }}
              <span v-if="modeFilter !== null || lineFilter.length > 0" class="text-slate-500">
                (ganze Haltestelle)
              </span>
            </p>
          </div>

          <p
            v-if="board.ending.length === 0 && board.starting.length === 0"
            class="mt-4 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900"
          >
            An dieser Haltestelle beginnt und endet keine Fahrt — sie wird nur durchfahren. Umläufe entstehen an
            Endstellen; dort sind die Übergänge zu pflegen.
          </p>

          <p v-else-if="auswahl !== null" class="mt-4 rounded-md bg-emerald-50 px-4 py-2 text-sm text-emerald-900">
            Fahrt ausgewählt. Wähle rechts die Fahrt, mit der das Fahrzeug weiterfährt — oder klicke links erneut, um
            die Auswahl aufzuheben.
          </p>

          <div :class="loadingBoard ? 'pointer-events-none opacity-60 transition-opacity' : ''">
          <!-- Filter: An einem Umsteigepunkt liegen schnell 60 Fahrten nebeneinander. Wer
               eine Linie pflegt, will nur die sehen. -->
          <div v-if="board.ending.length || board.starting.length" class="mt-4 flex flex-wrap items-center gap-4">
            <div v-if="mittelAmHalt.length > 1" class="flex flex-wrap gap-1 rounded-lg bg-slate-200/60 p-1">
              <button
                type="button"
                class="rounded-md px-3 py-1 text-sm transition"
                :class="modeFilter === null ? 'bg-white font-medium text-slate-900 shadow-sm' : 'text-slate-600 hover:bg-white/60'"
                @click="modeFilter = null; lineFilter = []; leereBereich()"
              >
                Alle
              </button>
              <button
                v-for="mittel in mittelAmHalt"
                :key="mittel"
                type="button"
                class="rounded-md px-3 py-1 text-sm transition"
                :class="modeFilter === mittel ? 'bg-white font-medium text-slate-900 shadow-sm' : 'text-slate-600 hover:bg-white/60'"
                @click="modeFilter = mittel; lineFilter = []; leereBereich()"
              >
                {{ mittel === 'tram' ? 'Tram' : 'Bus' }}
              </button>
            </div>

            <div v-if="linienAmHalt.length > 1" class="flex flex-wrap items-center gap-1.5">
              <span class="text-xs uppercase tracking-wide text-slate-500">Linie</span>
              <button
                type="button"
                class="rounded-md border px-2.5 py-1 text-sm transition"
                :class="lineFilter.length === 0 ? 'border-slate-800 bg-slate-800 font-medium text-white' : 'border-slate-200 text-slate-700 hover:border-slate-400'"
                @click="lineFilter = []"
              >
                Alle
              </button>
              <button
                v-for="linie in linienAmHalt"
                :key="linie"
                type="button"
                class="rounded-md border px-2.5 py-1 text-sm tabular-nums transition"
                :class="lineFilter.includes(linie) ? 'border-slate-800 bg-slate-800 font-medium text-white' : 'border-slate-200 text-slate-700 hover:border-slate-400'"
                @click="schalteLinie(linie)"
              >
                {{ linie }}
              </button>
            </div>
          </div>

          <p v-if="linienAmHalt.length > 1" class="mt-1.5 max-w-3xl text-xs text-slate-500">
            Mehrere Linien lassen sich gleichzeitig wählen — so bleibt der gewollte Linienwechsel (1 → 13) im Blick,
            ein ungewollter aber außen vor.
          </p>

          <!-- Der Mengen-Lauf. Bewusst ein eigener Modus: Solange er aus ist, verhält sich der
               Editor wie bisher, und ein Klick links ist ein Klick links. -->
          <div class="mt-3 flex flex-wrap items-center gap-3">
            <button
              type="button"
              class="rounded-md border px-3 py-1.5 text-sm transition"
              :class="
                autoModus
                  ? 'border-emerald-600 bg-emerald-600 font-medium text-white'
                  : 'border-slate-300 text-slate-700 hover:border-slate-500'
              "
              @click="schalteAutoModus"
            >
              {{ autoModus ? 'Bereichsmodus beenden' : 'Mehrere auf einmal …' }}
            </button>
            <p v-if="!autoModus" class="max-w-2xl text-xs text-slate-500">
              Wenn an dieser Haltestelle immer dieselbe Bahn die nächste Abfahrt übernimmt, lässt sich das für einen
              Zeitraum in einem Zug setzen — statt jeden Übergang einzeln zu klicken.
            </p>
          </div>

          <AutoLinkPanel
            v-if="autoModus"
            :params="autoParams"
            :lines="linienVerzeichnis"
            :busy="busy"
            @done="autoFertig"
            @fehler="error = $event"
          />

          <StopLinkBoard
            :board="board"
            :lines="linienVerzeichnis"
            :selected="auswahl"
            :busy="busy"
            :mode-filter="modeFilter"
            :line-filter="lineFilter"
            :range-mode="autoModus"
            :range-from="bereichVon"
            :range-to="bereichBis"
            :depots="betriebshoefe"
            @range-pick="waehleBereich"
            @set-depot="setzeBetriebshof"
            @select="auswahl = $event"
            @link="verknuepfe"
            @mark="markiere"
            @unlink="loese"
            @assign-course="setzeKurs"
            @detach-course="loeseKurs"
          />
          </div>
        </template>
      </template>
    </main>
  </div>
</template>
