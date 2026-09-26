import type { CourseSightingMark } from '../utils/courseMark'
import api from './api'
import type { DepotRef } from './depots'
import type { FahrplanTyp } from './lines'

/**
 * Ein **Versionsstand**: ein Zeitabschnitt, in dem sich an den am Halt beteiligten
 * Linien-Versionen nichts ändert.
 *
 * Der Editor arbeitet auf Periode und Fahrplantyp, nicht auf einem Datum. Innerhalb einer
 * Periode kann eine Linie aber mehrere Versionen haben — an einem Umsteigepunkt stehen Linie 1
 * und Linie 13 dann womöglich auf verschiedenen Ständen, und die Auswahl wäre mehrdeutig.
 */
export interface StopLinkStand {
  index: number
  /** Gesamtspanne — erster und letzter Tag, an dem dieser Stand gilt. */
  valid_from: string
  valid_to: string
  /**
   * Die einzelnen Zeiträume. Ein Stand ist über seine **Versionsmenge** identifiziert, nicht
   * über eine durchgehende Laufzeit: Nachtlinien wechseln im Mo-Fr-Strang wöchentlich die
   * Version, weshalb derselbe Stand für alle Montage gilt und ein anderer für alle Di–Fr.
   */
  ranges: { valid_from: string; valid_to: string }[]
  line_version_ids: number[]
  line_count: number
  /**
   * Noch offene Fahrten **in diesem Stand**, je Verkehrsmittel plus `total`.
   *
   * Ohne sie bliebe ein Widerspruch unauflösbar: Eine Nachtlinie hat im Mo-Fr-Strang
   * regelmäßig Eintagsversionen, und daraus wird ein Stand, der einen einzigen Tag umfasst.
   * Wer im Hauptstand alles entschieden hat, sieht dort 0 und hält die Haltestelle für fertig,
   * während die Auswahlliste — die über die ganze Periode zählt — noch eine Fahrt meldet.
   */
  open: { total: number } & Partial<Record<'tram' | 'bus', number>>
}

export type TripLinkKind = 'link' | 'start' | 'end'

/**
 * Die an einer Fahrt getroffene Entscheidung.
 *
 * `null` heißt **noch nicht gepflegt** — ausdrücklich etwas anderes als `start`/`end`, die eine
 * bewusst offen gelassene Kette bezeichnen — die Betriebsfahrt aus dem bzw. in den Betriebshof
 * (fachlich: Aus- bzw. Einrücken).
 */
export interface StopLinkDecision {
  id: number
  kind: TripLinkKind
  partner: StopLinkTrip | null
  /** Nur bei `kind: 'link'`. In Betriebstag-Sekunden — 24:50 → 25:10 ergibt 1200. */
  turnaround_seconds: number | null
  /**
   * Der Betriebshof — nur bei `start`/`end`, und auch dort freiwillig. `null` heißt **noch
   * offen**, nicht „kein Hof": An einer Endstelle steht er oft nicht fest.
   */
  depot: DepotRef | null
  note: string | null
}

export interface StopLinkTrip {
  id: number
  line: string
  mode: 'tram' | 'bus' | 'other'
  version_no: number
  line_version_id: number
  start_stop: string | null
  end_stop: string | null
  /** GTFS-Wallclock des Betriebstags — „25:10:00" ist gültig und gewollt. */
  departure_time: string | null
  arrival_time: string | null
  /**
   * Sortierschlüssel **entlang des Betriebstags**, aus der Engine. Auf der N1 fährt 22:49 vor
   * 00:19 — nach der Uhr sortiert stünde die halbe Nacht am Anfang. Immer danach sortieren,
   * nie nach der angezeigten Uhrzeit.
   */
  departure_sort: number
  arrival_sort: number
  course: { id: number; number: string; display: string; sighting: CourseSightingMark } | null
  decision: StopLinkDecision | null
}

export interface StopLinkBoard {
  /** Die Haltestelle samt ihrer einzelnen Bahnsteige — die bleiben sichtbar, nicht versteckt. */
  stop_group: { id: number; name: string; stops: { id: number; name: string }[] }
  period: { id: number; label: string; status: 'current' | 'frozen' }
  day_type: FahrplanTyp
  day_type_label: string
  stands: StopLinkStand[]
  stand: StopLinkStand | null
  /** Fahrten, die hier **enden**, nach Ankunft sortiert. */
  ending: StopLinkTrip[]
  /** Fahrten, die hier **beginnen**, nach Abfahrt sortiert. */
  starting: StopLinkTrip[]
  /** Zahl der Fahrten ohne Entscheidung — der Pflegestand auf einen Blick. */
  open_count: number
}

export interface TripLinkWarning {
  /**
   * `course_conflict`: Beide Ketten trugen bereits verschiedene Kursnummern. Die Verknüpfung
   * bleibt bestehen — sie ist eine Aussage über das Fahrzeug —, aber der Kurs ist zu klären.
   */
  code: 'short_turnaround' | 'line_change' | 'course_conflict'
  message: string
}

