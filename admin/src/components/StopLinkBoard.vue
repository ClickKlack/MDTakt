<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, type VNode } from 'vue'
import type { Depot } from '../services/depots'
import type { Line } from '../services/lines'
import type { StopLinkBoard, StopLinkTrip } from '../services/stopLinks'
import { formatClock, formatDuration } from '../utils/timezone'
import LineBadge from './LineBadge.vue'

const props = defineProps<{
  board: StopLinkBoard
  /** Liniensignete brauchen Farbe und Verkehrsmittel — beides kommt aus dem Linienverzeichnis. */
  lines: Record<string, Line>
  /** Die links ausgewählte endende Fahrt, die auf einen Anschluss wartet. */
  selected: number | null
  busy: boolean
  /** `null` = alle Verkehrsmittel. */
  modeFilter: 'tram' | 'bus' | null
  /** Leer = alle Linien. Mehrfachauswahl, damit ein gewollter Linienwechsel sichtbar bleibt. */
  lineFilter: string[]
  /**
   * Bereichsmodus: Statt einzeln zu verknuepfen werden Start- und Endfahrt der **linken**
   * Spalte markiert. Die beiden Bedienarten sind nie gleichzeitig scharf — im Bereichsmodus ist
   * `selected` immer `null`, womit die rechte Spalte von selbst inert wird.
   */
  rangeMode: boolean
  rangeFrom: number | null
  rangeTo: number | null
  /**
   * Die waehlbaren Betriebshoefe. Stillgelegte sind hier **nicht** dabei: Sie bleiben an
   * bestehenden Entscheidungen lesbar, nehmen aber nichts Neues mehr auf.
   */
  depots: Depot[]
}>()

const emit = defineEmits<{
  select: [tripId: number | null]
  link: [fromTripId: number, toTripId: number]
  mark: [tripId: number, kind: 'start' | 'end']
  unlink: [linkId: number]
  assignCourse: [tripId: number, number: string]
  detachCourse: [tripId: number]
  rangePick: [tripId: number]
  /** `null` laesst den Hof wieder offen — das ist eine gueltige Angabe, kein Rueckschritt. */
  setDepot: [linkId: number, depotId: number | null]
}>()

/** Die Fahrt, deren Kursnummer gerade bearbeitet wird. */
const bearbeitet = ref<number | null>(null)
const eingabe = ref('')

function bearbeiteKurs(trip: StopLinkTrip): void {
  bearbeitet.value = trip.id
  eingabe.value = trip.course?.number ?? ''
}

function uebernimmKurs(trip: StopLinkTrip): void {
  const wert = eingabe.value.trim()
  bearbeitet.value = null

  if (wert === (trip.course?.number ?? '')) {
    return
  }

  if (wert === '') {
    if (trip.course) {
      emit('detachCourse', trip.id)
    }
    return
  }

  emit('assignCourse', trip.id, wert)
}

/**
 * Fokus setzen und eine vorhandene Nummer markieren — siehe TimetableGrid: Ein `ref` im
 * `v-for` waere ein Array, und der Mount-Hook feuert vor `v-model`, das `el.value` erst in
 * seinem eigenen `mounted` setzt. Deshalb `nextTick`.
 */
async function feldBereit(vnode: VNode): Promise<void> {
  await nextTick()

  const el = vnode.el as HTMLInputElement | null
  el?.focus()
  el?.select()
}

/**
 * Eine Zeile der Gegenüberstellung. Verknüpfte Fahrten stehen **nebeneinander**, auch wenn
 * dadurch auf der Gegenseite eine Lücke entsteht — nur so ist auf einen Blick zu sehen, was
 * woran hängt. Ungepaarte Fahrten belegen ihre Seite allein.
 */
interface Zeile {
  key: string
  ending: StopLinkTrip | null
  starting: StopLinkTrip | null
  /** Betriebstag-Sortierschlüssel aus der Engine — nicht die angezeigte Uhrzeit. */
  sort: number
  /**
   * Die Fahrt, die diese Zeile fuehrt. Stichentscheid bei zeitgleichen Fahrten — **numerisch**,
   * wie in der Engine: Ein String-Vergleich auf dem Schluessel sortierte `e10412` vor `e9835`,
   * und die Bereichsmarkierung faende dann eine andere Grenze als der Mengen-Lauf.
   */
  id: number
}

