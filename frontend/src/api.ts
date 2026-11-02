export class ApiError extends Error {
  status: number
  constructor(status: number, message: string) {
    super(message)
    this.status = status
  }
}

export function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null
}

export async function request(path: string, method = 'GET', body?: object): Promise<unknown> {
  const headers: Record<string, string> = { Accept: 'application/json' }
  if (method !== 'GET') {
    const cookie = document.cookie.split('; ').find((part) => part.startsWith('XSRF-TOKEN='))
    if (cookie) headers['X-XSRF-TOKEN'] = decodeURIComponent(cookie.slice('XSRF-TOKEN='.length))
    headers['Content-Type'] = 'application/json'
  }
  const response = await fetch(path, {
    method, headers, credentials: 'same-origin', cache: 'no-store',
    body: body === undefined ? undefined : JSON.stringify(body),
    signal: AbortSignal.timeout(15_000),
  })
  if (response.status === 204) return null
  const result: unknown = await response.json()
  if (!response.ok) {
    const message = response.status === 401 ? 'Nieprawidłowe dane logowania lub sesja wygasła.'
      : response.status === 419 ? 'Sesja formularza wygasła. Spróbuj ponownie.'
      : response.status === 429 ? 'Zbyt wiele prób. Odczekaj minutę.'
      : response.status === 403 ? 'Brak uprawnień lub aktywnego przypisania do tej lokalizacji.'
      : isRecord(result) && typeof result.message === 'string' ? result.message : `Błąd HTTP ${response.status}.`
    throw new ApiError(response.status, message)
  }
  if (!isRecord(result) || !('data' in result)) throw new Error('Nieprawidłowa odpowiedź API.')
  return result.data
}
