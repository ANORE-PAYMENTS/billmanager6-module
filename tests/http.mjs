import assert from 'node:assert/strict'
import { spawn, spawnSync } from 'node:child_process'
import { createHash, createHmac } from 'node:crypto'
import { once } from 'node:events'
import { chmod, copyFile, cp, mkdir, mkdtemp, readdir, readFile, rm, writeFile } from 'node:fs/promises'
import { createServer as createHttpServer } from 'node:http'
import { createServer as createHttpsServer } from 'node:https'
import { tmpdir } from 'node:os'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const testsDirectory = path.dirname(fileURLToPath(import.meta.url))
const php = process.argv[2] || process.env.PHP_BINARY || 'php'
const openssl = process.env.OPENSSL_BINARY || (process.platform === 'win32' ? 'C:/Program Files/Git/usr/bin/openssl.exe' : 'openssl')
const filter = process.env.BILLMANAGER_TEST_FILTER ? new RegExp(process.env.BILLMANAGER_TEST_FILTER, 'i') : null
const version = spawnSync(php, ['-v'], { encoding: 'utf8' })
if (version.status !== 0) throw new Error(`PHP CLI is required: ${version.error || version.stderr}`)
const fixture = await mkdtemp(path.join(tmpdir(), 'anore-billmanager-test-'))
const manager = path.join(fixture, 'mgr5')
const stateDirectory = path.join(manager, 'var', 'anore-payments')
const stateFile = path.join(fixture, 'fixture.json')
const paymentId = '0ad952c8-1df9-4c4a-8873-6fbb3321f017'
const olderPaymentId = 'ccce9d70-76dc-4a96-a828-bf9266f83b88'
const secret = 'billmanager-test-webhook-secret'
const managerUrl = 'https://billing.example.test/billmgr'
const orderId = `billmanager6:${createHash('sha256').update(managerUrl).digest('hex').slice(0, 12)}:42`
let server
let apiServer
let serverOutput = ''
let apiOrigin
let origin
let apiRequests = []
let apiResponseOverride
let apiStatus = 201
let passed = 0

function baseState() {
  return {
    payment: {
      id: '42', paymethodamount: '123.45', amount: '123.45', number: 'INV-42', status: '1', paid: 'off',
      manager_url: managerUrl, email: 'customer@example.test', useremail: 'customer@example.test', externalid: '',
      client: { id: '7', email: 'customer@example.test' },
      currency: { id: '1', iso: 'RUB' },
      paymethod: {
        id: '9', module: 'pmanore.php', api_url: `${apiOrigin}/api/v1`, api_key: 'fixture-api-key',
        api_secret: 'fixture-api-secret', webhook_secret: secret, shop_id: '27', methods: 'sbp,card,crypto-old,card',
      },
    },
    calls: [], credits: [], logs: [], failures: {},
  }
}

function legacyMapping(overrides = {}) {
  return {
    state: 'ready', billmanagerPaymentId: 42, anorePaymentId: paymentId,
    paymentUrl: `https://pay.anore.cc/${paymentId}`, orderId, amount: '123.45', currency: 'RUB',
    expiresAt: '2099-01-01T00:00:00+00:00', createdAt: '2026-01-01T00:00:00+00:00',
    ...overrides,
  }
}

function success(overrides = {}) {
  return { event: 'payment.succeeded', id: paymentId, orderId, amount: 123.45, currency: 'RUB', ...overrides }
}

async function state() { return JSON.parse(await readFile(stateFile, 'utf8')) }
async function save(value) { await writeFile(stateFile, JSON.stringify(value), 'utf8') }
async function seedMapping(mapping = legacyMapping()) {
  await mkdir(stateDirectory, { recursive: true })
  await writeFile(path.join(stateDirectory, '42.json'), JSON.stringify(mapping), 'utf8')
}

async function mappings() {
  const result = []
  async function scan(directory) {
    for (const item of await readdir(directory, { withFileTypes: true })) {
      const name = path.join(directory, item.name)
      if (item.isDirectory()) await scan(name)
      else if (item.name.endsWith('.json')) result.push(JSON.parse(await readFile(name, 'utf8')))
    }
  }
  await scan(stateDirectory)
  return result
}