/** Fällt das Verzeichnis aus, trägt das Signet wenigstens die richtige Form. */
function signet(trip: StopLinkTrip): Line {
  return (
    props.lines[trip.line] ?? {
      route_short_name: trip.line,
      route_type: trip.mode === 'tram' ? 0 : 3,
      mode: trip.mode,
      modes: [trip.mode],
      route_ids: [],
      color: null,
    }
  )
}

function passt(trip: StopLinkTrip | null): boolean {
  if (trip === null) {
    return false
  }
  if (props.modeFilter !== null && trip.mode !== props.modeFilter) {
    return false
  }
  return props.lineFilter.length === 0 || props.lineFilter.includes(trip.line)
}

/**
 * Die Zeit, gegen die eine bestehende Zeile beim Einsortieren einer Abfahrt verglichen wird.
 *
 * Hat die Zeile eine Abfahrt, zaehlt diese — dann stehen beide in derselben Spalte. Hat sie
 * keine (eine Ankunft ohne Anschluss), zaehlt ihre Ankunft, damit die Abfahrt im zeitlichen
 * Fluss landet. Ohne den zweiten Fall rutschten an einer noch ungepflegten Haltestelle
 * **alle** Abfahrten ans Ende, weil dort keine einzige Zeile eine Abfahrt traegt.
 */
function vergleichszeit(zeile: Zeile): number {
  return zeile.starting?.departure_sort ?? zeile.ending?.arrival_sort ?? Number.MAX_SAFE_INTEGER
}

const zeilen = computed<Zeile[]>(() => {
  const mitAnkunft: Zeile[] = []
  const nurAbfahrt: Zeile[] = []
  const bereitsRechts = new Set<number>()

  for (const endet of props.board.ending) {
    const partnerId = endet.decision?.kind === 'link' ? (endet.decision.partner?.id ?? null) : null
    const partner = partnerId === null ? null : (props.board.starting.find((s) => s.id === partnerId) ?? null)

    if (partner) {
      bereitsRechts.add(partner.id)
    }

    mitAnkunft.push({
      key: `e${endet.id}`,
      ending: endet,
      starting: partner,
      sort: endet.arrival_sort,
      id: endet.id,
    })
  }

  for (const beginnt of props.board.starting) {
    if (bereitsRechts.has(beginnt.id)) {
      continue
    }
    nurAbfahrt.push({
      key: `s${beginnt.id}`,
      ending: null,
      starting: beginnt,
      sort: beginnt.departure_sort,
      id: beginnt.id,
    })
  }

  // Die Ankunft fuehrt: Zeilen mit linker Seite stehen nach ihr sortiert, damit die linke
  // Spalte lueckenlos aufsteigt. Kreuzen sich zwei Anschluesse, springt dafuer die rechte —
  // beides zugleich geht nicht.
  mitAnkunft.sort((a, b) => a.sort - b.sort || a.id - b.id)
  nurAbfahrt.sort((a, b) => a.sort - b.sort || a.id - b.id)

  // Eine Abfahrt **ohne** Ankunft kreuzt dagegen nichts: Sie hat keine linke Seite, also
  // verschiebt ihre Position dort nichts. Sie wird deshalb dort eingehaengt, wo sie
  // hingehoert — sonst stuende eine Ausrueck-Fahrt um 04:36 unter einem Anschluss, der erst
  // um 04:51 abfaehrt.
  const ergebnis = [...mitAnkunft]

  for (const zeile of nurAbfahrt) {
    const eigene = zeile.starting?.departure_sort ?? Number.MAX_SAFE_INTEGER
    const stelle = ergebnis.findIndex((r) => vergleichszeit(r) > eigene)

    if (stelle === -1) {
      ergebnis.push(zeile)
    } else {
      ergebnis.splice(stelle, 0, zeile)
    }
  }

  return ergebnis.filter((zeile) => passt(zeile.ending) || passt(zeile.starting))
})

/**
 * Fahrten, deren Anschluss auf eine Fahrt zeigt, die es in **diesem Versionsstand nicht gibt**.
 *
 * Der Fall entsteht beim Versionswechsel einer einzelnen Linie: Am City Carré wechselt die 13
 * mitten in der Periode auf eine neue Version, die 1, 2 und 5 nicht. Ein Anschluss 2 → 13, im
 * vorigen Stand gesetzt, zeigt danach auf eine 13er-Fahrt, die hier nicht mehr faehrt — waehrend
 * die Fahrt der neuen Version daneben unentschieden steht.
 *
 * Ohne diese Erkennung sah man davon nichts: Die Karte war gedaempft, weil eine Entscheidung
 * dranhaengt, zeigte aber weder Partner noch Wendezeit noch „Loesen" — sie war stumm. Genau der
 * gemeldete Effekt, dass eine Seite grau ist und die andere nicht.
 */
