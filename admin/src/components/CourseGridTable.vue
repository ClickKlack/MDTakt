<script setup lang="ts">
import { computed } from 'vue'
import type { CourseGridCell, CourseGridColumn, CourseGridSection } from '../services/courses'
import type { Line } from '../services/lines'
import { formatClock } from '../utils/timezone'
import LineBadge from './LineBadge.vue'

const props = defineProps<{
  /** Eine Tabelle — die Umläufe eines Laufwegs. Eine Linie mit zwei Laufwegen zeigt zwei. */
  grid: CourseGridSection
  /** Liniensignete brauchen Farbe und Verkehrsmittel — beides kommt aus dem Linienverzeichnis. */
  lines: Record<string, Line>
  /** Überschrift über der Tabelle — nur gesetzt, wenn die Linie mehrere Laufwege hat. */
  title?: string
  /** Die Zeichen-Legende (│ und ·) — bei mehreren Tabellen nur unter der letzten. */
  legend?: boolean
}>()

/** Fällt das Verzeichnis aus, trägt das Signet wenigstens die richtige Form. */
function signet(linie: string): Line {
  return (
    props.lines[linie] ?? {
      route_short_name: linie,
      route_type: 0,
      mode: 'tram',
      modes: ['tram'],
      route_ids: [],
      color: null,
    }
  )
}

/**
 * Die Unterlegung je Linie.
 *
 * Ein Umlauf laeuft ueber Linien hinweg — eine 1 wird in Sudenburg zur 13 —, und in der Tabelle
 * soll genau dieser Wechsel ins Auge fallen. Jede Linie traegt deshalb ihren eigenen
 * Zellenhintergrund; wo er in einer Spalte umspringt, hat das Fahrzeug gewechselt.
 *
 * Bewusst eine feste, blasse Palette statt der Linienfarbe: Die echten Farben (1 und 13 sind
 * beide rot) unterschieden die Bloecke nicht, und hinter Zahlenkolonnen muss der Untergrund
 * zurueckhaltend bleiben.
 */
const TOENUNGEN = ['bg-violet-100', 'bg-sky-100', 'bg-amber-100', 'bg-emerald-100', 'bg-rose-100']

/**
 * Die Linie, auf der die meisten Fahrten der Tabelle laufen — sie bleibt ungetoent.
 *
 * Nicht die gewaehlte Linie der Ansicht: Ein Umlauf erscheint auch unter einer Linie, die er nur
 * streift, und dann waere fast alles getoent.
 */
const grundlinie = computed(() => {
  const zaehler = new Map<string, number>()

  for (const kurs of props.grid.courses) {
    for (const zelle of kurs.cells) {
      if (zelle !== null) {
        zaehler.set(zelle.line, (zaehler.get(zelle.line) ?? 0) + 1)
      }
    }
  }

  return [...zaehler.entries()].sort((a, b) => b[1] - a[1])[0]?.[0] ?? null
})

/** Die uebrigen Linien in der Reihenfolge ihres Auftretens — je eine Toenung. */
const nebenlinien = computed(() => {
  const gesehen: string[] = []

  for (const kurs of props.grid.courses) {
    for (const zelle of kurs.cells) {
      if (zelle !== null && zelle.line !== grundlinie.value && !gesehen.includes(zelle.line)) {
        gesehen.push(zelle.line)
      }
    }
  }

  return gesehen
})

function toenung(linie: string): string {
  const i = nebenlinien.value.indexOf(linie)

  return i === -1 ? '' : TOENUNGEN[i % TOENUNGEN.length]
}

/**
 * Ein Halt, der erneut beruehrt wird, ist die naechste Runde des Umlaufs — nicht derselbe
 * Eintrag zweimal. Ohne Kennzeichnung sahen die Zeilen nach einem Doppeleintrag aus.
 */
function zeilenTitel(repeatIndex: number): string | undefined {
  return repeatIndex > 0
    ? `${repeatIndex + 1}. Berührung dieser Haltestelle — die nächste Runde des Umlaufs.`
    : undefined
}

function spaltenTitel(kurs: CourseGridColumn): string {
  const von = formatClock(kurs.first_departure)
  const bis = formatClock(kurs.last_arrival)

  return `Kurs ${kurs.number} · ${kurs.trip_count} Fahrten · ${von} bis ${bis}`
}

/**
 * Die Zelle eines Umlaufs in einer Zeile. `null` heisst: Dieser Umlauf beruehrt sie nicht.
 *
 * Als Funktion statt als Ausdruck im Template — ein `kurs.cells[zeile.position]!` mit
 * Nicht-Null-Zusicherung mehrfach in einem Attribut bringt den Template-Parser ins Straucheln.
 */
