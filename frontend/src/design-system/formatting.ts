export type NumberFormatOptions = {
  maximumFractionDigits?: number
  minimumFractionDigits?: number
}

export function formatNumber(value: string | number | null, options: NumberFormatOptions = {}): string {
  if (value === null) return '—'
  const maximumFractionDigits = options.maximumFractionDigits ?? 2
  const minimumFractionDigits = options.minimumFractionDigits ?? 0
  if (!Number.isInteger(maximumFractionDigits) || maximumFractionDigits < 0 || maximumFractionDigits > 20
    || !Number.isInteger(minimumFractionDigits) || minimumFractionDigits < 0
    || minimumFractionDigits > maximumFractionDigits) {
    throw new Error('Nieprawidłowa precyzja liczby do wyświetlenia.')
  }
  const raw = typeof value === 'number' ? String(value) : value
  const match = raw.match(/^(-?)(\d+)(?:\.(\d+))?$/)
  if (!match) throw new Error('Nieprawidłowa liczba do wyświetlenia.')

  const negative = match[1] === '-'
  const sourceFraction = match[3] ?? ''
  const scale = 10n ** BigInt(maximumFractionDigits)
  const keptFraction = sourceFraction.slice(0, maximumFractionDigits).padEnd(maximumFractionDigits, '0')
  let scaled = BigInt(match[2]) * scale + BigInt(keptFraction || '0')
  if ((sourceFraction[maximumFractionDigits] ?? '0') >= '5') scaled += 1n

  let whole = scaled / scale
  let fraction = maximumFractionDigits === 0
    ? ''
    : (scaled % scale).toString().padStart(maximumFractionDigits, '0')
  while (fraction.length > minimumFractionDigits && fraction.endsWith('0')) {
    fraction = fraction.slice(0, -1)
  }
  const sign = negative && scaled !== 0n ? '−' : ''
  const formattedWhole = new Intl.NumberFormat('pl-PL', { maximumFractionDigits: 0 }).format(whole)
  return `${sign}${formattedWhole}${fraction ? `,${fraction}` : ''}`
}