async function expireMappings() {
  for (const item of await readdir(stateDirectory)) {
    if (!item.endsWith('.json')) continue
    const filename = path.join(stateDirectory, item)
    const value = JSON.parse(await readFile(filename, 'utf8'))
    function expire(object) {
      if (!object || typeof object !== 'object') return
      if (object.expiresAt) object.expiresAt = '2020-01-01T00:00:00+00:00'
      for (const child of Object.values(object)) expire(child)
    }
    expire(value)
    await writeFile(filename, JSON.stringify(value), 'utf8')
  }
}

function allAttempts(value) {
  if (Array.isArray(value)) return value.flatMap(allAttempts)
  if (!value || typeof value !== 'object') return []
  return [ ...(value.anorePaymentId ? [value] : []), ...Object.values(value).flatMap(allAttempts) ]
}

async function test(name, run, options = {}) {
  if (filter && !filter.test(name)) return
  await rm(stateDirectory, { recursive: true, force: true })
  await mkdir(stateDirectory, { recursive: true })
  const value = baseState()
  if (options.change) options.change(value)
  await save(value)
  if (options.mapping !== false) await seedMapping(options.mapping || legacyMapping())
  apiRequests = []
  apiResponseOverride = undefined
  apiStatus = 201
  await run()
  passed += 1
  console.log(`PASS ${name}`)
}

async function request(payload = success(), options = {}) {
  const raw = typeof payload === 'string' ? payload : JSON.stringify(payload)
  const method = options.method || 'POST'
  const response = await fetch(`${origin}/mancgi/anoreresult.php${options.query || ''}`, {
    method,
    headers: {
      'Content-Type': 'application/json',
      'Anore-Signature': options.signature ?? createHmac('sha256', secret).update(raw).digest('hex'),
    },
    ...(method === 'GET' ? {} : { body: raw }),
  })
  return { status: response.status, body: await response.text() }
}

async function expectStatus(expected, payload = success(), options = {}) {
  const response = await request(payload, options)
  assert.equal(response.status, expected, `response=${JSON.stringify(response)}\nserver=${serverOutput}`)
  if (expected === 200) assert.ok(['OK', 'Ignored'].includes(response.body.trim()), response.body)
  return response
}

async function createPayment(params = {}, options = {}) {
  const query = new URLSearchParams({ elid: '42', ...(options.cookieOnly ? {} : { auth: 'fixture-auth' }), ...params })
  const response = await fetch(`${origin}/mancgi/anorepayment.php?${query}`, {
    redirect: 'manual', headers: options.cookie ? { Cookie: options.cookie } : {},
  })
  return { status: response.status, location: response.headers.get('location'), body: await response.text() }
}