export interface TripLinkResult {
  id: number
  kind: TripLinkKind
  stop_id: number
  from_trip: StopLinkTrip | null
  to_trip: StopLinkTrip | null
  turnaround_seconds: number | null
  depot: DepotRef | null
  note: string | null
  /** Hinweise, die die Verknüpfung **nicht** verhindern. */
  warnings: TripLinkWarning[]
  /**
   * Der Kurs der Kette nach dem Anschluss. Zwei verknüpfte Fahrten sind dasselbe Fahrzeug,
   * also derselbe Kurs: Trug eine Seite bereits eine Nummer, gilt sie jetzt für beide.
   */
  course: { id: number; number: string; lines: string[]; duplicate: boolean } | null
  /** Auf wie viele Fahrten der Kurs dabei übertragen wurde. 0 = es gab nichts zu übertragen. */
  course_trips_assigned: number
}

export interface TripLinkInput {
  kind: TripLinkKind
  from_trip_id?: number | null
  to_trip_id?: number | null
  /** Nur bei `start`/`end`. Meist wird erst markiert und der Hof danach nachgetragen. */
  depot_id?: number | null
  note?: string | null
}

export async function fetchStopLinkBoard(
  stopGroupId: number,
  periodId: number,
  dayType: FahrplanTyp,
  stand?: number | null,
): Promise<StopLinkBoard> {
  const { data } = await api.get('/api/v1/admin/stop-links', {
    params: { stop_group: stopGroupId, period: periodId, day_type: dayType, stand: stand ?? undefined },
  })
  return data.data
}

export async function createTripLink(input: TripLinkInput): Promise<TripLinkResult> {
  const { data } = await api.post('/api/v1/admin/trip-links', input)
  return data.data
}

export async function deleteTripLink(id: number): Promise<void> {
  await api.delete(`/api/v1/admin/trip-links/${id}`)
}

// ---------------------------------------------------------------- Mengen-Lauf über einen Zeitraum

export type AutoLinkAction = 'link' | 'unlink'

/** Ein geplanter oder angelegter Übergang. */
export interface AutoLinkPair {
  from_trip: StopLinkTrip
  to_trip: StopLinkTrip
  turnaround_seconds: number | null
  warnings: { code: 'short_turnaround' | 'line_change'; message: string }[]
  course: { id: number; number: string; lines: string[]; duplicate: boolean } | null
  course_trips_assigned: number
  /** Beide Ketten trugen bereits verschiedene Nummern — nichts wurde überschrieben. */
  course_conflict: boolean
}

/** Eine Entscheidung, die aufgelöst wird. */
export interface AutoLinkRemoval {
  trip_link_id: number
  kind: TripLinkKind
  from_trip: StopLinkTrip | null
  to_trip: StopLinkTrip | null
  turnaround_seconds: number | null
  /** Die Gegenfahrt liegt außerhalb des markierten Bereichs — gelöst wird trotzdem. */
  partner_outside_range: boolean
}

export interface AutoLinkSkip {
  side: 'ending' | 'starting'
  trip: StopLinkTrip
  partner: StopLinkTrip | null
  reason_code: string
  reason: string
}

export interface AutoLinkResult {
  action: AutoLinkAction
  stop_group: { id: number; name: string }
  period: { id: number; label: string; status: 'current' | 'frozen' }
  day_type: FahrplanTyp
  day_type_label: string
  stand: StopLinkStand | null
  filter: {
    lines: string[]
    mode: 'tram' | 'bus' | null
    from_trip_id: number
    to_trip_id: number
    min_turnaround_seconds: number
    max_turnaround_seconds: number
    include_terminals: boolean
    through_stop: boolean
  }
  /** Die konfigurierten Vorgabewerte — damit der Dialog sie nicht doppelt kennen muss. */
  defaults: { min_turnaround_seconds: number; max_turnaround_seconds: number }
  range: { from_trip: StopLinkTrip; to_trip: StopLinkTrip; trips: number }
  summary: {
    candidates: number
    planned: number
    created: number
    removed: number
    skipped: number
    courses_unified: number
    course_conflicts: number
    applied: boolean
  }
  pairs: AutoLinkPair[]
  removals: AutoLinkRemoval[]
  skipped: AutoLinkSkip[]
}

export interface AutoLinkParams {
  stop_group: number
  period: number
  day_type: FahrplanTyp
  from_trip_id: number
  to_trip_id: number
  stand?: number | null
  action?: AutoLinkAction
  lines?: string[]
  mode?: 'tram' | 'bus' | null
  min_turnaround_minutes?: number
  max_turnaround_minutes?: number
  include_terminals?: boolean
  /**
   * Tauschpunkt statt Wendestelle: Gepaart wird nur, was an **demselben Halt** weiterfährt, an
   * dem die Ankunft endet. An einer Haltestelle, an der das Fahrzeug nur kurz hält und
   * weiterfährt, kommen mehrere Fahrten zeitgleich an und fahren zeitgleich ab — die Zeit
   * unterscheidet dort nichts.
   */
  through_stop?: boolean
}

/** Garantiert folgenlos — die Vorschau schreibt nichts. */
export async function previewAutoLinks(params: AutoLinkParams): Promise<AutoLinkResult> {
  const { data } = await api.get('/api/v1/admin/stop-links/auto', { params })
  return data.data
}

export async function applyAutoLinks(params: AutoLinkParams): Promise<AutoLinkResult> {
  const { data } = await api.post('/api/v1/admin/stop-links/auto', params)
  return data.data
}
