// Zeit-/Datumsformatierung für die Anzeige — ausschließlich hier (Projektregel).
//
// Regel: Zeitstempel in der LOKALEN Browser-Zeitzone, deutsches Format („12:43 Uhr").
// Die Engine liefert Zeitstempel in UTC; new Date() interpretiert das ISO-8601-Z
// korrekt und Intl formatiert ohne `timeZone`-Angabe in der Ortszeit des Browsers.
//
// Achtung: Reine Kalenderdaten (z. B. Feed-Gültigkeit, service_date) sind KEINE
// Zeitstempel und dürfen NICHT zeitzonen-verschoben werden → formatDate().

const LOCALE = 'de-DE'

function toDate(value: string | Date | null | undefined): Date | null {
  if (!value) {
    return null
  }
  const date = typeof value === 'string' ? new Date(value) : value
  return Number.isNaN(date.getTime()) ? null : date
}

/** Uhrzeit eines UTC-Zeitstempels in Browser-Ortszeit, z. B. „12:43 Uhr". */
export function formatTime(utc: string | Date | null | undefined): string {
  const date = toDate(utc)
  if (!date) {
    return '—'
  }
  const time = new Intl.DateTimeFormat(LOCALE, { hour: '2-digit', minute: '2-digit' }).format(date)
  return `${time} Uhr`
}

/** Datum + Uhrzeit eines UTC-Zeitstempels in Browser-Ortszeit, z. B. „20.06.2026, 12:43 Uhr". */
export function formatDateTime(utc: string | Date | null | undefined): string {
  const date = toDate(utc)
  if (!date) {
    return '—'
  }
  const day = new Intl.DateTimeFormat(LOCALE, {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
  }).format(date)
  return `${day}, ${formatTime(date)}`
}

/** Reines Kalenderdatum (YYYY-MM-DD) als „DD.MM.YYYY" — OHNE Zeitzonen-Verschiebung. */
export function formatDate(date: string | null | undefined): string {
  if (!date) {
    return '—'
  }
  const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(date)
  return match ? `${match[3]}.${match[2]}.${match[1]}` : date
}

/**
 * Feed-Version aus `feed_info.txt` — ein freier Bezeichner. Manchmal ein Label
 * (z. B. „latest-nv-free"), manchmal eine Zeitangabe (z. B. „2026-06-16T03:00").
 * Sieht sie wie ein ISO-Datum/-Zeit aus, wird sie lesbar gemacht — OHNE
 * Zeitzonen-Verschiebung, da der Wert keine Zeitzone trägt; sonst unverändert.
 */
export function formatFeedVersion(value: string | null | undefined): string {
  if (!value) {
    return '—'
  }
  const dateTime = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/.exec(value)
  if (dateTime) {
    return `${dateTime[3]}.${dateTime[2]}.${dateTime[1]}, ${dateTime[4]}:${dateTime[5]} Uhr`
  }
  const dateOnly = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value)
  if (dateOnly) {
    return `${dateOnly[3]}.${dateOnly[2]}.${dateOnly[1]}`
  }
  return value
}
