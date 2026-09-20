import api from './api'

/**
 * Ein einzelner Halt innerhalb einer Haltestelle — ein physischer Punkt, üblicherweise ein
 * Richtungs-Bahnsteig.
 */
export interface StopGroupStop {
  id: number
  name: string
  /** `manual` schützt die Zuordnung vor der Automatik. */
  assigned_via: 'auto' | 'manual'
  lat: number
  lon: number
  ending_lines: string[]
  starting_lines: string[]
}

/**
 * Eine **Haltestelle** als Betriebspunkt: die Klammer um die Halte, die im Betrieb derselbe
 * Ort sind.
 *
 * Nötig, weil die Konsolidierung Halte nur bei ≤ 12 m und gleichem Namen verschmilzt. An einer
 * Endstelle liegen Ankunft und Abfahrt oft weiter auseinander — ohne diese Klammer ließe sich
 * dort kein Anschluss bilden.
 */
export interface StopGroup {
  id: number
  name: string
  created_via: 'auto' | 'manual'
  note: string | null
  stop_count: number
  stops: StopGroupStop[]
  ending_lines: string[]
  starting_lines: string[]
  /** Hier beginnt oder endet mindestens eine Fahrt — nur dort ist Umlauf-Pflege möglich. */
  is_terminus: boolean
  /** Es endet nur oder beginnt nur: der Hinweis auf ein fehlendes Gegenstück. */
  one_sided: boolean
  manual_count: number
}

export interface StopGroupSuggestion {
  id: number
  name: string
  distance_meters: number
  stop_count: number
}

export interface StopGroupDetail extends StopGroup {
  suggestions: StopGroupSuggestion[]
}

export async function fetchStopGroups(q?: string | null, onlyTermini = false): Promise<StopGroup[]> {
  const { data } = await api.get('/api/v1/admin/stop-groups', {
    params: { q: q || undefined, only_termini: onlyTermini ? 1 : undefined },
  })
  return data.data
}

export async function fetchStopGroup(id: number): Promise<StopGroupDetail> {
  const { data } = await api.get(`/api/v1/admin/stop-groups/${id}`)
  return data.data
}

export async function createStopGroup(name: string, note?: string | null): Promise<StopGroup> {
  const { data } = await api.post('/api/v1/admin/stop-groups', { name, note: note ?? null })
  return data.data
}

export async function renameStopGroup(id: number, name: string, note?: string | null): Promise<StopGroup> {
  const { data } = await api.put(`/api/v1/admin/stop-groups/${id}`, { name, note: note ?? null })
  return data.data
}

/** Legt die Quell-Haltestelle in die Ziel-Haltestelle — der Weg für „Rothensee (Schleife)" → „Rothensee". */
export async function mergeStopGroup(targetId: number, sourceId: number): Promise<void> {
  await api.post(`/api/v1/admin/stop-groups/${targetId}/merge`, { source_id: sourceId })
}

export async function assignStop(groupId: number, stopId: number): Promise<void> {
  await api.post(`/api/v1/admin/stop-groups/${groupId}/stops`, { consolidated_stop_id: stopId })
}

/** Gibt den Halt der Automatik zurück: Er landet wieder in der Gruppe seines Namens. */
export async function detachStop(groupId: number, stopId: number): Promise<void> {
  await api.delete(`/api/v1/admin/stop-groups/${groupId}/stops/${stopId}`)
}