const partnerAusserhalb = computed<Set<number>>(() => {
  const endeIds = new Set(props.board.ending.map((f) => f.id))
  const startIds = new Set(props.board.starting.map((f) => f.id))
  const treffer = new Set<number>()

  for (const [liste, gegenueber] of [
    [props.board.ending, startIds],
    [props.board.starting, endeIds],
  ] as const) {
    for (const trip of liste) {
      if (trip.decision?.kind !== 'link') {
        continue
      }
      const partner = trip.decision.partner

      if (partner === null || !gegenueber.has(partner.id)) {
        treffer.add(trip.id)
      }
    }
  }

  return treffer
})

function fremderPartner(trip: StopLinkTrip | null): boolean {
  return trip !== null && partnerAusserhalb.value.has(trip.id)
}

/** Was an der Gegenfahrt steht — sie gehoert zu einer Version, die hier nicht gilt. */
function fremderPartnerTitel(trip: StopLinkTrip): string {
  const partner = trip.decision?.partner

  if (partner === undefined || partner === null) {
    return 'Die Gegenfahrt gehört zu einer Fahrplan-Version, die in diesem Versionsstand nicht gilt.'
  }

  return (
    `Verknüpft mit Linie ${partner.line} um ` +
    `${formatClock(partner.departure_time ?? partner.arrival_time)} (Version ${partner.version_no}). ` +
    'Diese Version gilt in diesem Versionsstand nicht — der Anschluss gehört auf die Fahrt der neuen Version ' +
    'übertragen (Versionen → Übernahme) oder hier zu lösen.'
  )
}

const offeneEnden = computed(
  () => props.board.ending.filter((f) => passt(f) && f.decision === null).length,
)
const offeneAnfaenge = computed(
  () => props.board.starting.filter((f) => passt(f) && f.decision === null).length,
)
const sichtbareEnden = computed(() => props.board.ending.filter(passt).length)
const sichtbareAnfaenge = computed(() => props.board.starting.filter(passt).length)

/** Nur eine offene beginnende Fahrt kann einen Anschluss aufnehmen. */
function istZiel(trip: StopLinkTrip | null): boolean {
  return trip !== null && props.selected !== null && trip.decision === null
}

function klickEndend(trip: StopLinkTrip): void {
  hofOffen.value = null

  // Im Bereichsmodus ist auch eine bereits entschiedene Fahrt als Grenze waehlbar — sie wird
  // im Lauf ohnehin uebersprungen, waere als Markierung aber unverzichtbar, wenn der Bereich
  // genau dort beginnen soll.
  if (props.rangeMode) {
    emit('rangePick', trip.id)
    return
  }

  if (trip.decision !== null) {
    return
  }
  emit('select', props.selected === trip.id ? null : trip.id)
}

/** Sortierschluessel einer Fahrt — dieselbe Regel wie in der Engine. */
function schluessel(trip: StopLinkTrip): number[] {
  return [trip.arrival_sort, trip.id]
}

function vergleiche(a: number[], b: number[]): number {
  return a[0] - b[0] || a[1] - b[1]
}

/**
 * Die markierten Grenzen in **wirksamer** Reihenfolge.
 *
 * Verkehrt herum markiert ist kein Fehler, sondern eine Handbewegung von unten nach oben — die
 * Engine tauscht ebenso. Die Beschriftung folgt deshalb der Wirkung, nicht der Klickfolge.
 */
const bereichGrenzen = computed<{ von: StopLinkTrip; bis: StopLinkTrip } | null>(() => {
  const a = props.board.ending.find((f) => f.id === props.rangeFrom) ?? null
  const b = props.board.ending.find((f) => f.id === props.rangeTo) ?? null

  if (a === null) {
    return null
  }

  // Erst eine Grenze gesetzt: Der Bereich ist diese eine Fahrt.
  if (b === null) {
    return { von: a, bis: a }
  }

  return vergleiche(schluessel(a), schluessel(b)) <= 0 ? { von: a, bis: b } : { von: b, bis: a }
})

