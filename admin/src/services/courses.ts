import api from './api'
import type { DepotRef } from './depots'
import type { StopLinkStand } from './stopLinks'
import type { FahrplanTyp } from './lines'

/**
 * Ein **Umlauf** als benennbare Einheit. Die Kursnummer gehört ihm, nicht der Linie: Wechselt
 * ein Fahrzeug in Sudenburg von der 1 auf die 13, bleibt die Nummer und nur der Anzeige-Präfix
 * wechselt (`1/03` → `13/03`).
 */
export interface Course {
  id: number
  period_id: number
  day_type: FahrplanTyp
  day_type_label: string
  number: string
  note: string | null
  trip_count: number
  /** Die Linien, die dieser Umlauf berührt — mehr als eine ist der Normalfall. */
  lines: string[]
  first_departure: string | null
  last_arrival: string | null
  /**
   * Ein anderer Umlauf trägt dieselbe Nummer **auf einer gemeinsamen Linie**. Die Kursnummer ist
   * je Linie bzw. Linienkombination eindeutig, nicht netzweit (KURSE §2 K3): Die „2" der Linie 8
   * ist ein anderer Umlauf als die „2" der Linie 6 und keine Dublette.
   */
  duplicate: boolean
}

export interface CourseAssignment {
  course: Course | null
  /** Länge der Kette: Der Kurs gilt immer für den ganzen Umlauf, nie für eine Fahrt allein. */
  trips_assigned: number
  trip_ids: number[]
}

export async function fetchCourses(
  periodId: number,
  dayType: FahrplanTyp,
  line?: string | null,
): Promise<Course[]> {
  const { data } = await api.get('/api/v1/admin/courses', {
    params: { period: periodId, day_type: dayType, line: line || undefined },
  })
  return data.data
}

export async function renameCourse(id: number, number: string, note?: string | null): Promise<Course> {
  const { data } = await api.put(`/api/v1/admin/courses/${id}`, { number, note: note ?? null })
  return data.data
}

export async function deleteCourse(id: number): Promise<void> {
  await api.delete(`/api/v1/admin/courses/${id}`)
}

/**
 * Setzt den Kurs für die **ganze Kette**, zu der diese Fahrt gehört. Welche Fahrt der Kette
 * übergeben wird, ist gleichgültig.
 *
 * `number` ist der übliche Weg: Wer eine Nummer am Fahrzeug abliest, tippt sie ein; ein noch
 * unbekannter Umlauf wird dabei angelegt.
 */
export async function assignCourse(tripId: number, number: string): Promise<CourseAssignment> {
  const { data } = await api.put(`/api/v1/admin/consolidated-trips/${tripId}/course`, { number })
  return data.data
}

export async function detachCourse(tripId: number): Promise<CourseAssignment> {
  const { data } = await api.delete(`/api/v1/admin/consolidated-trips/${tripId}/course`)
  return data.data
}

/** Eine Fahrt innerhalb eines Umlaufs, in Fahrreihenfolge. */
export interface CourseChainTrip {
  id: number
  line: string
  mode: 'tram' | 'bus' | 'other'
  version_no: number
  start_stop: string | null
  end_stop: string | null
  departure_time: string | null
  arrival_time: string | null
  /** Abstand zur Vorfahrt in Betriebstag-Sekunden; `null` bei der ersten Fahrt. */
  gap_before_seconds: number | null
  /**
   * Steht zwischen dieser und der Vorfahrt ein Anschluss? Zusammen mit `gap_before_seconds`
   * zu lesen: Ein großer Abstand **mit** Anschluss ist eine lange Wende, ein Abstand **ohne**
   * Anschluss eine gerissene Kette.
   */
  linked_to_previous: boolean
}

export interface CourseChain {
  id: number
  number: string
  note: string | null
  duplicate: boolean
  trip_count: number
  /** Mehr als eine ist der Normalfall — ein Umlauf läuft über Linien hinweg. */
  lines: string[]
  first_departure: string | null
  last_arrival: string | null
  /**
   * Die beiden Ketten-Enden. Drei Zustände, die auseinanderzuhalten sind:
   * `marked: false` = gar keine Marke — der Umlauf endet ins Leere, das ist die Lücke.
   * `marked: true, depot: null` = festgehalten, Hof noch offen (an einer Endstelle der Regelfall).
   * `marked: true, depot: {…}` = vollständig.
   *
   * **Aus- und Einrückhof sind nicht zwangsläufig derselbe.**
   */
  terminal_out: CourseTerminal
  terminal_in: CourseTerminal
  /** Stellen, an denen die Kette reißt. */
  breaks: number
  trips: CourseChainTrip[]
}

export interface CourseTerminal {
  marked: boolean
  depot: DepotRef | null
}

