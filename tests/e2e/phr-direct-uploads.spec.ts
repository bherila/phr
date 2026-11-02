import { execFileSync } from 'node:child_process'
import { mkdirSync, mkdtempSync, readFileSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import path from 'node:path'

import { expect, type Page, type Request, type Route, test } from '@playwright/test'

import { setLiveStoreCors } from './support/liveStoreCors'

/**
 * The browser's upload code, in a real browser, on both transports. The first block runs on
 * whatever this backend is: a local installation in the default run, and one that chose a real
 * store with PHR_E2E_S3_ENDPOINT, where CORS, preflights and ETag are the store's own answers.
 * The second block is the s3 transport in the default run, with the store and the application's
 * upload routes played by routes, so CI covers this code without a store.
 */
const liveStore = process.env.PHR_E2E_S3_ENDPOINT ?? null
const STORE_ORIGIN = 'https://objects.example.test'

async function signIn(page: Page): Promise<void> {
  await page.context().clearCookies()
  await page.goto('/phr')
  await page.getByLabel('Email').fill('record-owner@example.test')
  await page.getByLabel('Password').fill('owner-password')
  await page.getByRole('button', { name: 'Authorize' }).click()
  await expect(page).toHaveURL(/\/phr\/patients/)
}

/** A patient only this test uses, opened on the Imports page. */
async function importsPageForNewPatient(page: Page): Promise<{ patientId: number, patientName: string }> {
  const patientName = `Synthetic Upload Patient ${Date.now()}`
  const token = await page.locator('meta[name="csrf-token"]').getAttribute('content')
  const created = await page.request.post('/api/phr/patients', {
    data: { display_name: patientName, relationship: 'self' },
    headers: { 'X-CSRF-TOKEN': token ?? '', 'Accept': 'application/json' },
  })
  expect(created.status()).toBe(201)
  const { patient } = await created.json() as { patient: { id: number } }
  await page.goto(`/phr/imports?patient_id=${patient.id}`)
  await expect(page.getByLabel('Import into')).toHaveValue(String(patient.id))

  return { patientId: patient.id, patientName }
}

function element(group: number, tag: number, vr: string, value: string | Uint8Array): Buffer {
  let bytes = typeof value === 'string' ? Buffer.from(value, 'latin1') : Buffer.from(value)
  if (bytes.length % 2 === 1) {
    bytes = Buffer.concat([bytes, Buffer.from(vr === 'UI' ? '\0' : ' ', 'latin1')])
  }
  const head = Buffer.alloc(6)
  head.writeUInt16LE(group, 0)
  head.writeUInt16LE(tag, 2)
  head.write(vr, 4, 'latin1')
  const length = Buffer.alloc(2)
  length.writeUInt16LE(bytes.length, 0)

  return Buffer.concat([head, length, bytes])
}

function uint16(value: number): Buffer {
  const bytes = Buffer.alloc(2)
  bytes.writeUInt16LE(value, 0)

  return bytes
}

/** A synthetic image PHR's parser accepts, unique to this run so it is never a duplicate. */
function dicomBytes(run: string, instance: number): Buffer {
  const study = `1.2.840.113619.2.55.${run}`

  return Buffer.concat([
    Buffer.alloc(128),
    Buffer.from('DICM', 'latin1'),
    element(0x0002, 0x0010, 'UI', '1.2.840.10008.1.2.1'),
    element(0x0008, 0x0016, 'UI', '1.2.840.10008.5.1.4.1.1.2'),
    element(0x0008, 0x0018, 'UI', `${study}.1.${instance}`),
    element(0x0008, 0x0020, 'DA', '20260517'),
    element(0x0008, 0x0030, 'TM', '101112'),
    element(0x0008, 0x0060, 'CS', 'CT'),
    element(0x0008, 0x1030, 'LO', 'Synthetic CT'),
    element(0x0010, 0x0010, 'PN', 'Synthetic^Patient'),
    element(0x0020, 0x000D, 'UI', study),
    element(0x0020, 0x000E, 'UI', `${study}.1`),
    element(0x0020, 0x0011, 'IS', '1'),
    element(0x0020, 0x0013, 'IS', String(instance)),
    element(0x0020, 0x0032, 'DS', '0\\0\\0'),
    element(0x0020, 0x0037, 'DS', '1\\0\\0\\0\\1\\0'),
    element(0x0020, 0x0052, 'UI', '1.2.3.4.5'),
    element(0x0028, 0x0002, 'US', uint16(1)),
    element(0x0028, 0x0004, 'CS', 'MONOCHROME2'),
    element(0x0028, 0x0010, 'US', uint16(512)),
    element(0x0028, 0x0011, 'US', uint16(512)),
    element(0x0028, 0x0030, 'DS', '0.7\\0.7'),
    element(0x0028, 0x0100, 'US', uint16(16)),
    element(0x0028, 0x0101, 'US', uint16(12)),
    element(0x0028, 0x0102, 'US', uint16(11)),
    element(0x0028, 0x0103, 'US', uint16(0)),
  ])
}

/** A folder like a burned CD: two images, and a file the page itself leaves out. */
function dicomFolder(): string {
  const root = mkdtempSync(path.join(tmpdir(), 'phr-e2e-dicom-'))
  const folder = path.join(root, 'SYNTHETIC_CT')
  mkdirSync(folder)
  const run = String(Date.now())
  writeFileSync(path.join(folder, 'IM0001'), dicomBytes(run, 1))
  writeFileSync(path.join(folder, 'IM0002'), dicomBytes(run, 2))
  writeFileSync(path.join(folder, 'README.txt'), 'Synthetic readme')

  return folder
}

/** The synthetic EHI package the PHPUnit suites use, with stored padding to reach a size. */
function syntheticEhiZip(paddingBytes: number): string {
  const zipPath = path.join(mkdtempSync(path.join(tmpdir(), 'phr-e2e-ehi-')), 'synthetic-ehi-export.zip')
  const script = 'require "vendor/autoload.php"; $p = \\Tests\\Support\\EhiClinicalPackage::standard()->build();'
    + ' if ((int) $argv[2] > 0) { $p = $p->with("EHITables/padding.bin", random_bytes((int) $argv[2])); }'
    + ' $p->writeZip($argv[1], ZipArchive::CM_STORE);'
  execFileSync('php', ['-r', script, zipPath, String(paddingBytes)])

  return zipPath
}

function isStoreRequest(request: Request, origin: string): boolean {
  return request.url().startsWith(origin) && request.method() === 'PUT'
}

test.describe('Uploads on this backend, from the Imports page', () => {
  test.beforeEach(async ({ page }) => {
    await signIn(page)
  })

  test('a DICOM folder uploads, with no extra steps for the person', async ({ page }) => {
    const { patientId } = await importsPageForNewPatient(page)
    const requests: Request[] = []
    page.on('request', (request) => requests.push(request))

    await page.getByLabel('DICOM folder').setInputFiles(dicomFolder())

    const tray = page.getByRole('region', { name: 'DICOM uploads' })
    await expect(tray.getByText('Upload complete')).toBeVisible({ timeout: 30_000 })

    const studies = await page.request.get(`/api/phr/patients/${patientId}/dicom/studies`)
    const { studies: found } = await studies.json() as { studies: unknown[] }
    expect(found).toHaveLength(1)

    const posted = requests.filter((request) => request.method() === 'POST' && new URL(request.url()).pathname.endsWith('/files'))
    if (liveStore === null) {
      expect(posted).toHaveLength(2)
      expect(requests.filter((request) => request.method() === 'PUT')).toHaveLength(0)
    } else {
      expect(posted).toHaveLength(0)
      const puts = requests.filter((request) => isStoreRequest(request, liveStore))
      expect(puts).toHaveLength(2)
      // The credential is the signature. The session cookie stays with the application.
      for (const put of puts) {
        expect(await put.headerValue('cookie')).toBeNull()
        expect(await put.headerValue('x-csrf-token')).toBeNull()
      }
    }
  })

  test('an EHI export uploads and is taken in', async ({ page }) => {
    const { patientName } = await importsPageForNewPatient(page)
    const requests: Request[] = []
    page.on('request', (request) => requests.push(request))

    // Over one part on a store, and small enough for PHP's request limit on a local chunk.
    await page.getByLabel('File to import').setInputFiles(syntheticEhiZip(liveStore === null ? 0 : 9 * 1024 * 1024))
    await expect(page.getByRole('heading', { name: 'MyChart or Epic EHI export' })).toBeVisible()
    await page.getByLabel(`This file belongs to ${patientName}`).check()
    await page.getByRole('button', { name: 'Start import' }).click()

    await expect(page.getByText('Every row accounted for')).toBeVisible({ timeout: 60_000 })

    const chunks = requests.filter((request) => new URL(request.url()).pathname.endsWith('/chunks'))
    if (liveStore === null) {
      expect(chunks.length).toBeGreaterThan(0)
      expect(requests.filter((request) => request.method() === 'PUT')).toHaveLength(0)
    } else {
      expect(chunks).toHaveLength(0)
      expect(requests.filter((request) => isStoreRequest(request, liveStore))).toHaveLength(2)
    }
  })
})

test.describe('Finishing a direct upload whose last request failed, against a real store', () => {
  test.skip(liveStore === null, 'Only a store has parts to finish from.')

  test.beforeEach(async ({ page }) => {
    await signIn(page)
  })

  for (const reload of [false, true]) {
    test(`every part arrived and the finish failed: finishing needs no new upload${reload ? ', even after a reload' : ''}`, async ({ page }) => {
      const { patientName } = await importsPageForNewPatient(page)
      const puts: Request[] = []
      page.on('request', (request) => {
        if (isStoreRequest(request, liveStore ?? '')) puts.push(request)
      })
      await page.route('**/ehi/uploads/*/intake', (route) => route.abort('failed'))

      await page.getByLabel('File to import').setInputFiles(syntheticEhiZip(9 * 1024 * 1024))
      await page.getByLabel(`This file belongs to ${patientName}`).check()
      await page.getByRole('button', { name: 'Start import' }).click()

      const finish = page.getByRole('button', { name: 'Start the import' })
      await expect(finish).toBeVisible({ timeout: 60_000 })
      expect(puts).toHaveLength(2)
      await page.unroute('**/ehi/uploads/*/intake')

      if (reload) {
        await page.reload()
        await page.getByRole('row', { name: /Upload not finished/ }).getByRole('button', { name: 'View' }).click()
        await expect(finish).toBeVisible()
      }
      const intake = page.waitForRequest((request) => request.url().endsWith('/intake') && request.method() === 'POST')
      await finish.click()
      expect((await intake).postDataJSON()).toEqual({})

      await expect(page.getByText('Every row accounted for')).toBeVisible({ timeout: 60_000 })
      expect(puts, 'nothing was sent again').toHaveLength(2)
    })
  }
})

test.describe('A bucket with the wrong CORS rule, in a real browser against a real store', () => {
  test.skip(liveStore === null, 'Only a real store answers with its own CORS rules.')
  test.describe.configure({ mode: 'serial' })

  test.beforeEach(async ({ page }) => {
    await signIn(page)
  })

  test.afterAll(() => {
    setLiveStoreCors('documented')
  })

  test('no rule: the browser refuses the PUT, and the job says where to look', async ({ page }) => {
    setLiveStoreCors('none')
    await importsPageForNewPatient(page)

    await page.getByLabel('DICOM folder').setInputFiles(dicomFolder())

    const tray = page.getByRole('region', { name: 'DICOM uploads' })
    await tray.getByRole('button', { name: /^Upload failed/ }).click({ timeout: 30_000 })
    await expect(page.getByRole('dialog').getByText(/CORS rules allow this site/).first()).toBeVisible()
  })

  test('a rule that does not expose ETag: the part is sent, its ETag can’t be read, and the upload says so', async ({ page }) => {
    setLiveStoreCors('hides-etag')
    const { patientName } = await importsPageForNewPatient(page)

    await page.getByLabel('File to import').setInputFiles(syntheticEhiZip(0))
    await page.getByLabel(`This file belongs to ${patientName}`).check()
    await page.getByRole('button', { name: 'Start import' }).click()

    await expect(page.getByText(/CORS rules must expose ETag/)).toBeVisible({ timeout: 30_000 })
  })
})

test.describe('The s3 transport, in a browser, with the store played by routes', () => {
  test.skip(liveStore !== null, 'A real store is answering in this run.')

  interface Stored {
    url: string
    headers: Record<string, string>
    bytes: number
  }

  /** The store: real cross-origin requests from the page, answered per its CORS rules. */
  async function playStore(page: Page, options: { exposeEtag?: boolean } = {}): Promise<Stored[]> {
    const received: Stored[] = []
    const origin = new URL(page.url()).origin
    const cors = {
      'Access-Control-Allow-Origin': origin,
      'Access-Control-Allow-Methods': 'PUT',
      'Access-Control-Allow-Headers': 'content-type',
      ...(options.exposeEtag === false ? {} : { 'Access-Control-Expose-Headers': 'ETag' }),
    }
    await page.route(`${STORE_ORIGIN}/**`, async (route: Route) => {
      const request = route.request()
      if (request.method() === 'OPTIONS') {
        await route.fulfill({ status: 200, headers: cors })
        return
      }
      // A route can't see a body that is a whole File, only a slice of one. A live run checks those.
      received.push({ url: request.url(), headers: await request.allHeaders(), bytes: request.postDataBuffer()?.length ?? 0 })
      await route.fulfill({ status: 200, headers: { ...cors, ETag: `"synthetic-${received.length}"` } })
    })

    return received
  }

  async function fulfillJson(route: Route, body: unknown, status = 200): Promise<void> {
    await route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) })
  }

  function dicomUpload(patientId: number, status: string): Record<string, unknown> {
    return {
      id: 501, patient_id: patientId, uploaded_by_user_id: 2, status, original_root_name: 'SYNTHETIC_CT', total_files: 2, stored_files: 2, skipped_files: 0,
      total_bytes: 2, stored_bytes: 2, manifest_json: null, skipped_files_json: [], error_message: null, created_at: null, updated_at: null,
    }
  }

  /** The application's upload routes, answering as they do on a store. */
  async function playDicomApi(page: Page, patientId: number): Promise<{ authorized: unknown[], confirmed: unknown[], finalized: () => boolean, cancelled: () => boolean }> {
    const base = `/api/phr/patients/${patientId}/dicom/uploads`
    const authorized: unknown[] = []
    const confirmed: unknown[] = []
    let finalized = false
    let cancelled = false
    await page.route(`**${base}`, (route) => fulfillJson(route, {
      upload: dicomUpload(patientId, 'pending'),
      transport: 's3',
      limits: { max_file_bytes: 1_073_741_824, max_file_size_label: '1 GB' },
    }, 201))
    await page.route(`**${base}/501/files/authorize`, async (route) => {
      const body = route.request().postDataJSON() as { relative_path: string }
      authorized.push(body)
      await fulfillJson(route, { authorization: {
        token: `token-${authorized.length}`,
        url: `${STORE_ORIGIN}/bucket/incoming-${authorized.length}?X-Amz-Signature=synthetic`,
        headers: { 'Content-Type': 'application/dicom' },
      } })
    })
    await page.route(`**${base}/501/files/confirm`, async (route) => {
      confirmed.push(route.request().postDataJSON())
      await fulfillJson(route, {
        result: { stored: true, skipped_reason: null, relative_path: `SYNTHETIC_CT/IM000${confirmed.length}`, study_id: 7001 },
        upload: dicomUpload(patientId, 'pending'),
      })
    })
    await page.route(`**${base}/501/finalize`, async (route) => {
      finalized = true
      await fulfillJson(route, { upload: dicomUpload(patientId, 'processed') })
    })
    await page.route(`**${base}/501/cancel`, async (route) => {
      cancelled = true
      await fulfillJson(route, { upload: dicomUpload(patientId, 'failed') })
    })

    return { authorized, confirmed, finalized: () => finalized, cancelled: () => cancelled }
  }

  function ehiUpload(patientId: number, overrides: Record<string, unknown>): Record<string, unknown> {
    return {
      id: 601, patient_id: patientId, status: 'uploading', failure_reason: null, format: null, transport: 's3', file_size_bytes: 0, uploaded_bytes: 0,
      chunk_size_bytes: 8_388_608, part_size_bytes: 4096, package_id: null, replayed: null, resumed: null, repaired: null, import_result: null,
      expires_at: '2030-01-01T00:00:00+00:00', completed_at: null, created_at: '2030-01-01T00:00:00+00:00', ...overrides,
    }
  }

  async function playEhiApi(page: Page, patientId: number, options: { failedIntakes?: number } = {}): Promise<{ signed: number[], intake: unknown[] }> {
    const base = `/api/phr/patients/${patientId}/ehi/uploads`
    const signed: number[] = []
    const intake: unknown[] = []
    let size = 0
    let failures = options.failedIntakes ?? 0
    let queued = false
    /** What the server answers: while the upload is open, it says what the store holds. */
    const current = (): Record<string, unknown> => ehiUpload(patientId, { status: queued ? 'queued' : 'uploading', file_size_bytes: size, uploaded_bytes: size })
    await page.route(`**${base}`, async (route) => {
      if (route.request().method() !== 'POST') {
        await fulfillJson(route, { uploads: size > 0 ? [current()] : [] })
        return
      }
      size = (route.request().postDataJSON() as { file_size_bytes: number }).file_size_bytes
      await fulfillJson(route, { upload: ehiUpload(patientId, { file_size_bytes: size }) }, 201)
    })
    await page.route(`**${base}/601/parts`, async (route) => {
      const partNumber = (route.request().postDataJSON() as { part_number: number }).part_number
      signed.push(partNumber)
      await fulfillJson(route, { part: { part_number: partNumber, byte_size: 4096, url: `${STORE_ORIGIN}/bucket/package.zip?partNumber=${partNumber}&uploadId=synthetic`, headers: {} } })
    })
    await page.route(`**${base}/601/intake`, async (route) => {
      intake.push(route.request().postDataJSON())
      if (failures > 0) {
        failures -= 1
        await route.abort('failed')
        return
      }
      queued = true
      await fulfillJson(route, { upload: current() }, 202)
    })
    await page.route(`**${base}/601`, (route) => fulfillJson(route, { upload: current() }))

    return { signed, intake }
  }

  test.beforeEach(async ({ page }) => {
    await signIn(page)
  })

  test('each DICOM file is PUT to the store as signed, then recorded by the application', async ({ page }) => {
    const { patientId } = await importsPageForNewPatient(page)
    const received = await playStore(page)
    const api = await playDicomApi(page, patientId)

    await page.getByLabel('DICOM folder').setInputFiles(dicomFolder())

    await expect(page.getByRole('region', { name: 'DICOM uploads' }).getByText('Upload complete')).toBeVisible()
    expect(api.authorized).toHaveLength(2)
    expect(received).toHaveLength(2)
    for (const put of received) {
      expect(put.headers['content-type']).toBe('application/dicom')
      expect(put.headers.cookie).toBeUndefined()
      expect(put.headers['x-csrf-token']).toBeUndefined()
    }
    expect(api.confirmed).toHaveLength(2)
    expect(api.finalized()).toBe(true)
  })

  test('when the store can’t be reached, the job fails and says where to look', async ({ page }) => {
    const { patientId } = await importsPageForNewPatient(page)
    // What a page sees of a CORS refusal too. Routes can't produce a real one: Playwright
    // adds the header itself. The live run has a real one.
    await page.route(`${STORE_ORIGIN}/**`, (route) => route.abort('failed'))
    const api = await playDicomApi(page, patientId)

    await page.getByLabel('DICOM folder').setInputFiles(dicomFolder())

    const tray = page.getByRole('region', { name: 'DICOM uploads' })
    await tray.getByRole('button', { name: /^Upload failed/ }).click()
    await expect(page.getByRole('dialog').getByText(/CORS rules allow this site/).first()).toBeVisible()
    await expect.poll(() => api.cancelled()).toBe(true)
    expect(api.finalized()).toBe(false)
    expect(api.confirmed).toHaveLength(0)
  })

  test('an EHI export is PUT part by part, and finished with the ETags the page read', async ({ page }) => {
    const { patientId, patientName } = await importsPageForNewPatient(page)
    const received = await playStore(page)
    const api = await playEhiApi(page, patientId)
    const zip = syntheticEhiZip(0)
    const size = readFileSync(zip).length
    const partCount = Math.ceil(size / 4096)

    await page.getByLabel('File to import').setInputFiles(zip)
    await page.getByLabel(`This file belongs to ${patientName}`).check()
    await page.getByRole('button', { name: 'Start import' }).click()

    await expect.poll(() => api.intake.length).toBe(1)
    expect(api.signed).toEqual(Array.from({ length: partCount }, (_, index) => index + 1))
    expect(received.reduce((total, put) => total + put.bytes, 0)).toBe(size)
    for (const put of received) {
      expect(put.url).toContain('uploadId=synthetic')
      expect(put.headers.cookie).toBeUndefined()
    }
    expect(api.intake[0]).toEqual({
      parts: received.map((_, index) => ({ part_number: index + 1, etag: `"synthetic-${index + 1}"` })),
    })
  })

  test('when the finish fails after every part arrived, it can be retried with nothing sent again', async ({ page }) => {
    const { patientId, patientName } = await importsPageForNewPatient(page)
    const received = await playStore(page)
    const api = await playEhiApi(page, patientId, { failedIntakes: 1 })

    await page.getByLabel('File to import').setInputFiles(syntheticEhiZip(0))
    await page.getByLabel(`This file belongs to ${patientName}`).check()
    await page.getByRole('button', { name: 'Start import' }).click()

    const finish = page.getByRole('button', { name: 'Start the import' })
    await expect(finish).toBeVisible()
    const sent = received.length
    expect(api.intake).toHaveLength(1)
    expect((api.intake[0] as { parts: unknown[] }).parts).toHaveLength(sent)

    await finish.click()

    await expect.poll(() => api.intake.length).toBe(2)
    expect(api.intake[1]).toEqual({})
    expect(received, 'nothing was sent again').toHaveLength(sent)
    await expect(finish).toBeHidden()
  })

  test('after a reload, the upload can still be finished from Recent imports', async ({ page }) => {
    const { patientId, patientName } = await importsPageForNewPatient(page)
    const received = await playStore(page)
    const api = await playEhiApi(page, patientId, { failedIntakes: 1 })

    await page.getByLabel('File to import').setInputFiles(syntheticEhiZip(0))
    await page.getByLabel(`This file belongs to ${patientName}`).check()
    await page.getByRole('button', { name: 'Start import' }).click()
    await expect(page.getByRole('button', { name: 'Start the import' })).toBeVisible()
    const sent = received.length

    await page.reload()
    await page.getByRole('row', { name: /Upload not finished/ }).getByRole('button', { name: 'View' }).click()
    await page.getByRole('button', { name: 'Start the import' }).click()

    await expect.poll(() => api.intake.length).toBe(2)
    expect(api.intake[1]).toEqual({})
    expect(received, 'nothing was sent again').toHaveLength(sent)
  })

  test('when the bucket hides ETag, the upload stops at once and says so', async ({ page }) => {
    const { patientId, patientName } = await importsPageForNewPatient(page)
    const received = await playStore(page, { exposeEtag: false })
    const api = await playEhiApi(page, patientId)

    await page.getByLabel('File to import').setInputFiles(syntheticEhiZip(0))
    await page.getByLabel(`This file belongs to ${patientName}`).check()
    await page.getByRole('button', { name: 'Start import' }).click()

    await expect(page.getByText(/CORS rules must expose ETag/)).toBeVisible()
    expect(received).toHaveLength(1)
    expect(api.intake).toHaveLength(0)
  })
})
