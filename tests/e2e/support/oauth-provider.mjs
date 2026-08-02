import { createHash, randomUUID, timingSafeEqual } from 'node:crypto'
import { createServer } from 'node:http'

const host = '127.0.0.1'
const port = 4174
const clientId = 'playwright-client'
const clientSecret = 'playwright-client-secret'
const redirectUri = 'http://127.0.0.1:4173/oauth/callback'

const identities = new Map([
  ['record-owner@example.test', {
    password: 'owner-password',
    sub: 'playwright-owner',
    name: 'E2E Record Owner',
    email: 'record-owner@example.test',
  }],
  ['unrelated-user@example.test', {
    password: 'outsider-password',
    sub: 'playwright-outsider',
    name: 'E2E Unrelated User',
    email: 'unrelated-user@example.test',
  }],
])
const authorizationCodes = new Map()
const accessTokens = new Map()

function respond(response, status, body, contentType = 'text/plain; charset=utf-8') {
  response.writeHead(status, {
    'cache-control': 'no-store',
    'content-type': contentType,
    'x-content-type-options': 'nosniff',
  })
  response.end(body)
}

function safeEqual(left, right) {
  const leftBuffer = Buffer.from(left)
  const rightBuffer = Buffer.from(right)
  return leftBuffer.length === rightBuffer.length && timingSafeEqual(leftBuffer, rightBuffer)
}

function authorizeForm(params, error = '') {
  const hidden = ['client_id', 'redirect_uri', 'state', 'code_challenge', 'code_challenge_method']
    .map((name) => `<input type="hidden" name="${name}" value="${escapeHtml(params.get(name) ?? '')}">`)
    .join('')

  return `<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Synthetic identity provider</title></head>
<body><main><h1>Synthetic identity provider</h1>
${error ? `<p role="alert">${escapeHtml(error)}</p>` : ''}
<form method="post" action="/oauth/authorize">${hidden}
<label>Email <input name="email" type="email" autocomplete="username" required></label>
<label>Password <input name="password" type="password" autocomplete="current-password" required></label>
<button type="submit">Authorize</button>
</form></main></body></html>`
}

function escapeHtml(value) {
  return value.replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('"', '&quot;')
}

async function readForm(request) {
  const chunks = []
  for await (const chunk of request) chunks.push(chunk)
  return new URLSearchParams(Buffer.concat(chunks).toString('utf8'))
}

const server = createServer(async (request, response) => {
  const url = new URL(request.url ?? '/', `http://${host}:${port}`)

  if (request.method === 'GET' && url.pathname === '/health') {
    respond(response, 200, 'ok')
    return
  }

  if (request.method === 'GET' && url.pathname === '/oauth/authorize') {
    const valid = url.searchParams.get('client_id') === clientId
      && url.searchParams.get('redirect_uri') === redirectUri
      && url.searchParams.get('code_challenge_method') === 'S256'
      && Boolean(url.searchParams.get('state'))
      && Boolean(url.searchParams.get('code_challenge'))
    respond(response, valid ? 200 : 400, valid ? authorizeForm(url.searchParams) : 'Invalid authorization request.', 'text/html; charset=utf-8')
    return
  }

  if (request.method === 'POST' && url.pathname === '/oauth/authorize') {
    const form = await readForm(request)
    const identity = identities.get(form.get('email') ?? '')
    if (!identity || !safeEqual(identity.password, form.get('password') ?? '')) {
      respond(response, 401, authorizeForm(form, 'Invalid synthetic credentials.'), 'text/html; charset=utf-8')
      return
    }

    if (form.get('client_id') !== clientId
      || form.get('redirect_uri') !== redirectUri
      || form.get('code_challenge_method') !== 'S256'
      || !form.get('state')
      || !form.get('code_challenge')) {
      respond(response, 400, 'Invalid authorization request.')
      return
    }

    const code = randomUUID()
    authorizationCodes.set(code, {
      identity,
      challenge: form.get('code_challenge'),
    })
    const callback = new URL(redirectUri)
    callback.searchParams.set('code', code)
    callback.searchParams.set('state', form.get('state'))
    response.writeHead(302, { location: callback.toString(), 'cache-control': 'no-store' })
    response.end()
    return
  }

  if (request.method === 'POST' && url.pathname === '/oauth/token') {
    const form = await readForm(request)
    const code = form.get('code') ?? ''
    const authorization = authorizationCodes.get(code)
    authorizationCodes.delete(code)
    const verifier = form.get('code_verifier') ?? ''
    const actualChallenge = createHash('sha256').update(verifier).digest('base64url')
    const valid = authorization
      && form.get('grant_type') === 'authorization_code'
      && form.get('client_id') === clientId
      && form.get('client_secret') === clientSecret
      && form.get('redirect_uri') === redirectUri
      && safeEqual(authorization.challenge, actualChallenge)
    if (!valid) {
      respond(response, 400, JSON.stringify({ error: 'invalid_grant' }), 'application/json')
      return
    }

    const token = randomUUID()
    accessTokens.set(token, authorization.identity)
    respond(response, 200, JSON.stringify({ access_token: token, token_type: 'Bearer' }), 'application/json')
    return
  }

  if (request.method === 'GET' && url.pathname === '/api/oauth/user') {
    const token = (request.headers.authorization ?? '').replace(/^Bearer\s+/i, '')
    const identity = accessTokens.get(token)
    if (!identity) {
      respond(response, 401, JSON.stringify({ error: 'unauthenticated' }), 'application/json')
      return
    }
    respond(response, 200, JSON.stringify({ sub: identity.sub, name: identity.name, email: identity.email }), 'application/json')
    return
  }

  respond(response, 404, 'Not found.')
})

server.listen(port, host, () => {
  process.stdout.write(`Synthetic OAuth provider ready at http://${host}:${port}\n`)
})