async function expectCreated(params = {}) {
  const response = await createPayment(params)
  assert.equal(response.status, 302, `response=${JSON.stringify(response)}\nserver=${serverOutput}`)
  assert.match(response.location, /^https:\/\/pay\.anore\.cc\//)
  return response
}

async function expectNoCredit() { assert.deepEqual((await state()).credits, []) }

function recover(args = []) {
  return spawnSync(php, [path.join(manager, 'paymethods', 'anore-recover.php'), '--payment=42', ...args], {
    env: { ...process.env, BILLMANAGER_FIXTURE_STATE: stateFile }, encoding: 'utf8',
  })
}

try {
  await cp(path.join(testsDirectory, '..', 'src'), manager, { recursive: true })
  await cp(path.join(manager, 'cgi'), path.join(manager, 'mancgi'), { recursive: true })
  await copyFile(path.join(testsDirectory, '..', 'scripts', 'recover.php'), path.join(manager, 'paymethods', 'anore-recover.php'))
  await copyFile(path.join(testsDirectory, 'billmanager-fixture.php'), path.join(manager, 'include', 'php', 'bill_util.php'))
  await writeFile(path.join(fixture, 'openssl.cnf'), '[req]\nprompt=no\ndistinguished_name=dn\nx509_extensions=ext\n[dn]\nCN=localhost\n[ext]\nsubjectAltName=DNS:localhost,IP:127.0.0.1\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,digitalSignature,keyEncipherment,keyCertSign\n', 'utf8')
  const certificate = spawnSync(openssl, [
    'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1',
    '-keyout', path.join(fixture, 'key.pem'), '-out', path.join(fixture, 'cert.pem'), '-config', path.join(fixture, 'openssl.cnf'),
  ], { encoding: 'utf8' })
  assert.equal(certificate.status, 0, `OpenSSL is required for the isolated HTTPS fixture: ${certificate.error || certificate.stderr}`)
  apiServer = createHttpsServer({
    key: await readFile(path.join(fixture, 'key.pem')), cert: await readFile(path.join(fixture, 'cert.pem')),
  }, async (request, response) => {
    let raw = ''
    for await (const chunk of request) raw += chunk
    apiRequests.push({ path: request.url, method: request.method, headers: request.headers, raw, body: JSON.parse(raw) })
    const id = `aaaaaaaa-1111-4222-8333-${String(apiRequests.length).padStart(12, '0')}`
    response.writeHead(apiStatus, { 'Content-Type': 'application/json' })
    response.end(JSON.stringify(apiResponseOverride ?? { id, paymentUrl: `https://pay.anore.cc/${id}`, expiresIn: 14400 }))
  })
  apiServer.listen(0, '127.0.0.1')
  await once(apiServer, 'listening')
  apiOrigin = `https://127.0.0.1:${apiServer.address().port}`
  await save(baseState())
  for (const filename of ['cgi/anorepayment.php', 'cgi/anoreresult.php', 'include/php/anore_billmanager.php', 'paymethods/pmanore.php', 'paymethods/anore-recover.php']) {
    const lint = spawnSync(php, ['-l', path.join(manager, filename)], { encoding: 'utf8' })
    assert.equal(lint.status, 0, lint.stdout + lint.stderr)
  }
  server = createHttpServer(async (request, response) => {
    const url = new URL(request.url, 'http://fixture.test')
    if (!['/mancgi/anorepayment.php', '/mancgi/anoreresult.php'].includes(url.pathname)) {
      response.writeHead(404)
      response.end('Not Found')
      return
    }
    let raw = ''
    for await (const chunk of request) raw += chunk
    const filename = path.join(manager, url.pathname.slice(1))
    const child = spawn(php, ['-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'date.timezone=Asia/Tokyo',
      '-d', `curl.cainfo=${path.join(fixture, 'cert.pem')}`, filename], {
      env: { ...process.env, BILLMANAGER_FIXTURE_STATE: stateFile, NO_PROXY: '127.0.0.1,localhost',
        REQUEST_METHOD: request.method, QUERY_STRING: url.search.slice(1), REQUEST_URI: request.url,
        SCRIPT_FILENAME: filename, SCRIPT_NAME: url.pathname, SERVER_NAME: 'billing.example.test',
        SERVER_PROTOCOL: 'HTTP/1.1', SERVER_PORT: '443', HTTPS: 'on', REMOTE_ADDR: '127.0.0.1',
        HTTP_HOST: 'billing.example.test', HTTP_ANORE_SIGNATURE: request.headers['anore-signature'] || '',
        HTTP_COOKIE: request.headers.cookie || '',
        CONTENT_TYPE: request.headers['content-type'] || '', CONTENT_LENGTH: String(Buffer.byteLength(raw)),
      }, stdio: ['pipe', 'pipe', 'pipe'],
    })
    let output = ''
    child.stdout.on('data', chunk => { output += chunk })
    child.stderr.on('data', chunk => { serverOutput += chunk })
    child.stdin.end(raw)
    const [exitCode] = await once(child, 'close')
    const boundary = output.match(/\r?\n\r?\n/)
    if (!boundary) {
      response.writeHead(500, { 'Content-Type': 'text/plain' })
      response.end(`Missing CGI headers: ${output}\nexit=${exitCode}`)
      return
    }
    const headerText = output.slice(0, boundary.index)
    const headers = {}
    let status = 200
    for (const line of headerText.split(/\r?\n/)) {
      const separator = line.indexOf(':')
      if (separator < 0) continue
      const name = line.slice(0, separator).toLowerCase()
      const value = line.slice(separator + 1).trim()
      if (name === 'status') status = Number(value.split(' ')[0])
      else headers[name] = value
    }
    if (headers.location && status === 200) status = 302
    response.writeHead(status, headers)
    response.end(output.slice(boundary.index + boundary[0].length))
  })
  server.listen(0, '127.0.0.1')
  await once(server, 'listening')
  origin = `http://127.0.0.1:${server.address().port}`

  await test('signed callback runs the real CGI bootstrap and credits once', async () => {
    await expectStatus(200)
    const value = await state()
    assert.deepEqual(value.credits, [{ elid: 42 }])
    const command = value.calls.find(call => call.command === 'payment.setpaid')
    assert.equal(command.params.externalid, paymentId)
    assert.equal(command.params.sok, 'ok')
  })
  await test('repeated successful callbacks are acknowledged without another paid call', async () => {
    await expectStatus(200)
    await expectStatus(200)
    const value = await state()
    assert.equal(value.credits.length, 1)
    assert.equal(value.calls.filter(call => call.command === 'payment.setpaid').length, 1)
  })
  await test('parallel duplicate callbacks credit the invoice exactly once', async () => {
    const responses = await Promise.all([request(), request(), request()])
    assert.deepEqual(responses.map(value => value.status), [200, 200, 200], JSON.stringify(responses))
    const value = await state()
    assert.equal(value.credits.length, 1)
    assert.equal(value.calls.filter(call => call.command === 'payment.setpaid').length, 1)
  })
  await test('legacy falsely paid mapping is repaired against authoritative BILLmanager status', async () => {
    await expectStatus(200)
    await expectStatus(200)
    const value = await state()
    assert.equal(value.payment.status, '4')
    assert.equal(value.credits.length, 1)
    assert.equal(value.calls.filter(call => call.command === 'payment.setpaid').length, 1)
  }, { mapping: legacyMapping({ state: 'paid' }) })
  await test('confirmed version 2 payment cannot credit again after BILLmanager status changes', async () => {
    await expectCreated()
    const payload = success({ id: 'aaaaaaaa-1111-4222-8333-000000000001' })
    await expectStatus(200, payload)
    const value = await state()
    value.payment.status = '1'
    value.payment.externalid = ''
    await save(value)
    await expectStatus(422, payload)
    const result = await state()
    assert.equal(result.credits.length, 1)
    assert.equal(result.calls.filter(call => call.command === 'payment.setpaid').length, 1)
  }, { mapping: false })
  await test('expired then paid accepts delayed success exactly once', async () => {
    await expectStatus(200, success({ event: 'payment.expired' }))
    await expectStatus(200)
    await expectStatus(200)
    assert.equal((await state()).credits.length, 1)
  })
  await test('paid then expired preserves a completed payment', async () => {
    await expectStatus(200)
    await expectStatus(200, success({ event: 'payment.expired' }))
    const value = await state()
    assert.equal(value.payment.status, '4')
    assert.equal(value.calls.filter(call => call.command === 'payment.setnopay').length, 0)
  })
  await test('test webhook is accepted without payment state or credit', async () => {
    await expectStatus(200, { event: 'payment.test', message: 'Fixture test notification' }, { query: '?payment=42' })
    await expectNoCredit()
  }, { mapping: false })
  await test('callback query maps an optional absent order ID safely', async () => {
    const payload = success()
    delete payload.orderId
    await expectStatus(200, payload, { query: '?payment=42' })
    assert.equal((await state()).credits.length, 1)
  })
  await test('unknown signed events do not credit', async () => {
    await expectStatus(200, success({ event: 'payment.created' }))
    await expectNoCredit()
  })
  await test('GET callbacks are explicitly rejected', async () => { await expectStatus(405, '', { method: 'GET' }) })
  await test('invalid signature is rejected without credit', async () => {
    await expectStatus(401, success(), { signature: '0'.repeat(64) })
    await expectNoCredit()
  })
  await test('malformed JSON is rejected', async () => { await expectStatus(400, '{"event":') })
  await test('oversized raw request is rejected before processing', async () => {
    const empty = JSON.stringify({ event: 'payment.test', padding: '' })
    const raw = JSON.stringify({ event: 'payment.test', padding: 'x'.repeat(65537 - Buffer.byteLength(empty)) })
    assert.equal(Buffer.byteLength(raw), 65537)
    await expectStatus(400, raw, { query: '?payment=42' })
    await expectNoCredit()
  })
  for (const [field, value] of [['id', 'invalid-uuid'], ['amount', 123.44], ['currency', 'USD'], ['orderId', 'billmanager6:bbbbbbbbbbbb:42']]) {
    await test(`mismatched ${field} is rejected without credit`, async () => {
      await expectStatus(422, success({ [field]: value }))
      await expectNoCredit()
    })
  }
  await test('unknown mapping is rejected without credit', async () => {
    await expectStatus(422, success({ id: olderPaymentId }))
    await expectNoCredit()
  })
  await test('mapping changed while obtaining payment info is revalidated under the lock', async () => {
    await expectStatus(422)
    await expectNoCredit()
  }, { change: value => { value.mappingOnInfo = { amount: '999.00' } } })
  await test('a different credited transaction cannot receive a second credit', async () => {
    await expectStatus(422)
    await expectNoCredit()
    assert.equal((await state()).calls.filter(call => call.command === 'payment.setpaid').length, 0)
  }, { change: value => {
    value.payment.status = '4'
    value.payment.externalid = olderPaymentId
  } })
  await test('original USD amount is credited independently from RUB conversion', async () => {
    await expectStatus(200, success({ amount: 1.41, currency: 'USD', rubAmount: 123.45 }))
    assert.equal((await state()).credits.length, 1)
  }, { mapping: legacyMapping({ amount: '1.41', currency: 'USD' }), change: value => {
    value.payment.paymethodamount = '1.41'
    value.payment.amount = '1.41'
    value.payment.currency.iso = 'USD'
  } })
  for (const command of ['payment.info', 'payment.setpaid']) {
    for (const failure of ['throw', 'xml']) {
      await test(`${command} ${failure} failure is retryable and recovers without duplicate credit`, async () => {
        await expectStatus(503)
        await expectNoCredit()
        const value = await state()
        value.failures = {}
        await save(value)
        await expectStatus(200)
        await expectStatus(200)
        assert.equal((await state()).credits.length, 1)
      }, { change: value => { value.failures[command] = failure } })
    }
  }
  await test('expiry BILLmanager XML error is retryable', async () => {
    await expectStatus(503, success({ event: 'payment.expired' }))
    await expectNoCredit()
  }, { change: value => { value.failures['payment.setnopay'] = 'xml' } })
  await test('logging failure cannot break callback validation or success', async () => {
    await expectStatus(401, success(), { signature: '0'.repeat(64) })
    await expectStatus(200)
    await expectStatus(200)
    assert.equal((await state()).credits.length, 1)
  }, { change: value => { value.logFailure = true } })
  await test('cache write failure after credit retries without another paid operation', async () => {
    const first = await request()
    assert.equal(first.status, 503, `cache failure must be exercised: ${JSON.stringify(first)}`)
    const value = await state()
    assert.equal(value.credits.length, 1)
    const unsaved = JSON.parse(await readFile(path.join(stateDirectory, '42.json'), 'utf8'))
    assert.equal(unsaved.state, 'ready')
    value.failStateWriteAfterCredit = false
    await save(value)
    await chmod(stateDirectory, 0o750)
    await chmod(path.join(stateDirectory, '42.json'), 0o600)
    await expectStatus(200)
    const recovered = await state()
    assert.equal(recovered.credits.length, 1)
    assert.equal(recovered.calls.filter(call => call.command === 'payment.setpaid').length, 1)
  }, { change: value => { value.failStateWriteAfterCredit = true } })

  await test('creation sends current fields, canonical methods and signed exact JSON over verified HTTPS', async () => {
    await expectCreated()
    assert.equal(apiRequests.length, 1)
    const request = apiRequests[0]
    assert.equal(request.path, '/api/v1/payments')
    assert.equal(request.method, 'POST')
    assert.equal(request.headers.authorization, 'Bearer fixture-api-key')
    assert.equal(request.headers['x-zpay-signature'], createHmac('sha256', 'fixture-api-secret').update(request.raw).digest('hex'))
    assert.equal(request.body.email, 'customer@example.test')
    assert.equal(request.body.callbackUrl, 'https://billing.example.test/mancgi/anoreresult.php?payment=42')
    assert.equal(request.body.orderId, orderId)
    assert.equal(request.body.shopId, 27)
    assert.equal(request.body.amount, 123.45)
    assert.equal(request.body.currency, 'rub')
    assert.deepEqual(request.body.methods, ['sbp', 'card', 'crypto-old'])
    await expectNoCredit()
    const initialQuery = (await state()).calls.find(call => call.command === 'payment')
    assert.equal(initialQuery.auth, 'fixture-auth')
    assert.equal(initialQuery.params.id, 42)
    assert.equal(initialQuery.params.filter, 'on')
    assert.ok((await state()).calls.filter(call => call.command === 'payment.info').every(call => !call.auth))
  }, { mapping: false })
  for (const auth of ['', 'bogus-auth']) {
    await test(`creation session ${JSON.stringify(auth)} cannot create a payment`, async () => {
      assert.notEqual((await createPayment({ auth })).status, 302)
      assert.equal(apiRequests.length, 0)
      await expectNoCredit()
    }, { mapping: false })
  }
  await test('valid session cannot request another account payment or reach privileged payment info', async () => {
    assert.equal((await createPayment({ elid: '99' })).status, 403)
    assert.equal(apiRequests.length, 0)
    const value = await state()
    assert.equal(value.calls.filter(call => call.command === 'payment.info').length, 0)
    assert.equal(value.calls.filter(call => call.command === 'payment').length, 1)
    await expectNoCredit()
  }, { mapping: false })
  await test('empty authorized payment list cannot fall back to privileged payment info', async () => {
    assert.equal((await createPayment()).status, 403)
    assert.equal(apiRequests.length, 0)
    assert.equal((await state()).calls.filter(call => call.command === 'payment.info').length, 0)
    await expectNoCredit()
  }, { mapping: false, change: value => { value.accessibleIds = [] } })
  await test('cookie-only CLI session authorizes payment creation', async () => {
    const response = await createPayment({}, { cookieOnly: true, cookie: 'unrelated=1; billmgrses5=fixture-auth%3Ametadata' })
    assert.equal(response.status, 302, JSON.stringify(response))
    assert.equal(apiRequests.length, 1)
    assert.equal((await state()).calls.find(call => call.command === 'payment').auth, 'fixture-auth')
    await expectNoCredit()
  }, { mapping: false })
  await test('bogus cookie session is rejected before invoice creation', async () => {
    const response = await createPayment({}, { cookieOnly: true, cookie: 'billmgrses5=bogus-auth:metadata' })
    assert.equal(response.status, 403, JSON.stringify(response))
    assert.equal(apiRequests.length, 0)
    await expectNoCredit()
  }, { mapping: false })
  await test('unchanged creation reuses a cached URL and stores UTC expiration', async () => {
    const first = await expectCreated()
    assert.equal((await expectCreated()).location, first.location)
    assert.equal(apiRequests.length, 1)
    const attempts = (await mappings()).flatMap(allAttempts).filter(value => value.anorePaymentId.startsWith('aaaaaaaa-'))
    assert.ok(attempts.length >= 1)
    const expiry = attempts[0].expiresAt
    assert.match(expiry, /(?:\+00:00|Z)$/)
    assert.ok(Math.abs(Date.parse(expiry) - Date.now() - 14400000) < 15000, expiry)
    await expectNoCredit()
  }, { mapping: false })
  await test('parallel creation requests reuse one Anore invoice', async () => {
    const responses = await Promise.all([createPayment(), createPayment(), createPayment()])
    assert.deepEqual(responses.map(value => value.status), [302, 302, 302], JSON.stringify(responses))
    assert.equal(new Set(responses.map(value => value.location)).size, 1)
    assert.equal(apiRequests.length, 1)
    const attempts = JSON.parse(await readFile(path.join(stateDirectory, '42.json'), 'utf8')).attempts
    assert.equal(attempts.length, 1)
    await expectNoCredit()
  }, { mapping: false })
  await test('equivalent mixed-case method aliases reuse an invoice', async () => {
    let value = await state()
    value.payment.paymethod.methods = ' SBP, CARD, DVNET, xrocket, CRYPTO, crypto-old '
    await save(value)
    const first = await expectCreated()
    assert.deepEqual(apiRequests[0].body.methods, ['sbp', 'card', 'crypto', 'crypto-old'])
    value = await state()
    value.payment.paymethod.methods = 'sbp,card,crypto,crypto-old'
    await save(value)
    assert.equal((await expectCreated()).location, first.location)
    assert.equal(apiRequests.length, 1)
  }, { mapping: false })
  await test('changed shop cannot create a second live invoice', async () => {
    await expectCreated()
    const before = await readFile(path.join(stateDirectory, '42.json'), 'utf8')
    const value = await state()
    value.payment.paymethod.shop_id = '28'
    await save(value)
    assert.equal((await createPayment()).status, 409)
    assert.equal(apiRequests.length, 1)
    assert.equal(await readFile(path.join(stateDirectory, '42.json'), 'utf8'), before)
    await expectNoCredit()
  }, { mapping: false })
  await test('after expiry a fresh shop attempt preserves delayed payment of the old invoice', async () => {
    await expectCreated()
    const previous = apiRequests[0]
    const oldId = 'aaaaaaaa-1111-4222-8333-000000000001'
    await expireMappings()
    const value = await state()
    value.payment.paymethod.shop_id = '28'
    await save(value)
    await expectCreated()
    assert.equal(apiRequests.length, 2)
    await expectStatus(200, success({ id: oldId, orderId: previous.body.orderId }))
    assert.equal((await state()).credits.length, 1)
  }, { mapping: false })
  await test('old expiry after a newer UUID was credited is acknowledged without changing the credit', async () => {
    await expectCreated()
    await expireMappings()
    await expectCreated()
    const newerId = 'aaaaaaaa-1111-4222-8333-000000000002'
    await expectStatus(200, success({ id: newerId }))
    const before = await state()
    await expectStatus(200, success({ event: 'payment.expired', id: 'aaaaaaaa-1111-4222-8333-000000000001' }))
    const after = await state()
    assert.equal(after.payment.status, '4')
    assert.equal(after.payment.externalid, newerId)
    assert.deepEqual(after.credits, before.credits)
    assert.equal(after.calls.filter(call => call.command === 'payment.setnopay').length, 0)
  }, { mapping: false })
  await test('created UUID index maps a callback without optional order ID', async () => {
    await expectCreated()
    const payload = success({ id: 'aaaaaaaa-1111-4222-8333-000000000001' })
    delete payload.orderId
    await expectStatus(200, payload)
    assert.equal((await state()).credits.length, 1)
  }, { mapping: false })
  await test('changed email cannot replace a live invoice', async () => {
    await expectCreated()
    const before = await readFile(path.join(stateDirectory, '42.json'), 'utf8')
    const value = await state()
    value.payment.email = 'changed@example.test'
    value.payment.useremail = 'changed@example.test'
    value.payment.client.email = 'changed@example.test'
    await save(value)
    assert.equal((await createPayment()).status, 409)
    assert.equal(apiRequests.length, 1)
    assert.equal(await readFile(path.join(stateDirectory, '42.json'), 'utf8'), before)
  }, { mapping: false })
  await test('changed BILLmanager payment method cannot replace a live invoice', async () => {
    await expectCreated()
    const before = await readFile(path.join(stateDirectory, '42.json'), 'utf8')
    const value = await state()
    value.payment.paymethod.id = '10'
    await save(value)
    assert.equal((await createPayment()).status, 409)
    assert.equal(apiRequests.length, 1)
    assert.equal(await readFile(path.join(stateDirectory, '42.json'), 'utf8'), before)
    await expectNoCredit()
  }, { mapping: false })
  await test('expired cached URL creates a fresh attempt', async () => {
    await expectCreated()
    await expireMappings()
    await expectCreated()
    assert.equal(apiRequests.length, 2)
    await expectNoCredit()
  }, { mapping: false })
  for (const field of ['anorePaymentId', 'expiresAt']) {
    await test(`ready mapping missing ${field} cannot create a replacement invoice`, async () => {
      const value = legacyMapping()
      delete value[field]
      await seedMapping(value)
      assert.equal((await createPayment()).status, 503)
      assert.equal(apiRequests.length, 0)
      await expectNoCredit()
    }, { mapping: false })
  }
  await test('valid JSON without a mapping record cannot create a replacement invoice', async () => {
    await seedMapping('not a mapping record')
    assert.equal((await createPayment()).status, 503)
    assert.equal(apiRequests.length, 0)
    await expectNoCredit()
  }, { mapping: false })
  await test('associative attempt keys in valid JSON cannot create a replacement invoice', async () => {
    await seedMapping({ version: 2, state: 'ready', attempts: { historical: legacyMapping() } })
    assert.equal((await createPayment()).status, 503)
    assert.equal(apiRequests.length, 0)
    await expectNoCredit()
  }, { mapping: false })
  await test('paid BILLmanager payment cannot reopen a cached payment URL', async () => {
    await expectCreated()
    await expectStatus(200, success({ id: 'aaaaaaaa-1111-4222-8333-000000000001' }))
    const response = await createPayment()
    assert.notEqual(response.status, 302)
    assert.equal(apiRequests.length, 1)
    assert.equal((await state()).credits.length, 1)
  }, { mapping: false })
  for (const [field, value] of [['methods', 'sbp,invalid'], ['webhook_secret', ''], ['shop_id', '27x']]) {
    await test(`invalid ${field} fails before API creation`, async () => {
      const response = await createPayment()
      assert.notEqual(response.status, 302)
      assert.equal(apiRequests.length, 0)
      await expectNoCredit()
    }, { mapping: false, change: state => { state.payment.paymethod[field] = value } })
  }
  await test('LocalQuery creation error XML fails before API creation', async () => {
    assert.notEqual((await createPayment()).status, 302)
    assert.equal(apiRequests.length, 0)
    await expectNoCredit()
  }, { mapping: false, change: value => { value.failures['payment.info'] = 'xml' } })
  await test('unsafe API payment URL is refused', async () => {
    apiResponseOverride = { id: paymentId, paymentUrl: 'javascript:alert(1)', expiresIn: 14400 }
    assert.notEqual((await createPayment()).status, 302)
    await expectNoCredit()
  }, { mapping: false })
  await test('incomplete creation UUID fails without redirect', async () => {
    apiResponseOverride = { id: 'broken-id', paymentUrl: 'https://pay.anore.cc/broken-id', expiresIn: 14400 }
    assert.notEqual((await createPayment()).status, 302)
    await expectNoCredit()
  }, { mapping: false })
  await test('client error permits a safe creation retry', async () => {
    apiStatus = 422
    apiResponseOverride = { error: 'Fixture validation failure' }
    assert.notEqual((await createPayment()).status, 302)
    apiStatus = 201
    apiResponseOverride = undefined
    await expectCreated()
    assert.equal(apiRequests.length, 2)
  }, { mapping: false })
  await test('uncertain server error blocks creation retry', async () => {
    apiStatus = 503
    apiResponseOverride = { error: 'Fixture temporary failure' }
    assert.notEqual((await createPayment()).status, 302)
    apiStatus = 201
    apiResponseOverride = undefined
    assert.notEqual((await createPayment()).status, 302)
    assert.equal(apiRequests.length, 1)
  }, { mapping: false })
  await test('paymethod validation supplies a default API URL', async () => {
    const validation = spawnSync(php, [path.join(manager, 'paymethods', 'pmanore.php'), '--command=pmvalidate'], {
      input: '<doc><api_key>fixture-key</api_key><webhook_secret>fixture-secret</webhook_secret><methods>DVNET,xrocket</methods></doc>',
      env: { ...process.env, BILLMANAGER_FIXTURE_STATE: stateFile }, encoding: 'utf8',
    })
    assert.equal(validation.status, 0, validation.stderr)
    assert.match(validation.stdout, /https:\/\/api\.anore\.cc/)
    assert.match(validation.stdout, /crypto/)
  }, { mapping: false })
  await test('recovery refuses a known UUID and leaves history untouched', async () => {
    await expectCreated()
    const before = await readFile(path.join(stateDirectory, '42.json'), 'utf8')
    const result = recover(['--confirm-not-created'])
    assert.equal(result.status, 1)
    assert.equal(await readFile(path.join(stateDirectory, '42.json'), 'utf8'), before)
    assert.equal(apiRequests.length, 1)
  }, { mapping: false })
  if (!process.getuid || process.getuid() === 0) {
    await test('recovery requires confirmation and removes only uncertain initialization', async () => {
      await expectCreated()
      await expireMappings()
      apiStatus = 503
      apiResponseOverride = { error: 'Fixture uncertain request' }
      assert.equal((await createPayment()).status, 502)
      const before = await readFile(path.join(stateDirectory, '42.json'), 'utf8')
      const uncertain = JSON.parse(before)
      assert.equal(uncertain.attempts.length, 2)
      assert.equal(uncertain.attempts[1].state, 'initializing')
      assert.equal(recover().status, 1)
      assert.equal(await readFile(path.join(stateDirectory, '42.json'), 'utf8'), before)
      const result = recover(['--confirm-not-created'])
      assert.equal(result.status, 0, result.stderr)
      const recovered = JSON.parse(await readFile(path.join(stateDirectory, '42.json'), 'utf8'))
      assert.deepEqual(recovered.attempts, [uncertain.attempts[0]])
      assert.ok((await readdir(stateDirectory)).some(name => name.startsWith('42.json.recovery-')))
      assert.equal(JSON.parse(await readFile(path.join(stateDirectory, 'aaaaaaaa-1111-4222-8333-000000000001.invoice'), 'utf8')).paymentId, 42)
      assert.equal(apiRequests.length, 2)
      await expectNoCredit()
    }, { mapping: false })
  }
  console.log(`BILLmanager HTTP: ${passed} tests passed (${version.stdout.split('\n')[0].trim()})`)
} finally {
  if (server) await new Promise(resolve => server.close(resolve))
  if (apiServer) await new Promise(resolve => apiServer.close(resolve))
  await rm(fixture, { recursive: true, force: true })
}
