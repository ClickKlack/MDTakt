import api from './api'

export interface Line {
  route_id: string
  route_short_name: string
  route_type: number
  mode: 'tram' | 'bus' | 'other'
  // Im Admin gepflegte Linienfarbe (Hex inkl. '#'); null = nicht gesetzt → Fallback.
  color?: string | null
}

/** Linienfarbe setzen (Admin/Sanctum). */
export async function setLineColor(line: string, color: string): Promise<void> {
  await api.put(`/api/v1/admin/line-colors/${encodeURIComponent(line)}`, { color })
}

/** Linienfarbe zurücksetzen (Admin/Sanctum). */
export async function resetLineColor(line: string): Promise<void> {
  await api.delete(`/api/v1/admin/line-colors/${encodeURIComponent(line)}`)
}

export interface LineTripItem {
  trip_id: string
  service_id: string
  departure_time: string | null
  arrival_time: string | null
}

export interface LineTripGroup {
  start_stop: string
  end_stop: string
  trip_count: number
  trips: LineTripItem[]
}

export interface LineTrips {
  line: string
  trip_count: number
  groups: LineTripGroup[]
}

/** Alle MVB-Linien. */
export async function fetchLines(): Promise<Line[]> {
  const { data } = await api.get('/api/v1/lines')
  return data.data
}

/** Fahrten einer Linie, gruppiert nach Start → Ziel. */
export async function fetchLineTrips(line: string): Promise<LineTrips> {
  const { data } = await api.get(`/api/v1/lines/${encodeURIComponent(line)}/trips`)
  return data.data
}
