import api from './api'

/** Ein Gültigkeits-Intervall einer Version; `*_confirmed` = Grenze beobachtet, nicht geraten. */
export interface VersionInterval {
  valid_from: string
  valid_to: string
  from_confirmed: boolean
  to_confirmed: boolean
}

export interface LineVersion {
  id: number
  line: string
  day_type: string
  day_type_label: string
  version_no: number
  fingerprint: string
  trip_count: number | null
  first_seen_at: string | null
  last_seen_at: string | null
  intervals: VersionInterval[]
}

export interface SchedulePeriod {
  id: number
  label: string
  valid_from: string
  valid_to: string | null
  status: 'current' | 'frozen'
  created_via: 'admin' | 'offer' | 'bootstrap'
}

export interface VersionCoverage {
  from: string | null
  to: string | null
  confirmed_boundaries: number
  open_boundaries: number
}

export interface LineVersions {
  period: SchedulePeriod | null
  coverage: VersionCoverage | null
  lines: { line: string; versions: LineVersion[] }[]
}

/**
 * Fahrplan-Änderungshistorie einer Periode (Admin/Sanctum).
 *
 * Ohne `periodId` die laufende. Die Periode mitzugeben ist nicht optionales Beiwerk: Sobald
 * eine neue Periode beginnt, wäre die Historie davor sonst unerreichbar.
 */
export async function fetchLineVersions(
  dayType?: string | null,
  line?: string | null,
  periodId?: number | null,
): Promise<LineVersions> {
  const params: Record<string, string> = {}
  if (dayType) {
    params.day_type = dayType
  }
  if (line) {
    params.line = line
  }
  if (periodId) {
    params.period = String(periodId)
  }

  const { data } = await api.get('/api/v1/admin/line-versions', { params })
  return data.data
}
