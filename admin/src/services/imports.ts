import api from './api'

export type ImportStatus = 'running' | 'success' | 'failed'

export interface ImportRun {
  id: number
  status: ImportStatus
  started_at: string | null
  finished_at: string | null
  feed_version: string | null
  feed_start_date: string | null
  feed_end_date: string | null
  counts: Record<string, number> | null
  error_message: string | null
}

export interface Pagination {
  current_page: number
  per_page: number
  total: number
  last_page: number
}

export interface ImportStatusResponse {
  current: {
    last_success_at: string | null
    feed_version: string | null
    feed_start_date: string | null
    feed_end_date: string | null
    tables: Record<string, number>
  }
  runs: ImportRun[]
  pagination: Pagination
}

/** Holt Import-Historie (paginiert) + aktuellen Datenstand (Sanctum-geschützt). */
export async function fetchImportStatus(page = 1, perPage = 10): Promise<ImportStatusResponse> {
  const { data } = await api.get('/api/v1/admin/imports', {
    params: { page, per_page: perPage },
  })
  return data.data
}
