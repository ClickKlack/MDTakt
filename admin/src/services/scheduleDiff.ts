import api from './api'
import type { VersionInterval } from './scheduleVersions'

export interface DiffTrip {
  id: number
  signature: string
  start_stop: string
  end_stop: string
  departure_time: string | null
  arrival_time: string | null
  stop_count: number
}

export interface DiffStopRow {
  stop_id: number | null
  stop_name: string
  from_time: string | null
  to_time: string | null
  delta_seconds: number | null
  status: 'equal' | 'shifted' | 'only_from' | 'only_to'
}

export interface DiffPair {
  /** `time` = nur verschoben, `route` = anderer Laufweg, `time_and_route` = beides. */
  reason: 'time' | 'route' | 'time_and_route'
  /** Versatz am ersten gemeinsamen Halt. */
  shift_seconds: number | null
  /** Alle gemeinsamen Halte um denselben Betrag verschoben. */
  uniform_shift: boolean
  from_trip: DiffTrip
  to_trip: DiffTrip
  stops?: DiffStopRow[]
}

export interface DiffVersion {
  id: number
  line: string
  day_type: string
  day_type_label: string
  version_no: number
  fingerprint: string
  trip_count: number
  intervals: VersionInterval[]
}

export interface LineVersionDiff {
  from: DiffVersion
  to: DiffVersion
  summary: {
    unchanged: number
    changed: number
    added: number
    removed: number
    from_trip_count: number
    to_trip_count: number
  }
  changed: DiffPair[]
  added: DiffTrip[]
  removed: DiffTrip[]
}

export async function fetchLineVersionDiff(
  from: number,
  to: number,
  includeStops = true,
): Promise<LineVersionDiff> {
  const { data } = await api.get('/api/v1/admin/line-version-diff', {
    params: { from, to, ...(includeStops ? { include: 'stops' } : {}) },
  })
  return data.data
}
