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

/**
 * GTFS-Lokalzeit als Uhrzeit „HH:MM".
 *
 * GTFS notiert Zeiten relativ zum **Betriebstag**, also auch jenseits 24 Uhr: „25:10" meint
 * 1:10 nachts. Angezeigt wird die echte Uhrzeit — eine Uhr zeigt nie 25:10, und „24:13" wäre
 * für den Leser schlicht falsch.
 *
 * Damit geht die Information verloren, ob eine Zeit noch zum selben Betriebstag gehört. Das
 * ist Absicht: Die **Reihenfolge** trägt sie ohnehin, und die kommt aus der Engine
 * (`departure_sort`/`arrival_sort`, siehe OperatingDayResolver) — nie aus dieser Darstellung.
 *
 * KEINE Zeitzonen-Umrechnung: Der Wert ist Netz-Lokalzeit, kein UTC-Zeitstempel.
 */
export function formatClock(time: string | null | undefined): string {
  if (!time) {
    return '—'
  }
  const match = /^(\d{1,3}):(\d{2})/.exec(time)
  if (!match) {
    return time
  }
  const stunde = Number(match[1]) % 24
  return `${String(stunde).padStart(2, '0')}:${match[2]}`
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

/**
 * Zeitversatz zwischen zwei Fahrplan-Versionen, z. B. „+1 Min", „−43 Min", „±0".
 * Reine Dauer, keine Uhrzeit — deshalb keine Zeitzonen-Umrechnung.
 */
export function formatClockDelta(seconds: number | null | undefined): string {
  if (seconds === null || seconds === undefined) {
    return '—'
  }
  if (seconds === 0) {
    return '±0'
  }

  const minuten = Math.round(Math.abs(seconds) / 60)
  const vorzeichen = seconds > 0 ? '+' : '−'

  // Unter einer Minute wuerde „+0 Min" stehen — die Sekunden sind dann die ehrlichere Angabe.
  return minuten === 0 ? `${vorzeichen}${Math.abs(seconds)} Sek` : `${vorzeichen}${minuten} Min`
}

/**
 * Dauer als Zeitspanne, z. B. „4 Min", „1 Std 12 Min", „40 Sek" — für Wendezeiten zwischen
 * zwei Fahrten eines Umlaufs. Anders als formatClockDelta ohne Vorzeichen: Eine Wendezeit ist
 * kein Versatz gegen einen Sollwert, sondern eine Spanne.
 *
 * Reine Dauer, keine Uhrzeit — deshalb keine Zeitzonen-Umrechnung. Die Eingabe stammt aus
 * GTFS-Betriebstag-Sekunden und ist auch über Mitternacht hinweg richtig (24:50 → 25:10).
 */
export function formatDuration(seconds: number | null | undefined): string {
  if (seconds === null || seconds === undefined) {
    return '—'
  }
  if (seconds < 60) {
    return `${seconds} Sek`
  }

  const minuten = Math.round(seconds / 60)
  if (minuten < 60) {
    return `${minuten} Min`
  }

  const stunden = Math.floor(minuten / 60)
  const rest = minuten % 60
  return rest === 0 ? `${stunden} Std` : `${stunden} Std ${rest} Min`
}
