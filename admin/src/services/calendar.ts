import api from './api'

export interface SchoolHoliday {
  id: number
  name: string
  start_date: string
  end_date: string
}

export interface Holiday {
  date: string
  name: string
}

export interface SchoolHolidayInput {
  name: string
  start_date: string
  end_date: string
}

export async function fetchSchoolHolidays(): Promise<SchoolHoliday[]> {
  const { data } = await api.get('/api/v1/admin/school-holidays')
  return data.data
}

export async function createSchoolHoliday(input: SchoolHolidayInput): Promise<SchoolHoliday> {
  const { data } = await api.post('/api/v1/admin/school-holidays', input)
  return data.data
}

export async function updateSchoolHoliday(id: number, input: SchoolHolidayInput): Promise<SchoolHoliday> {
  const { data } = await api.put(`/api/v1/admin/school-holidays/${id}`, input)
  return data.data
}

export async function deleteSchoolHoliday(id: number): Promise<void> {
  await api.delete(`/api/v1/admin/school-holidays/${id}`)
}

export async function fetchHolidays(year: number): Promise<{ year: number; holidays: Holiday[] }> {
  const { data } = await api.get('/api/v1/admin/holidays', { params: { year } })
  return data.data
}
