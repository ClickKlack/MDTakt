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
   * Ein anderer Umlauf im selben Strang trägt dieselbe Nummer. **Kein Fehler** — ob die
   * Kursnummer netzweit eindeutig ist, ist offen. Gemeldet wird es, weil es Tippfehler findet.
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
