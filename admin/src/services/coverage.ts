import api from './api'

export interface CoverageRange {
  from: string
  to: string
  from_confirmed: boolean
  to_confirmed: boolean
}

export interface CoverageGap {
  from: string
  to: string
  days: number
}

export interface CoverageDayType {
  day_type: string
  day_type_label: string
  ranges: CoverageRange[]
  gaps: CoverageGap[]
  versions_total: number
  versions_without_content: number
}

export interface Coverage {
  window: { from: string; to: string } | null
  totals: {
    lines: number
    versions: number
    versions_without_content: number
    consolidated_trips: number
  }
  lines: { line: string; day_types: CoverageDayType[] }[]
}

export async function fetchCoverage(): Promise<Coverage> {
  const { data } = await api.get('/api/v1/admin/coverage')
  return data.data
}
