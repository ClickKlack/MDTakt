import api from './api'
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
  /** Stellen, an denen die Kette reißt. */
  breaks: number
  trips: CourseChainTrip[]
}

export interface LineCourseOverview {
  line: string
  period: { id: number; label: string; status: 'current' | 'frozen' }
  day_type: FahrplanTyp
  day_type_label: string
  courses: CourseChain[]
  /** Fahrten dieser Linie, die zu keinem Umlauf gehören. */
  unassigned: CourseChainTrip[]
  summary: { courses: number; assigned_trips: number; unassigned_trips: number; breaks: number }
}

export async function fetchLineCourses(
  line: string,
  periodId: number,
  dayType: FahrplanTyp,
): Promise<LineCourseOverview> {
  const { data } = await api.get(`/api/v1/admin/lines/${encodeURIComponent(line)}/courses`, {
    params: { period: periodId, day_type: dayType },
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