function zelle(kurs: CourseGridColumn, position: number): CourseGridCell | null {
  return kurs.cells[position] ?? null
}

/**
 * Die Zeilen, unter denen eine Trennlinie sitzt: dort endet eine Fahrt.
 *
 * Bemessen ueber **alle** Spalten, nicht je Spalte — die Linie laeuft quer durch die Tabelle,
 * und sie soll dort liegen, wo ein Umlauf an der Endstelle ankommt. Am Realbestand fallen die
 * Rundenenden nahezu deckungsgleich zusammen (Linie 6 Mo-Fr: 33 Grenzzeilen auf 691 Zeilen,
 * meist alle acht Spalten auf derselben), die Linie trennt also wirklich Runde von Runde.
 *
 * Einmal als Menge statt je Zelle gefragt: Sonst liefe die Suche 691 × 8 Mal durch alle Spalten.
 */
const rundenenden = computed(() => {
  const zeilen = new Set<number>()

  for (const kurs of props.grid.courses) {
    kurs.cells.forEach((zelle, position) => {
      if (zelle?.kind === 'arrival') {
        zeilen.add(position)
      }
    })
  }

  return zeilen
})

/**
 * Erste und letzte belegte Zeile je Spalte.
 *
 * Dazwischen **faehrt das Fahrzeug**, auch wo keine Zeit steht: Es haelt an einer Endstelle, oder
 * die Zeile gehoert zum Ausrueck-Muster eines anderen Umlaufs. Darueber und darunter ist es
 * dagegen gar nicht im Dienst. Beides sieht in den Daten gleich aus — `null` — und muss in der
 * Anzeige auseinandergehalten werden, sonst zerfaellt eine Spalte optisch in Bruchstuecke.
 */
const spannen = computed(() => {
  const ergebnis = new Map<number, { von: number; bis: number }>()

  for (const kurs of props.grid.courses) {
    let von = -1
    let bis = -1

    kurs.cells.forEach((zelle, i) => {
      if (zelle !== null) {
        if (von === -1) {
          von = i
        }
        bis = i
      }
    })

    if (von !== -1) {
      ergebnis.set(kurs.id, { von, bis })
    }
  }

  return ergebnis
})

/** Eine Luecke **innerhalb** eines Umlaufs — das Fahrzeug gehoert hierher, faehrt hier aber nicht. */
function istLuecke(kurs: CourseGridColumn, position: number): boolean {
  if (zelle(kurs, position) !== null) {
    return false
  }

  const spanne = spannen.value.get(kurs.id)

  return spanne !== undefined && position > spanne.von && position < spanne.bis
}

/**
 * Ankunft **und** Abfahrt an der Endstelle werden hervorgehoben — sie sind das Paar, das die
 * Wende ausmacht, und die beiden Zeiten, die beim Pflegen zaehlen.
 */
function istEckzeit(kurs: CourseGridColumn, position: number): boolean {
  const z = zelle(kurs, position)

  return z !== null && (z.kind === 'arrival' || z.starts_trip)
}

function zellenTitel(kurs: CourseGridColumn, position: number): string | undefined {
  const z = zelle(kurs, position)

  if (z === null) {
    return undefined
  }

  return `Kurs ${kurs.number} · Linie ${z.line} · ${z.kind === 'arrival' ? 'Ankunft' : 'Abfahrt'}`
}

const spaltenbreite = computed(() => `${props.grid.courses.length * 4 + 16}rem`)
</script>

