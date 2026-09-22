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
  /**
   * Die Verkehrsmittel, die in **Periode und Fahrplantyp der Abfrage** hier beginnen oder
   * enden. Leer, wenn die Abfrage ohne beides lief — dann ist auch `open` null.
   */
  modes: ('tram' | 'bus')[]
  /**
   * Noch offene Fahrten je Verkehrsmittel, plus `total`.
   *
   * **Über die ganze Periode gezählt, nicht je Versionsstand.** Die Zahl kann deshalb höher
   * liegen als die im Editor, wenn dort ein Stand gewählt ist, der nur einen Teil der Periode
   * abdeckt. Für die Frage, die die Auswahlliste beantwortet — ist hier noch etwas zu tun? —
   * trägt das: Sie ist nie fälschlich null.
   */
  open: ({ total: number } & Partial<Record<'tram' | 'bus', number>>) | null
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

/**
 * Das Haltestellen-Verzeichnis.
 *
 * Mit `period` **und** `dayType` tragen die Zeilen zusätzlich `modes` und `open` — erst beide
 * zusammen benennen einen Fahrplan, und erst dann lässt sich sagen, was hier noch offen ist.
 */
export async function fetchStopGroups(
  q?: string | null,
  onlyTermini = false,
  period?: number | null,
  dayType?: string | null,
): Promise<StopGroup[]> {
  const { data } = await api.get('/api/v1/admin/stop-groups', {
    params: {
      q: q || undefined,
      // Bewusst `1` und nicht `true`: In einer Query ist ein Boolean eine Zeichenkette.
      only_termini: onlyTermini ? 1 : undefined,
      period: period ?? undefined,
      day_type: dayType ?? undefined,
    },
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
