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

function httpErrorMessage(status: number): string {
  if (status === 401) return 'Nieprawidłowe dane logowania lub sesja wygasła.'
  if (status === 403) return 'Brak uprawnień lub aktywnego przypisania do tej lokalizacji.'
  if (status === 404) return 'Nie znaleziono żądanego zasobu.'
  if (status === 409) return 'Operacja jest sprzeczna z aktualnym stanem danych.'
  if (status === 419) return 'Sesja formularza wygasła. Spróbuj ponownie.'
  if (status === 422) return 'Sprawdź poprawność wprowadzonych danych.'
  if (status === 429) return 'Zbyt wiele prób. Odczekaj chwilę przed ponowną próbą.'
  if (status >= 500) return 'Wystąpił błąd serwera. Spróbuj ponownie później.'
  return `Nie udało się wykonać żądania (HTTP ${status}).`
}

export async function request(path: string, method = 'GET', body?: object): Promise<unknown> {
  const headers: Record<string, string> = { Accept: 'application/json' }
  if (method !== 'GET') {
    const cookie = document.cookie.split('; ').find((part) => part.startsWith('XSRF-TOKEN='))
    if (cookie) headers['X-XSRF-TOKEN'] = decodeURIComponent(cookie.slice('XSRF-TOKEN='.length))
    headers['Content-Type'] = 'application/json'
  }
  let response: Response
  try {
    response = await fetch(path, {
      method, headers, credentials: 'same-origin', cache: 'no-store',
      body: body === undefined ? undefined : JSON.stringify(body),
      signal: AbortSignal.timeout(15_000),
    })
  } catch (cause) {
    if (cause instanceof DOMException && cause.name === 'TimeoutError') {
      throw new Error('Przekroczono czas oczekiwania na odpowiedź backendu.')
    }
    throw new Error('Nie można połączyć się z backendem.')
  }
  if (response.status === 204) return null
  let result: unknown
  try {
    result = await response.json()
  } catch {
    if (response.ok) throw new Error('Backend zwrócił nieprawidłową odpowiedź.')
    throw new ApiError(response.status, httpErrorMessage(response.status))
  }
  if (!response.ok) {
    const localized = response.headers.get('Content-Language')?.toLowerCase() === 'pl'
    const message = localized && response.status < 500 && isRecord(result) && typeof result.message === 'string'
      ? result.message
      : httpErrorMessage(response.status)
    throw new ApiError(response.status, message)
  }
  if (!isRecord(result) || !('data' in result)) throw new Error('Nieprawidłowa odpowiedź API.')
  return result.data
}
