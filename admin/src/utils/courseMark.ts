/**
 * Farbe der Kursnummer nach Beleg durch Sichtungen (nur die gesichtete Fahrt, nicht die Kette):
 * grün = gesichtet, hellgrün = über Durchläufe gesichtet (KURSE §2 K10), rot = offene Sichtung
 * nennt eine andere Nummer, dunkel = gepflegt oder über Anschlüsse fortgeschrieben.
 */
export type CourseSightingMark = 'seen' | 'disputed' | 'seen_through' | null

export function courseMarkClass(mark: CourseSightingMark | undefined): string {
  switch (mark) {
    case 'seen':
      return 'bg-emerald-600 font-semibold text-white hover:bg-emerald-700'
    case 'seen_through':
      return 'bg-emerald-200 font-semibold text-emerald-950 ring-1 ring-emerald-600 hover:bg-emerald-300'
    case 'disputed':
      return 'bg-red-600 font-semibold text-white hover:bg-red-700'
    default:
      return 'bg-slate-800 font-semibold text-white hover:bg-slate-700'
  }
}

export function courseMarkTitle(mark: CourseSightingMark | undefined): string {
  switch (mark) {
    case 'seen':
      return ' · an dieser Fahrt gesichtet'
    case 'seen_through':
      return ' · über einen Durchlauf gesichtet (nicht diese Fahrt selbst)'
    case 'disputed':
      return ' · offene Sichtung nennt eine andere Nummer'
    default:
      return ''
  }
}