<template>
  <div class="mt-4">
    <h3 v-if="title" class="mx-auto mb-2 max-w-6xl text-sm font-semibold text-slate-800">{{ title }}</h3>

    <p
      v-if="grid.alignment_warning"
      class="mx-auto mb-3 max-w-6xl rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-900"
    >
      <strong>Die Umläufe dieser Linie laufen stark auseinander.</strong>
      Die gemeinsame Halte-Achse ist deutlich länger als der längste einzelne Umlauf — die Tabelle zeigt korrekte
      Zeiten, liest sich aber lückenhaft.
    </p>

    <!--
      Bewusst ausserhalb des sonst durchgaengigen max-w-6xl-Rahmens: Bei 16 Umlaeufen braucht die
      Tabelle die volle Breite. Kopfzeile und Haltespalte bleiben beim Scrollen stehen.
      Wichtig: border-separate statt border-collapse — bei collapse verschwinden die Rahmen von
      sticky-Zellen. Trennlinien deshalb ueber ring-*, nicht ueber border-*.
    -->
    <div class="overflow-x-auto pb-2">
      <table class="border-separate border-spacing-0 text-sm tabular-nums" :style="{ minWidth: spaltenbreite }">
        <thead>
          <tr>
            <th
              class="sticky left-0 top-0 z-30 bg-slate-50 px-4 py-2 text-left text-xs font-medium uppercase text-slate-500 ring-1 ring-slate-200"
            >
              Halt
            </th>
            <th
              v-for="kurs in grid.courses"
              :key="kurs.id"
              class="sticky top-0 z-20 min-w-16 bg-slate-50 px-2 py-2 text-center ring-1 ring-slate-200"
              :title="spaltenTitel(kurs)"
            >
              <span class="block text-xs font-semibold text-slate-900">{{ kurs.number }}</span>
              <span class="mt-0.5 flex flex-wrap justify-center gap-0.5">
                <LineBadge v-for="l in kurs.lines" :key="l" :line="signet(l)" size="sm" />
              </span>
              <span v-if="kurs.breaks > 0" class="mt-0.5 block text-[0.6rem] text-amber-700" title="Gerissene Kette">
                {{ kurs.breaks }}× gerissen
              </span>
            </th>
          </tr>
        </thead>

        <tbody>
          <tr v-for="zeile in grid.rows" :key="zeile.position" class="hover:bg-slate-50">
            <th
              scope="row"
              class="sticky left-0 z-10 max-w-64 truncate bg-white px-4 py-1 text-left font-normal text-slate-700 ring-1 ring-slate-100"
              :class="rundenenden.has(zeile.position) ? 'border-b-2 border-b-slate-500' : ''"
              :title="zeilenTitel(zeile.repeat_index)"
            >
              {{ zeile.stop_name }}
              <sup v-if="zeile.repeat_index > 0" class="ml-0.5 text-slate-400">{{ zeile.repeat_index + 1 }}.</sup>
            </th>
            <!-- Ankunft und Abfahrt an der Endstelle stehen fett: Sie sind das Paar, das die
                 Wende ausmacht. Die Trennlinie darunter schliesst die Runde ab. -->
            <td
              v-for="kurs in grid.courses"
              :key="`${kurs.id}-${zeile.position}`"
              class="px-2 py-1 text-center ring-1 ring-slate-100"
              :class="[
                zelle(kurs, zeile.position) === null
                  ? istLuecke(kurs, zeile.position)
                    ? 'text-slate-400'
                    : 'text-slate-200'
                  : 'text-slate-700',
                toenung(zelle(kurs, zeile.position)?.line ?? ''),
                istEckzeit(kurs, zeile.position) ? 'font-bold text-slate-900' : '',
                rundenenden.has(zeile.position) ? 'border-b-2 border-b-slate-500' : '',
              ]"
              :title="zellenTitel(kurs, zeile.position)"
            >
              <!--
                Drei Zustaende, nicht zwei. Eine Zeit, wo das Fahrzeug faehrt. Ein senkrechter
                Strich, wo es zum selben Umlauf gehoert, hier aber nicht faehrt — er haelt die
                Spalte zusammen, damit sie nicht in Bruchstuecke zerfaellt. Und ein Punkt, wo es
                gar nicht im Dienst ist; ganz leer waere er nicht, weil das Auge beim
                horizontalen Scrollen sonst die Zeile verliert.
              -->
              <template v-if="zelle(kurs, zeile.position)">
                {{ formatClock(zelle(kurs, zeile.position)!.time) }}
              </template>
              <template v-else>{{ istLuecke(kurs, zeile.position) ? '│' : '·' }}</template>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <p v-if="legend !== false" class="mx-auto mt-2 max-w-6xl text-xs text-slate-500">
      <span class="font-medium text-slate-400">│</span> heißt: Das Fahrzeug gehört zu diesem Umlauf, fährt diese
      Haltestelle aber nicht an — es hält gerade, oder die Zeile gehört zum Weg eines anderen Kurses aus dem
      Betriebshof.
      <span class="text-slate-300">·</span> heißt: Der Umlauf hat hier noch nicht begonnen oder ist schon zu Ende.
    </p>

    <p v-if="grid.rows.length && nebenlinien.length" class="mx-auto mt-1 max-w-6xl text-xs text-slate-500">
      Farbig unterlegt: Dort verlässt der Umlauf die
      <LineBadge :line="signet(grundlinie!)" size="sm" class="inline-block align-middle" />
      und fährt als
      <span v-for="(l, i) in nebenlinien" :key="l">
        <span class="rounded px-1 py-0.5 align-middle" :class="toenung(l)">{{ l }}</span
        ><template v-if="i < nebenlinien.length - 1"> · </template>
      </span>
      weiter.
    </p>
  </div>
</template>
