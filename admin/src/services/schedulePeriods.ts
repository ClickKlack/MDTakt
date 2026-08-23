import api from './api'

export interface SchedulePeriod {
  id: number
  label: string
  valid_from: string
  valid_to: string | null
  status: 'current' | 'frozen'
  status_label: string
  created_via: 'admin' | 'offer' | 'bootstrap'
  created_via_label: string
  line_version_count: number
  is_deletable: boolean
}

export interface SchedulePeriodInput {
  label: string
  valid_from: string
}

export async function fetchSchedulePeriods(): Promise<SchedulePeriod[]> {
  const { data } = await api.get('/api/v1/admin/schedule-periods')
  return data.data
}

export async function createSchedulePeriod(input: SchedulePeriodInput): Promise<SchedulePeriod> {
  const { data } = await api.post('/api/v1/admin/schedule-periods', input)
  return data.data
}

export async function updateSchedulePeriod(id: number, input: SchedulePeriodInput): Promise<SchedulePeriod> {
  const { data } = await api.put(`/api/v1/admin/schedule-periods/${id}`, input)
  return data.data
}

export async function deleteSchedulePeriod(id: number): Promise<void> {
  await api.delete(`/api/v1/admin/schedule-periods/${id}`)
}

export interface PeriodChangeOffer {
  id: number
  suggested_from: string
  changed_line_count: number
  active_line_count: number
  share: number | null
  lines: string[]
  // Bis wohin reicht die Beobachtung hinter dem Wechseltag? Gleich dem Wechseltag heißt:
  // ein einziger beobachteter Tag, meist der Rand des Feed-Fensters.
  observed_until: string
  single_day_observation: boolean
  status: 'open' | 'accepted' | 'declined'
  status_label: string
  decided_at: string | null
}

export async function fetchPeriodChangeOffers(): Promise<PeriodChangeOffer[]> {
  const { data } = await api.get('/api/v1/admin/period-change-offers')
  return data.data
}

export async function acceptPeriodChangeOffer(id: number, label: string): Promise<SchedulePeriod> {
  const { data } = await api.post(`/api/v1/admin/period-change-offers/${id}/accept`, { label })
  return data.data
}

export async function declinePeriodChangeOffer(id: number): Promise<void> {
  await api.post(`/api/v1/admin/period-change-offers/${id}/decline`)
}
