// Zeitzonen-Konvertierung nach Europe/Berlin — ausschließlich hier (Projektregel).
// Die Engine liefert alle Zeitstempel in UTC; die Anzeige erfolgt in Ortszeit.

const TZ = 'Europe/Berlin'
const LOCALE = 'de-DE'

/** Formatiert einen UTC-Zeitstempel als Datum + Uhrzeit in Europe/Berlin. */
export function formatDateTime(utc: string | Date | null | undefined): string {
  if (!utc) {
    return '—'
  }
  const date = typeof utc === 'string' ? new Date(utc) : utc
  return new Intl.DateTimeFormat(LOCALE, {
    timeZone: TZ,
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(date)
}

/** Formatiert nur die Uhrzeit (HH:MM) eines UTC-Zeitstempels in Europe/Berlin. */
export function formatTime(utc: string | Date | null | undefined): string {
  if (!utc) {
    return '—'
  }
  const date = typeof utc === 'string' ? new Date(utc) : utc
  return new Intl.DateTimeFormat(LOCALE, {
    timeZone: TZ,
    hour: '2-digit',
    minute: '2-digit',
  }).format(date)
}
