import api from './api'

/**
 * Ein **Betriebshof** (KURSE §3.2).
 *
 * Er hängt an der Entscheidung, nicht am Kurs: Ein Fahrzeug rückt morgens aus dem einen Hof aus
 * und abends in einen anderen ein, wenn der Umlauf es dorthin trägt. Aus- und Einrückhof sind
 * deshalb zwei getrennte Angaben.
 */
export interface Depot {
  id: number
  name: string
  short_name: string | null
  /** Kurzform, wo der Platz knapp ist — sonst der volle Name. Nie leer. */
  display: string
  /**
   * Die Haltestellen, an denen dieser Hof aus- und einrücken lässt — **mehrere**, und das ist
   * der Regelfall: Die Westerhüsener Fahrten beginnen fast immer an der Schleswiger Straße und
   * nicht am Hof selbst. Leer heißt: Hier greift die Automatik nicht, der Hof wird von Hand
   * gewählt (so wie Kroatenwuhne, der an keiner Haltestelle liegt).
   */
  stop_groups: { id: number; name: string }[]
  /** Leer = **alle** Verkehrsmittel. Das ist der Vorgabefall, nicht „keines". */
  modes: ('tram' | 'bus')[]
  /** Stillgelegt heißt: an alten Entscheidungen lesbar, nimmt aber nichts Neues mehr auf. */
  active: boolean
  note: string | null
  /** Wie viele Entscheidungen daran hängen. > 0 heißt: nicht löschbar, nur stilllegbar. */
  usage_count: number
}

/** Der Hof an einer Entscheidung — knapper als das Verzeichnis, mehr braucht die Anzeige nicht. */
export interface DepotRef {
  id: number
  name: string
  display: string
  active: boolean
}

export interface DepotInput {
  name: string
  short_name?: string | null
  stop_group_ids?: number[]
  modes?: ('tram' | 'bus')[]
  active?: boolean
  note?: string | null
}

export async function fetchDepots(activeOnly = false): Promise<Depot[]> {
  const { data } = await api.get('/api/v1/admin/depots', {
    // Bewusst `1` und nicht `true`: In einer Query ist ein Boolean eine Zeichenkette, und
    // Laravels `boolean`-Regel nimmt „true" nicht an.
    params: { active_only: activeOnly ? 1 : undefined },
  })
  return data.data
}

export async function createDepot(input: DepotInput): Promise<Depot> {
  const { data } = await api.post('/api/v1/admin/depots', input)
  return data.data
}

export async function updateDepot(id: number, input: DepotInput): Promise<Depot> {
  const { data } = await api.put(`/api/v1/admin/depots/${id}`, input)
  return data.data
}

export async function deleteDepot(id: number): Promise<void> {
  await api.delete(`/api/v1/admin/depots/${id}`)
}

/**
 * Den Betriebshof einer Betriebsfahrt setzen — `null` lässt ihn wieder offen.
 *
 * Getrennt vom Anlegen der Entscheidung, weil es die übliche Reihenfolge ist: Erst wird
 * markiert, der Hof kommt dazu, sobald er feststeht.
 */
export async function setTripLinkDepot(linkId: number, depotId: number | null): Promise<void> {
  await api.put(`/api/v1/admin/trip-links/${linkId}/depot`, { depot_id: depotId })
}