export interface LineCourseOverview {
  line: string
  period: { id: number; label: string; status: 'current' | 'frozen' }
  day_type: FahrplanTyp
  day_type_label: string
  /**
   * Die Versionsstände, über die sich die hier gezeigten Umläufe erstrecken.
   *
   * Nötig, seit eine Fahrt je Tag einen anderen Nachfolger haben darf: Wechselt eine beteiligte
   * Linie mitten in der Periode die Version, verzweigt sich der Umlauf. Beide Zweige gehören
   * demselben Fahrzeug, aber nie demselben Tag — untereinander gezeigt sähe es aus, als führe
   * es beide.
   */
  stands: StopLinkStand[]
  stand: StopLinkStand | null
  courses: CourseChain[]
  /** Fahrten dieser Linie, die zu keinem Umlauf gehören. */
  unassigned: CourseChainTrip[]
  summary: { courses: number; assigned_trips: number; unassigned_trips: number; breaks: number }
}

export async function fetchLineCourses(
  line: string,
  periodId: number,
  dayType: FahrplanTyp,
  stand?: number | null,
): Promise<LineCourseOverview> {
  const { data } = await api.get(`/api/v1/admin/lines/${encodeURIComponent(line)}/courses`, {
    params: { period: periodId, day_type: dayType, stand: stand ?? undefined },
  })
  return data.data
}

// ---------------------------------------------------------------- Umläufe als Tabelle

/** Eine Zeile der gemeinsamen Halte-Achse. Eine **Position**, keine Halt-Identität. */
export interface CourseGridRow {
  position: number
  stop_id: number
  stop_name: string
  /**
   * Die wievielte Berührung dieses Halts, ab 0. Ein Umlauf fährt hin und zurück — dieselbe
   * Haltestelle mehrfach untereinander ist keine Doppelung, sondern die nächste Runde.
   */
  repeat_index: number
}

export interface CourseGridCell {
  /** GTFS-Wallclock; „25:10:00" ist gültig und gehört zum Betriebstag des Vortags. */
  time: string | null
  /** Abfahrt — außer am letzten Halt einer Fahrt, dort die Ankunft. */
  kind: 'departure' | 'arrival'
  /**
   * Hier **beginnt** eine Fahrt.
   *
   * Zusammen mit `kind: 'arrival'` markiert das die Endstelle: eine Zeile mit der Ankunft, die
   * nächste mit der Abfahrt. `kind` allein unterschiede das nicht — eine Abfahrt am
   * Zwischenhalt sieht genauso aus.
   */
  starts_trip: boolean
  /**
   * Die Linie **dieser Fahrt**, nicht des Umlaufs. Wo der Wert umspringt, hat das Fahrzeug die
   * Linie gewechselt — die Stelle, die farbig unterlegt wird.
   */
  line: string
}

export interface CourseGridColumn {
  id: number
  number: string
  lines: string[]
  trip_count: number
  breaks: number
  duplicate: boolean
  first_departure: string | null
  last_arrival: string | null
  /** Positionsgleich zu `rows`; `null` = dieser Umlauf berührt die Zeile nicht. */
  cells: (CourseGridCell | null)[]
}

/**
 * Eine Tabelle — die Umläufe **eines Laufwegs** auf gemeinsamer Achse.
 *
 * Fast jede Linie hat genau eine. Die 1 hat zwei: Kannenstieg–Listemannstraße und
 * Sudenburg–City Carré teilen keinen Halt, also auch keinen Taktpunkt.
 */
export interface CourseGridSection {
  /** Die Endstellen dieses Laufwegs, nach Häufigkeit — die ersten beiden benennen die Tabelle. */
  termini: string[]
  /**
   * Wie die Achse entstand — und damit, was eine Zeile quer gelesen bedeutet.
   * `stop`: Takt an einer Haltestelle, die Spalten sind um ganze Umläufe verschoben und eine
   * Zeile steigt quer auf. `pattern`: feste Runden aus dem Fahrtmuster (die Verknüpfung der 1) —
   * eine Zeile ist dieselbe Stelle der Runde in derselben Runde, quer aber nicht aufsteigend.
   * `none`: unverschoben.
   */
  alignment: 'stop' | 'pattern' | 'none'
  rows: CourseGridRow[]
  /**
   * Eine Spalte je Umlauf, in Kursreihenfolge — aber gegeneinander **um ganze Umläufe
   * verschoben**, damit eine Taktzeile quer gelesen aufsteigt. Kurs 2 zeigt neben der ersten
   * Runde von Kurs 1 also seine zweite.
   */
  courses: CourseGridColumn[]
  /** Die Umläufe laufen stark auseinander — die Tabelle liest sich dann lückenhaft. */
  alignment_warning: boolean
}

export interface CourseGrid {
  line: string
  /** Derselbe Versionsstand wie in der Kettenansicht — der Umschalter wechselt nur die Form. */
  stands?: StopLinkStand[]
  stand?: StopLinkStand | null
  period: { id: number; label: string; status: 'current' | 'frozen' }
  day_type: FahrplanTyp
  day_type_label: string
  /** Eine Tabelle je Laufweg; leer, solange kein Umlauf vergeben ist. */
  sections: CourseGridSection[]
  unassigned: CourseChainTrip[]
  summary: { courses: number; assigned_trips: number; unassigned_trips: number; breaks: number }
}

/**
 * Dieselben Umläufe wie {@link fetchLineCourses}, nur als Tabelle: Halte als Zeilen, ein Kurs
 * je Spalte.
 *
 * Eigener Endpunkt, weil die Antwort schwerer wiegt — für Linie 6 Mo–Fr rund 8.500 Zellen.
 */