/**
 * Liegt diese Fahrt im markierten Bereich? **Inklusive** beider Grenzen — wortgleich zur Engine.
 * Laufen die beiden auseinander, fasst der Lauf eine andere Menge an als die hier hervorgehobene.
 */
function imBereich(trip: StopLinkTrip | null): boolean {
  const grenzen = bereichGrenzen.value

  if (trip === null || grenzen === null) {
    return false
  }

  return (
    vergleiche(schluessel(grenzen.von), schluessel(trip)) <= 0 &&
    vergleiche(schluessel(trip), schluessel(grenzen.bis)) <= 0
  )
}

function istGrenze(trip: StopLinkTrip | null): 'von' | 'bis' | null {
  const grenzen = bereichGrenzen.value

  if (trip === null || grenzen === null) {
    return null
  }
  if (trip.id === grenzen.von.id) {
    return 'von'
  }
  return trip.id === grenzen.bis.id ? 'bis' : null
}

function klickBeginnend(trip: StopLinkTrip): void {
  hofOffen.value = null

  if (!istZiel(trip) || props.selected === null) {
    return
  }
  emit('link', props.selected, trip.id)
}

function kurz(wendezeit: number | null): boolean {
  return wendezeit !== null && wendezeit < 180
}

// ---------------------------------------------------------------- Betriebshof an der Marke

/** Die Entscheidung, deren Hof gerade gewaehlt wird. */
const hofOffen = ref<number | null>(null)

/**
 * Die Hoefe, die dieses Fahrzeug aufnehmen.
 *
 * Eine leere Modus-Liste heisst **alle** — das ist der Vorgabefall und nicht „keines". Ein
 * Tram-Hof taucht bei einem Bus gar nicht erst auf: Die Engine weist ihn ohnehin ab, und eine
 * Auswahl, die eine Fehlermeldung produziert, ist keine Auswahl.
 */
function hoefeFuer(trip: StopLinkTrip): Depot[] {
  return props.depots.filter((hof) => hof.modes.length === 0 || hof.modes.includes(trip.mode as 'tram' | 'bus'))
}

function waehleHof(linkId: number, depotId: number | null): void {
  hofOffen.value = null
  emit('setDepot', linkId, depotId)
}

/**
 * Traegt diese Karte gerade das offene Hof-Menue?
 *
 * Daran haengt mehr als eine Hervorhebung: Die Karte darf, solange das Menue offen ist, **kein**
 * `opacity` unter 1 tragen. Ein transparentes Element bildet einen eigenen Stacking-Context —
 * das Menue waere damit an die Karte gefesselt, die naechste Zeile legte sich darueber, und es
 * schiene obendrein zu 55 % durch. Genau die unschoenen Effekte. Deshalb faellt die Daempfung
 * weg und die Karte steigt mit `relative z-30` ueber ihre Nachbarn.
 */
function hofMenueOffen(trip: StopLinkTrip | null): boolean {
  const id = trip?.decision?.id

  return id !== undefined && hofOffen.value === id
}

/** Ein Klick irgendwo sonst schliesst das Menue — sonst bliebe es beim Weiterarbeiten stehen. */
function schliesseHofMenue(): void {
  hofOffen.value = null
}

onMounted(() => {
  document.addEventListener('click', schliesseHofMenue)
  document.addEventListener('keydown', aufEscape)
})

onBeforeUnmount(() => {
  document.removeEventListener('click', schliesseHofMenue)
  document.removeEventListener('keydown', aufEscape)
})

function aufEscape(e: KeyboardEvent): void {
  if (e.key === 'Escape') {
    hofOffen.value = null
  }
}
</script>

