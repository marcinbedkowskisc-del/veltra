import { createServer } from 'node:http'
import { mkdir, writeFile } from 'node:fs/promises'
import { resolve } from 'node:path'

const allowedNames = new Set([
  'raw-catalog-1025x577.png',
  'raw-catalog-selection-1025x577.png',
  'raw-catalog-filtered-1025x577.png',
  'raw-catalog-restricted-1025x577.png',
  'raw-catalog-not-ready-1025x577.png',
  'raw-catalog-session-expired-1025x577.png',
  'raw-catalog-1440x900.png',
  'raw-catalog-1920x1080.png',
  'raw-catalog-1440.png',
  'raw-catalog-1920.png',
  'raw-catalog-selection.png',
  'raw-catalog-filtered.png',
  'raw-catalog-restricted.png',
  'raw-catalog-not-ready.png',
  'raw-catalog-detail-1920.png',
  'raw-catalog-detail-1440.png',
  'raw-catalog-detail-restricted.png',
  'raw-catalog-detail-forbidden.png',
  'raw-catalog-detail-not-ready.png',
  'raw-material-create-empty.png',
  'raw-material-create-filled.png',
  'raw-material-create-validation.png',
  'raw-material-create-saving.png',
  'raw-material-create-success.png',
  'raw-material-create-not-ready.png',
  'raw-material-create-unauthorized.png',
  'raw-material-create-mobile.png',
])

const server = createServer(async (request, response) => {
  response.setHeader('Access-Control-Allow-Origin', 'http://127.0.0.1:4179')
  response.setHeader('Access-Control-Allow-Methods', 'POST, OPTIONS')
  response.setHeader('Access-Control-Allow-Headers', 'Content-Type')
  if (request.method === 'OPTIONS') {
    response.writeHead(204).end()
    return
  }

  const name = new URL(request.url ?? '/', 'http://127.0.0.1').pathname.split('/').pop()
  if (request.method !== 'POST' || !name || !allowedNames.has(name)
    || request.headers['content-type'] !== 'image/png') {
    response.writeHead(400).end('Żądanie odrzucone.')
    return
  }

  const chunks = []
  let size = 0
  for await (const chunk of request) {
    size += chunk.length
    if (size > 15_000_000) {
      response.writeHead(413).end('Zrzut jest zbyt duży.')
      return
    }
    chunks.push(chunk)
  }

  try {
    const bytes = Buffer.concat(chunks)
    if (!bytes.subarray(0, 8).equals(Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]))) {
      response.writeHead(400).end('Nieprawidłowy plik PNG.')
      return
    }
    const outputDirectory = resolve(name.startsWith('raw-material-create-')
      ? 'artifacts/stage4b2a'
      : name.startsWith('raw-catalog-detail-')
        ? 'artifacts/stage4b1'
        : 'artifacts/stage4a1')
    await mkdir(outputDirectory, { recursive: true })
    await writeFile(resolve(outputDirectory, name), bytes)
    response.writeHead(201, { 'Content-Type': 'application/json' }).end(JSON.stringify({ saved: name, bytes: size }))
  } catch {
    response.writeHead(500).end('Nie udało się zapisać zrzutu.')
  }
})

server.listen(4180, '127.0.0.1', () => {
  console.log('Izolowany odbiornik zrzutów gotowy na 127.0.0.1:4180')
})
