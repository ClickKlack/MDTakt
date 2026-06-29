import type { Line } from '../services/lines'

export type LineShape = 'circle' | 'square' | 'bat'

/** Anzeige-Label auf der Form — vollständige Linienbezeichnung inkl. Buchstaben (z. B. „N1"). */
export function lineLabel(line: Line): string {
  return line.route_short_name
}

/**
 * Form je Verkehrstyp — der Typ muss dadurch nicht zusätzlich benannt werden:
 *   Tram → Kreis · Bus → Viereck · Nachtverkehr → Fledermaus.
 * Nachtverkehr erkennen wir am Buchstaben-Präfix der Linie (z. B. „N1").
 */
export function lineShape(line: Line): LineShape {
  if (/^[A-Za-z]/.test(line.route_short_name)) {
    return 'bat'
  }
  return line.mode === 'tram' ? 'circle' : 'square'
}

/** Primär-Sortierung nach Typ: Tram (0) → Bus (1) → Nachtverkehr (2). */
export function lineTypeOrder(line: Line): number {
  const shape = lineShape(line)
  return shape === 'circle' ? 0 : shape === 'square' ? 1 : 2
}

/** Sekundär-Sortierschlüssel: nur die Nummer (Buchstaben entfernt). */
export function lineSortKey(line: Line): number {
  const n = Number.parseInt(line.route_short_name.replace(/\D+/g, ''), 10)
  return Number.isNaN(n) ? Number.MAX_SAFE_INTEGER : n
}

/**
 * Linienfarbe: im Admin gepflegte Farbe (aus dem Backend) → sonst eine
 * deterministische, gut unterscheidbare Ersatzfarbe (für noch ungepflegte Linien).
 */
export function lineColor(line: Line): string {
  if (line.color) {
    return line.color
  }
  let hash = 0
  for (const ch of line.route_short_name) {
    hash = (hash * 31 + ch.charCodeAt(0)) >>> 0
  }
  return `hsl(${hash % 360} 60% 42%)`
}

/** Lesbare Textfarbe auf der Linienfarbe (Schwarz/Weiß je nach Helligkeit). */
export function lineTextColor(line: Line): string {
  const color = lineColor(line)
  const hex = /^#([0-9a-f]{6})$/i.exec(color)
  if (!hex) {
    return '#ffffff' // hsl-Fallback ist dunkel genug für weißen Text
  }
  const value = Number.parseInt(hex[1], 16)
  const r = (value >> 16) & 0xff
  const g = (value >> 8) & 0xff
  const b = value & 0xff
  const luminance = 0.2126 * r + 0.7152 * g + 0.0722 * b
  return luminance > 150 ? '#1f2937' : '#ffffff'
}
