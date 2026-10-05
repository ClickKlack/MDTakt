import type { CourseSightingMark } from '../utils/courseMark'
import api from './api'
import type { VersionInterval } from './scheduleVersions'

/**
 * Eine Zeile der Fahrplan-Matrix. Die Zeile ist eine **Position in der Haltefolge**, nicht eine
 * Halt-Identität: Wendeschleifen und Stichabstecher berühren denselben Halt zweimal, und beide
 * Berührungen brauchen eine eigene Zeile. `repeat_index` unterscheidet sie.
 */
export interface TimetableRow {
  position: number
  stop_id: number
  stop_name: string
  repeat_index: number
}

/**
 * Offene Sichtungen einer Fahrt mit derselben Kursnummer — eine Aussage mit `count` Belegen, die
 * gemeinsam angenommen oder abgelehnt wird. Die meistgenannte Nummer steht zuerst.
 */
export interface TimetableSightingGroup {
  number: string
  display: string
  count: number
  ids: number[]
  /** Betriebstage, reine Kalenderdaten */
  dates: string[]
  stops: string[]
  comparison: 'same' | 'differs' | 'none'
  /** Mindestens eine Sichtung wurde erst in der Folgeversion gefunden. */
  next_version: boolean
  /** Kettenlänge bei `differs` — für die Rückfrage vor dem Umnummerieren */
  chain_trip_count: number | null
}

/** Die Partnerfahrt eines Anschlusses — mit allem, was der Sprung in ihren Fahrplan braucht. */
export interface TimetableLinkTrip {
  id: number
  line: string
  mode: 'tram' | 'bus' | 'other'
  line_version_id: number
  version_no: number
  day_type: string | null
  period_id: number | null
  start_stop: string | null
  end_stop: string | null
  departure_time: string | null
  arrival_time: string | null
  course: string | null
}

/** Was vor oder nach einer Fahrt kommt: Anschluss, Ausrücken (`start`) oder Einrücken (`end`). */
export interface TimetableLink {
  kind: 'link' | 'start' | 'end'
  /** Durchlauf am Tauschpunkt statt Wende (KURSE §2 K10) */
  through_run: boolean
  /** `null` bei Aus-/Einrücken */
  trip: TimetableLinkTrip | null
  turnaround_seconds: number | null
  /** Kurzname des Betriebshofs, nur bei Aus-/Einrücken */
  depot: string | null
}

export interface TimetableTrip {
  id: number
  signature: string
  mode: 'tram' | 'bus' | 'other'
  departure_time: string | null
  arrival_time: string | null
  /**
   * Der Umlauf, zu dem diese Fahrt gehört. `display` trägt den Linien-Präfix (`1/03`), der
   * reine Anzeige ist — die Nummer gehört der Kette, nicht der Linie.
   */
  course: { id: number; number: string; display: string; sighting: CourseSightingMark } | null
  /** Offene Sichtungen aus MDKursTracker, nach Kursnummer gruppiert */
  sightings: TimetableSightingGroup[]
  /**
   * Listen, weil eine Fahrt je Tag einen anderen Anschluss tragen darf (KURSE §3). Leer heißt
   * offen, nicht Kettenende.
   */
  links: { before: TimetableLink[]; after: TimetableLink[] }
  /** Zeiten als „HH:MM", positionsgleich zu `rows`; null = Zeile wird nicht bedient. */
  cells: (string | null)[]
}

export interface TimetableDirection {
  key: string
  start_stop: string
  end_stop: string
  trip_count: number
  /** Über 1: mehrere Laufwege wurden auf eine gemeinsame Zeilenachse ausgerichtet. */
  variant_count: number
  /** Die Ausrichtung ist so weit auseinandergelaufen, dass die Tabelle in die Irre führen kann. */
  alignment_warning: boolean
  rows: TimetableRow[]
  trips: TimetableTrip[]
}

export interface TimetableVersion {
  id: number
  line: string
  day_type: string
  day_type_label: string
  version_no: number
  fingerprint: string
  trip_count: number
  intervals: VersionInterval[]
}

export interface Timetable {
  line_version: TimetableVersion
  period: { id: number; label: string; status: 'current' | 'frozen' } | null
  directions: TimetableDirection[]
}

export async function fetchTimetable(lineVersionId: number): Promise<Timetable> {
  const { data } = await api.get(`/api/v1/admin/line-versions/${lineVersionId}/timetable`)
  return data.data
}