export async function fetchCourseGrid(
  line: string,
  periodId: number,
  dayType: FahrplanTyp,
  stand?: number | null,
): Promise<CourseGrid> {
  const { data } = await api.get(`/api/v1/admin/lines/${encodeURIComponent(line)}/course-grid`, {
    params: { period: periodId, day_type: dayType, stand: stand ?? undefined },
  })
  return data.data
}

export interface CarryoverVersion {
  id: number
  line: string
  day_type: FahrplanTyp
  day_type_label: string
  version_no: number
}

export interface CarryoverResult {
  from: CarryoverVersion
  to: CarryoverVersion
  summary: {
    paired: number
    unchanged: number
    changed: number
    added: number
    removed: number
    courses_carried: number
    course_numbers: number
    links_carried: number
    terminals_carried: number
    blocked: number
    lost: number
  }
  /** Anschlüsse auf eine andere Linie — nicht übertragbar, nach dem Wechsel neu zu setzen. */
  blocked: { trip: { id: number; line: string } | null; partner: { id: number; line: string } | null; reason: string }[]
  lost: { kind: 'link' | 'start' | 'end'; trip: { id: number; line: string } | null }[]
}

/** Garantiert folgenlos — zeigt nur, was eine Übernahme bewirken würde. */
export async function previewCarryover(toVersionId: number, fromVersionId: number): Promise<CarryoverResult> {
  const { data } = await api.get(`/api/v1/admin/line-versions/${toVersionId}/course-carryover`, {
    params: { from: fromVersionId },
  })
  return data.data
}

export async function applyCarryover(toVersionId: number, fromVersionId: number): Promise<CarryoverResult> {
  const { data } = await api.post(`/api/v1/admin/line-versions/${toVersionId}/course-carryover`, {
    from: fromVersionId,
  })
  return data.data
}

// ---------------------------------------------------------------- Nummernfolge fortschreiben

export type CourseSequenceAction = 'assign' | 'clear'

/** Eine Fahrt in der Kurzform, die die Umlauf-Pflege überall verwendet. */
export interface SequenceTrip {
  id: number
  line: string
  mode: 'tram' | 'bus' | 'other'
  start_stop: string | null
  end_stop: string | null
  departure_time: string | null
  arrival_time: string | null
}

/**
 * Eine geplante Vergabe oder Entnahme — geführt je **Kette**, nicht je Spalte.
 *
 * Die Nummer ist ein Etikett am Umlauf: Eine Zuweisung zieht die ganze Kette mit, auch Fahrten
 * der Gegenrichtung. Genau die stehen in `outside_range`.
 */
export interface CourseSequenceEntry {
  column: number
  number: string
  trip: SequenceTrip
  chain_trip_ids: number[]
  chain_trip_count: number
  outside_range: SequenceTrip[]
  course: { id: number; number: string; lines: string[]; duplicate: boolean } | null
}

export interface CourseSequenceResult {
  action: CourseSequenceAction
  line_version: { id: number; line: string; day_type: string; day_type_label: string; version_no: number }
  direction: { key: string; start_stop: string; end_stop: string; trip_count: number }
  /** `null` bei `clear`. Führende Nullen der Eingabe bleiben erhalten. */
  pattern: { input: string; numbers: string[]; count: number } | null
  range: { from_trip_id: number; to_trip_id: number; from_index: number; to_index: number; columns: number }
  summary: {
    columns: number
    planned: number
    /** Größer als `columns` — die Kette zieht mit. Das ist die Zahl für einen Bedienknopf. */
    trips_affected: number
    outside_range: number
    unchanged: number
    skipped: number
    conflicts: number
    written: number
    removed: number
    applied: boolean
  }
  assignments: CourseSequenceEntry[]
  removals: CourseSequenceEntry[]
  unchanged: { column: number; number: string; trip: SequenceTrip }[]
  skipped: { column: number; number: string | null; trip: SequenceTrip; reason_code: string; reason: string }[]
  /** Zwei Fahrten derselben Kette sollen verschiedene Nummern bekommen — nichts wird geschrieben. */
  conflicts: { numbers: string[]; chain_trip_ids: number[]; trips: SequenceTrip[]; reason: string }[]
  warnings: { code: string; message: string }[]
}

export interface CourseSequenceParams {
  from_trip_id: number
  to_trip_id: number
  action?: CourseSequenceAction
  pattern?: string
}

/** Garantiert folgenlos — die Vorschau schreibt nichts. */
export async function previewCourseSequence(
  lineVersionId: number,
  params: CourseSequenceParams,
): Promise<CourseSequenceResult> {
  const { data } = await api.get(`/api/v1/admin/line-versions/${lineVersionId}/course-sequence`, { params })
  return data.data
}

export async function applyCourseSequence(
  lineVersionId: number,
  params: CourseSequenceParams,
): Promise<CourseSequenceResult> {
  const { data } = await api.post(`/api/v1/admin/line-versions/${lineVersionId}/course-sequence`, params)
  return data.data
}