<template>
  <div class="mt-6">
    <div class="grid grid-cols-2 gap-4 border-b border-slate-200 pb-1">
      <h2 class="flex items-baseline justify-between text-sm font-medium text-slate-900">
        Endet hier
        <span class="text-xs font-normal text-slate-500">
          {{ sichtbareEnden }} Fahrten · {{ offeneEnden }} offen
        </span>
      </h2>
      <h2 class="flex items-baseline justify-between text-sm font-medium text-slate-900">
        Beginnt hier
        <span class="text-xs font-normal text-slate-500">
          {{ sichtbareAnfaenge }} Fahrten · {{ offeneAnfaenge }} offen
        </span>
      </h2>
    </div>

    <p
      v-if="zeilen.length === 0"
      class="mt-3 rounded-lg bg-slate-50 px-4 py-3 text-sm text-slate-600"
    >
      <template v-if="modeFilter !== null || lineFilter.length > 0">
        Keine Fahrt passt zu diesem Filter. Setz ihn zurück, um alles zu sehen.
      </template>
      <template v-else>
        An dieser Haltestelle beginnt und endet in diesem Versionsstand keine Fahrt.
      </template>
    </p>

    <ul v-else class="mt-1 divide-y divide-slate-100">
      <li v-for="zeile in zeilen" :key="zeile.key" class="grid grid-cols-2 items-stretch gap-4 py-1">
        <!-- Linke Seite: endende Fahrt -->
        <div
          v-if="zeile.ending"
          class="rounded-lg border px-3 py-2 hover:opacity-100"
          :class="[
            zeile.ending.decision === null
              ? 'cursor-pointer border-slate-200 bg-white hover:border-slate-400'
              : 'border-slate-100 bg-white',
            // Entschieden heisst erledigt: gedaempft, damit das Auge die offenen findet.
            // Beim Ueberfahren wieder voll lesbar — aendern muss man sie ja koennen. Nur
            // solange das Hof-Menue zu ist: siehe hofMenueOffen().
            // Im Bereichsmodus faellt sie ebenfalls weg: Dort ist jede Zeile anklickbar und
            // soll voll lesbar sein. Zwei widerspruechliche `opacity`-Klassen nebeneinander
            // entschiede nicht die Reihenfolge hier, sondern die im Stylesheet.
            zeile.ending.decision !== null && !rangeMode && !hofMenueOffen(zeile.ending)
              ? 'opacity-55'
              : '',
            // Ohne Uebergang, solange das Menue offen ist: Die Animation von 0,55 auf 1 liesse
            // die Karte fuer ihre Dauer transparent — und damit das Menue kurz hinter der
            // naechsten Zeile aufblitzen.
            hofMenueOffen(zeile.ending) ? 'relative z-30' : 'transition',
            selected === zeile.ending.id ? 'border-slate-800 ring-2 ring-slate-800' : '',
            // Im Bereichsmodus ist jede Zeile anklickbar, auch eine entschiedene: Sie wird im
            // Lauf uebersprungen, muss aber als Grenze waehlbar bleiben.
            rangeMode ? 'cursor-pointer' : '',
            rangeMode && imBereich(zeile.ending)
              ? 'border-l-4 border-l-emerald-500 bg-emerald-50/40'
              : '',
          ]"
          @click="klickEndend(zeile.ending)"
        >
          <div v-if="rangeMode && istGrenze(zeile.ending)" class="mb-1 text-[0.65rem] font-semibold uppercase tracking-wide text-emerald-700">
            {{ istGrenze(zeile.ending) === 'von' ? 'Start' : 'Ende' }}
          </div>
          <div class="flex items-center gap-2">
            <LineBadge :line="signet(zeile.ending)" size="sm" />
            <!-- Der Kurs ist die eigentliche Auskunft dieser Ansicht: Er sagt, zu welchem
                 Umlauf die Fahrt gehoert. Deshalb steht er direkt neben dem Signet. -->
            <input
              v-if="bearbeitet === zeile.ending.id"
              v-model="eingabe"
              type="text"
              maxlength="8"
              class="w-16 rounded border border-slate-800 px-1 py-0.5 text-xs tabular-nums focus:outline-none"
              @vue:mounted="feldBereit"
              @click.stop
              @blur="uebernimmKurs(zeile.ending)"
              @keydown.enter.prevent="uebernimmKurs(zeile.ending)"
              @keydown.esc.prevent="bearbeitet = null"
            />
            <button
              v-else
              type="button"
              class="rounded px-1.5 py-0.5 text-xs tabular-nums transition disabled:opacity-50"
              :class="
                zeile.ending.course
                  ? 'bg-slate-800 font-semibold text-white hover:bg-slate-700'
                  : 'border border-dashed border-slate-300 text-slate-400 hover:border-slate-500 hover:text-slate-700'
              "
              :disabled="busy"
              :title="zeile.ending.course ? 'Kurs ändern' : 'Kursnummer eintragen'"
              @click.stop="bearbeiteKurs(zeile.ending)"
            >
              {{ zeile.ending.course ? zeile.ending.course.display : 'ohne Kurs' }}
            </button>
            <span class="ml-auto shrink-0 text-sm tabular-nums font-medium text-slate-900">
              {{ formatClock(zeile.ending.arrival_time) }}
            </span>
          </div>
          <div class="mt-1 flex items-center gap-2 text-xs">
            <span class="min-w-0 flex-1 truncate text-slate-600">aus {{ zeile.ending.start_stop ?? '—' }}</span>
            <button
              v-if="zeile.ending.decision === null"
              type="button"
              class="shrink-0 text-slate-500 underline underline-offset-2 hover:text-slate-900 disabled:opacity-50"
              :disabled="busy"
              title="Einrücken: Das Fahrzeug fährt von hier in den Betriebshof — die Kette endet bewusst."
              @click.stop="emit('mark', zeile.ending.id, 'end')"
            >
              In den Betriebshof
            </button>
            <!-- Die Marke ist eine Entscheidung wie jede andere und muss deshalb auch wieder
                 zurückzunehmen sein — ohne „Lösen" bliebe ein Fehlgriff für immer stehen. -->
            <!-- Ein Anschluss, dessen Gegenfahrt in diesem Stand nicht faehrt. Er ist eine
                 echte Entscheidung, nur hier nicht darstellbar — also sagen wir es, statt die
                 Karte stumm zu daempfen. -->
            <template v-else-if="zeile.ending.decision.kind === 'link' && fremderPartner(zeile.ending)">
              <span
                class="shrink-0 rounded bg-violet-100 px-1.5 text-violet-900"
                :title="fremderPartnerTitel(zeile.ending)"
              >
                Anschluss auf {{ zeile.ending.decision.partner?.line ?? '?' }}
                {{ formatClock(zeile.ending.decision.partner?.departure_time ?? null) }} — andere Version
              </span>
              <button
                type="button"
                class="shrink-0 text-slate-500 underline underline-offset-2 hover:text-slate-900 disabled:opacity-50"
                :disabled="busy"
                title="Den Anschluss lösen — die Fahrt gilt dann wieder als nicht gepflegt."
                @click.stop="emit('unlink', zeile.ending.decision.id)"
              >
                Lösen
              </button>
            </template>
            <template v-else-if="zeile.ending.decision.kind === 'end'">
              <span class="shrink-0 rounded bg-sky-50 px-1.5 text-sky-900" title="Einrücken">
                In den Betriebshof
              </span>
              <!-- Welcher Hof — freiwillig. An einer Endstelle steht er oft nicht fest, und
                   „offen" ist dort die ehrlichere Angabe als ein geratener Hof. -->
              <div class="relative shrink-0">
                <button
                  type="button"
                  class="rounded px-1.5 text-xs transition disabled:opacity-50"
                  :class="
                    zeile.ending.decision.depot
                      ? 'bg-sky-100 font-medium text-sky-900 hover:bg-sky-200'
                      : 'border border-dashed border-slate-300 text-slate-400 hover:border-slate-500 hover:text-slate-700'
                  "
                  :disabled="busy"
                  :title="zeile.ending.decision.depot?.name ?? 'Betriebshof wählen — freiwillig'"
                  @click.stop="hofOffen = hofOffen === zeile.ending.decision.id ? null : zeile.ending.decision.id"
                >
                  {{ zeile.ending.decision.depot?.display ?? 'Hof offen' }}
                </button>
                <div
                  v-if="hofOffen === zeile.ending.decision.id"
                  class="absolute right-0 top-full z-40 mt-1 w-52 rounded-md border border-slate-300 bg-white py-1 text-left shadow-xl"
                  @click.stop
                >
                  <button
                    v-for="hof in hoefeFuer(zeile.ending)"
                    :key="hof.id"
                    type="button"
                    class="block w-full px-3 py-1 text-left text-xs hover:bg-slate-100"
                    :class="zeile.ending.decision.depot?.id === hof.id ? 'font-semibold text-slate-900' : 'text-slate-700'"
                    @click.stop="waehleHof(zeile.ending.decision.id, hof.id)"
                  >
                    {{ hof.name }}
                  </button>
                  <p v-if="hoefeFuer(zeile.ending).length === 0" class="px-3 py-1 text-xs text-slate-500">
                    Für dieses Verkehrsmittel ist kein Betriebshof gepflegt.
                  </p>
                  <button
                    v-if="zeile.ending.decision.depot"
                    type="button"
                    class="mt-1 block w-full border-t border-slate-100 px-3 pt-1.5 text-left text-xs text-slate-500 hover:bg-slate-100"
                    @click.stop="waehleHof(zeile.ending.decision.id, null)"
                  >
                    Wieder offen lassen
                  </button>
                </div>
              </div>
              <button
                type="button"
                class="shrink-0 text-slate-500 underline underline-offset-2 hover:text-slate-900 disabled:opacity-50"
                :disabled="busy"
                title="Die Marke wieder entfernen — die Fahrt gilt dann wieder als nicht gepflegt."
                @click.stop="emit('unlink', zeile.ending.decision.id)"
              >
                Lösen
              </button>
            </template>
          </div>
        </div>
        <div v-else />

        <!-- Rechte Seite: beginnende Fahrt -->
        <div
          v-if="zeile.starting"
          class="rounded-lg border px-3 py-2 hover:opacity-100"
          :class="[
            istZiel(zeile.starting)
              ? 'cursor-pointer border-emerald-400 bg-emerald-50/40 hover:border-emerald-600'
              : zeile.starting.decision === null
                ? 'border-slate-200 bg-white'
                : 'border-slate-100 bg-white',
            zeile.starting.decision !== null && !istZiel(zeile.starting) && !hofMenueOffen(zeile.starting)
              ? 'opacity-55'
              : '',
            hofMenueOffen(zeile.starting) ? 'relative z-30' : 'transition',
          ]"
          @click="klickBeginnend(zeile.starting)"
        >
          <div class="flex items-center gap-2">
            <span class="shrink-0 text-sm tabular-nums font-medium text-slate-900">
              {{ formatClock(zeile.starting.departure_time) }}
            </span>
            <LineBadge :line="signet(zeile.starting)" size="sm" />
            <input
              v-if="bearbeitet === zeile.starting.id"
              v-model="eingabe"
              type="text"
              maxlength="8"
              class="w-16 rounded border border-slate-800 px-1 py-0.5 text-xs tabular-nums focus:outline-none"
              @vue:mounted="feldBereit"
              @click.stop
              @blur="uebernimmKurs(zeile.starting)"
              @keydown.enter.prevent="uebernimmKurs(zeile.starting)"
              @keydown.esc.prevent="bearbeitet = null"
            />
            <button
              v-else
              type="button"
              class="rounded px-1.5 py-0.5 text-xs tabular-nums transition disabled:opacity-50"
              :class="
                zeile.starting.course
                  ? 'bg-slate-800 font-semibold text-white hover:bg-slate-700'
                  : 'border border-dashed border-slate-300 text-slate-400 hover:border-slate-500 hover:text-slate-700'
              "
              :disabled="busy"
              :title="zeile.starting.course ? 'Kurs ändern' : 'Kursnummer eintragen'"
              @click.stop="bearbeiteKurs(zeile.starting)"
            >
              {{ zeile.starting.course ? zeile.starting.course.display : 'ohne Kurs' }}
            </button>
          </div>
          <div class="mt-1 flex items-center gap-2 text-xs">
            <span class="min-w-0 flex-1 truncate text-slate-600">nach {{ zeile.starting.end_stop ?? '—' }}</span>
            <button
              v-if="zeile.starting.decision === null"
              type="button"
              class="shrink-0 text-slate-500 underline underline-offset-2 hover:text-slate-900 disabled:opacity-50"
              :disabled="busy"
              title="Ausrücken: Das Fahrzeug kommt aus dem Betriebshof — die Kette beginnt bewusst hier."
              @click.stop="emit('mark', zeile.starting.id, 'start')"
            >
              Aus dem Betriebshof
            </button>
            <template v-else-if="zeile.starting.decision.kind === 'link' && fremderPartner(zeile.starting)">
              <span
                class="shrink-0 rounded bg-violet-100 px-1.5 text-violet-900"
                :title="fremderPartnerTitel(zeile.starting)"
              >
                Anschluss von {{ zeile.starting.decision.partner?.line ?? '?' }}
                {{ formatClock(zeile.starting.decision.partner?.arrival_time ?? null) }} — andere Version
              </span>
              <button
                type="button"
                class="shrink-0 text-slate-500 underline underline-offset-2 hover:text-slate-900 disabled:opacity-50"
                :disabled="busy"
                title="Den Anschluss lösen — die Fahrt gilt dann wieder als nicht gepflegt."
                @click.stop="emit('unlink', zeile.starting.decision.id)"
              >
                Lösen
              </button>
            </template>
            <template v-else-if="zeile.starting.decision.kind === 'start'">
              <span class="shrink-0 rounded bg-sky-50 px-1.5 text-sky-900" title="Ausrücken">
                Aus dem Betriebshof
              </span>
              <div class="relative shrink-0">
                <button
                  type="button"
                  class="rounded px-1.5 text-xs transition disabled:opacity-50"
                  :class="
                    zeile.starting.decision.depot
                      ? 'bg-sky-100 font-medium text-sky-900 hover:bg-sky-200'
                      : 'border border-dashed border-slate-300 text-slate-400 hover:border-slate-500 hover:text-slate-700'
                  "
                  :disabled="busy"
                  :title="zeile.starting.decision.depot?.name ?? 'Betriebshof wählen — freiwillig'"
                  @click.stop="hofOffen = hofOffen === zeile.starting.decision.id ? null : zeile.starting.decision.id"
                >
                  {{ zeile.starting.decision.depot?.display ?? 'Hof offen' }}
                </button>
                <div
                  v-if="hofOffen === zeile.starting.decision.id"
                  class="absolute right-0 top-full z-40 mt-1 w-52 rounded-md border border-slate-300 bg-white py-1 text-left shadow-xl"
                  @click.stop
                >
                  <button
                    v-for="hof in hoefeFuer(zeile.starting)"
                    :key="hof.id"
                    type="button"
                    class="block w-full px-3 py-1 text-left text-xs hover:bg-slate-100"
                    :class="zeile.starting.decision.depot?.id === hof.id ? 'font-semibold text-slate-900' : 'text-slate-700'"
                    @click.stop="waehleHof(zeile.starting.decision.id, hof.id)"
                  >
                    {{ hof.name }}
                  </button>
                  <p v-if="hoefeFuer(zeile.starting).length === 0" class="px-3 py-1 text-xs text-slate-500">
                    Für dieses Verkehrsmittel ist kein Betriebshof gepflegt.
                  </p>
                  <button
                    v-if="zeile.starting.decision.depot"
                    type="button"
                    class="mt-1 block w-full border-t border-slate-100 px-3 pt-1.5 text-left text-xs text-slate-500 hover:bg-slate-100"
                    @click.stop="waehleHof(zeile.starting.decision.id, null)"
                  >
                    Wieder offen lassen
                  </button>
                </div>
              </div>
              <button
                type="button"
                class="shrink-0 text-slate-500 underline underline-offset-2 hover:text-slate-900 disabled:opacity-50"
                :disabled="busy"
                title="Die Marke wieder entfernen — die Fahrt gilt dann wieder als nicht gepflegt."
                @click.stop="emit('unlink', zeile.starting.decision.id)"
              >
                Lösen
              </button>
            </template>
          </div>
        </div>
        <div v-else />

        <!-- Die Verbindung selbst, ueber beide Spalten: Wendezeit und Loesen -->
        <div
          v-if="zeile.ending && zeile.starting && zeile.ending.decision?.kind === 'link'"
          class="col-span-2 -mt-0.5 flex items-center justify-center gap-2 text-xs opacity-55 transition hover:opacity-100"
        >
          <span class="h-px flex-1 bg-slate-200" />
          <span
            class="rounded-full px-2 py-0.5 tabular-nums"
            :class="
              kurz(zeile.ending.decision.turnaround_seconds)
                ? 'bg-amber-100 text-amber-900'
                : 'bg-slate-100 text-slate-600'
            "
          >
            {{ formatDuration(zeile.ending.decision.turnaround_seconds) }} Wende
          </span>
          <span v-if="zeile.ending.line !== zeile.starting.line" class="rounded-full bg-violet-100 px-2 py-0.5 text-violet-900">
            Linienwechsel
          </span>
          <button
            type="button"
            class="text-slate-500 underline underline-offset-2 hover:text-slate-900 disabled:opacity-50"
            :disabled="busy"
            @click="emit('unlink', zeile.ending.decision.id)"
          >
            Lösen
          </button>
          <span class="h-px flex-1 bg-slate-200" />
        </div>
      </li>
    </ul>
  </div>
</template>
