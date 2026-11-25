/** Amounts travel as integers in the currency's minor unit; text is only converted at the edges, never through floats. */
const digits = (currency: string) => new Intl.NumberFormat('es', { style: 'currency', currency }).resolvedOptions().maximumFractionDigits ?? 2

/** "12,5" with 2 decimals is 1250. Null for anything that is not a non-negative number with at most `decimals` decimals. */
export function parseDecimal(text: string, decimals: number): number | null {
  const match = /^\s*(\d{1,9})(?:[.,](\d+))?\s*$/.exec(text)
  const fraction = match?.[2] ?? ''
  return !match || fraction.length > decimals ? null : Number(match[1] + fraction.padEnd(decimals, '0'))
}
export const decimalText = (value: number, decimals: number) => (value / 10 ** decimals).toFixed(decimals).replace('.', ',')

export const parseMoney = (text: string, currency: string) => parseDecimal(text, digits(currency))
export const moneyText = (minor: number, currency: string) => decimalText(minor, digits(currency))
export const formatMoney = (minor: number, currency: string) => new Intl.NumberFormat('es', { style: 'currency', currency }).format(minor / 10 ** digits(currency))
