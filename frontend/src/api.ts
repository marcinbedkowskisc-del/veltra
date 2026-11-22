export class ApiError extends Error {
  status: number
  code: string | null
  errors: Record<string, string[]>
  constructor(
    status: number,
    message: string,
    code: string | null = null,
    errors: Record<string, string[]> = {},
  ) {
    super(message)
    this.status = status
    this.code = code
    this.errors = errors
  }
}

export type ApiEnvelope = {
  data: unknown
  meta?: unknown
}

type RequestOptions = {
  signal?: AbortSignal
  headers?: Record<string, string>
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

function responseError(status: number, result: unknown, response: Response): ApiError {
  const error = isRecord(result) && isRecord(result.error) ? result.error : null
  const code = error && typeof error.code === 'string' ? error.code : null
  const validationSource = isRecord(result) && isRecord(result.errors) ? result.errors : null
  const errors: Record<string, string[]> = {}
  if (validationSource) {
    Object.entries(validationSource).forEach(([field, messages]) => {
      if (Array.isArray(messages) && messages.every((message): message is string => typeof message === 'string')) {
        errors[field] = messages
      }
    })
  }
  const serverMessage = error && typeof error.message === 'string'
    ? error.message
    : isRecord(result) && typeof result.message === 'string'
      ? result.message
      : null
  const localized = response.headers.get('Content-Language')?.toLowerCase() === 'pl'
  const message = code === 'RAW_MATERIALS_MODULE_UNAVAILABLE' && serverMessage
    ? serverMessage
    : localized && status < 500 && serverMessage
      ? serverMessage
      : httpErrorMessage(status)

  return new ApiError(status, message, code, errors)
}

async function send(
  path: string,
  method: string,
  body: object | undefined,
  options: RequestOptions,
): Promise<Response> {
  const headers: Record<string, string> = { Accept: 'application/json', ...options.headers }
  const isFormData = typeof FormData !== 'undefined' && body instanceof FormData
  if (method !== 'GET') {
    const cookie = document.cookie.split('; ').find((part) => part.startsWith('XSRF-TOKEN='))
    if (cookie) headers['X-XSRF-TOKEN'] ??= decodeURIComponent(cookie.slice('XSRF-TOKEN='.length))
    if (!isFormData) headers['Content-Type'] ??= 'application/json'
  }
  try {
    return await fetch(path, {
      method, headers, credentials: 'same-origin', cache: 'no-store',
      body: body === undefined ? undefined : isFormData ? body : JSON.stringify(body),
      signal: options.signal
        ? AbortSignal.any([options.signal, AbortSignal.timeout(15_000)])
        : AbortSignal.timeout(15_000),
    })
  } catch (cause) {
    if (cause instanceof DOMException && cause.name === 'TimeoutError') {
      throw new Error('Przekroczono czas oczekiwania na odpowiedź backendu.')
    }
    if (options.signal?.aborted) throw cause
    throw new Error('Nie można połączyć się z backendem.')
  }
}

async function parseErrorResponse(response: Response): Promise<ApiError> {
  try {
    return responseError(response.status, await response.json(), response)
  } catch (cause) {
    if (cause instanceof ApiError) throw cause
    return new ApiError(response.status, httpErrorMessage(response.status))
  }
}

export async function requestEnvelope(
  path: string,
  method = 'GET',
  body?: object,
  options: RequestOptions = {},
): Promise<ApiEnvelope> {
  const response = await send(path, method, body, options)
  if (response.status === 204) return { data: null }
  let result: unknown
  try {
    result = await response.json()
  } catch {
    if (response.ok) throw new Error('Backend zwrócił nieprawidłową odpowiedź.')
    throw new ApiError(response.status, httpErrorMessage(response.status))
  }
  if (!response.ok) throw responseError(response.status, result, response)
  if (!isRecord(result) || !('data' in result)) throw new Error('Nieprawidłowa odpowiedź API.')

  return 'meta' in result ? { data: result.data, meta: result.meta } : { data: result.data }
}

export async function request(
  path: string,
  method = 'GET',
  body?: object,
  options: RequestOptions = {},
): Promise<unknown> {
  return (await requestEnvelope(path, method, body, options)).data
}

function safeDownloadName(header: string | null): string {
  const encoded = header?.match(/filename\*=UTF-8''([^;]+)/i)?.[1]
  const quoted = header?.match(/filename="([^"]+)"/i)?.[1]
  let name: string
  try {
    name = encoded ? decodeURIComponent(encoded) : quoted ?? 'katalog-raw.csv'
  } catch {
    throw new Error('Backend zwrócił nieprawidłową nazwę pliku eksportu.')
  }
  name = [...name].map((character) => {
    const code = character.charCodeAt(0)
    return character === '/' || character === '\\' || code < 32 || code === 127 ? '_' : character
  }).join('').trim()

  return name || 'katalog-raw.csv'
}

export async function requestDownload(path: string, options: RequestOptions = {}): Promise<{ blob: Blob; filename: string }> {
  const response = await send(path, 'GET', undefined, options)
  if (!response.ok) throw await parseErrorResponse(response)
  if (!response.headers.get('Content-Type')?.toLowerCase().startsWith('text/csv')) {
    throw new Error('Backend zwrócił nieprawidłowy plik eksportu.')
  }

  return {
    blob: await response.blob(),
    filename: safeDownloadName(response.headers.get('Content-Disposition')),
  }
}
