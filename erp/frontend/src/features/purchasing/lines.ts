/** A line as typed: numbers stay strings until saved. */
export interface DraftLine {
  item_id: string
  description: string
  quantity: string
  unit: string
  rate: string
  gst_percent: string
}
export const blankLine: DraftLine = { item_id: '', description: '', quantity: '', unit: '', rate: '', gst_percent: '' }

/** Lines ready to send; empty rows are dropped. */
export function linesForApi(lines: DraftLine[]) {
  return lines
    .filter((l) => l.item_id || l.description.trim() || l.quantity || l.rate)
    .map((l) => ({
      item_id: l.item_id ? Number(l.item_id) : null,
      description: l.description.trim() || null,
      quantity: Number(l.quantity),
      unit: l.unit.trim() || null,
      rate: Number(l.rate),
      gst_percent: Number(l.gst_percent) || 0,
    }))
}

/** First problem with the lines, checked before sending. */
export function linesProblem(lines: DraftLine[], t: (k: string) => string): string | null {
  const used = linesForApi(lines)
  if (used.length === 0) return t('purchasing.needLine')
  if (used.some((l) => !l.item_id && !l.description)) return t('purchasing.needDescription')
  if (used.some((l) => !(l.quantity > 0) || Number.isNaN(l.rate) || l.rate < 0)) return t('purchasing.needQuantityRate')
  return null
}
