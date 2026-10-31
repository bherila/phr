import { execFileSync } from 'node:child_process'
import { mkdtempSync } from 'node:fs'
import { tmpdir } from 'node:os'
import path from 'node:path'

import { expect, type Page, test } from '@playwright/test'

const ownerCredentials = {
  email: 'record-owner@example.test',
  password: 'owner-password',
}
const outsiderCredentials = {
  email: 'unrelated-user@example.test',
  password: 'outsider-password',
}
// Playwright reruns a whole serial group on retry, but global setup — and the
// synthetic database it recreates — runs once per invocation. Namespacing the
// patient by attempt keeps a retry from tripping over rows the failed attempt
// already wrote.
function patientNameForAttempt(retry: number): string {
  return `Synthetic Playwright Patient ${retry}`
}

async function signIn(page: Page, credentials: typeof ownerCredentials): Promise<void> {
  await page.context().clearCookies()
  await page.goto('/phr')
  // The identity provider is the only sign-in method, so being sent to sign in
  // forwards straight to it.
  await expect(page.getByRole('heading', { name: 'Synthetic identity provider' })).toBeVisible()
  await page.getByLabel('Email').fill(credentials.email)
  await page.getByLabel('Password').fill(credentials.password)
  await page.getByRole('button', { name: 'Authorize' }).click()
  await expect(page).toHaveURL(/\/phr\/patients/)
}

async function ownerPatientId(page: Page, patientName: string): Promise<number> {
  const response = await page.request.get('/api/phr/patients')
  expect(response.ok()).toBeTruthy()
  const payload = await response.json() as { patients: Array<{ id: number, display_name: string | null }> }
  const patient = payload.patients.find((candidate) => candidate.display_name === patientName)
  expect(patient).toBeDefined()
  return patient!.id
}

/** The synthetic C-CDA document the PHPUnit suites use, as a file on disk. */
function syntheticCcda(): string {
  const xmlPath = path.join(mkdtempSync(path.join(tmpdir(), 'phr-e2e-ccda-')), 'synthetic-visit-summary.xml')
  execFileSync('php', ['-r', 'require "vendor/autoload.php"; file_put_contents($argv[1], \\Tests\\Support\\PhrCcdaSyntheticDocument::xml());', xmlPath], { stdio: 'inherit' })
  return xmlPath
}

/** The synthetic clinical EHI package the PHPUnit suites use, as a ZIP on disk. */
function syntheticEhiZip(): string {
  const zipPath = path.join(mkdtempSync(path.join(tmpdir(), 'phr-e2e-ehi-')), 'synthetic-ehi-export.zip')
  execFileSync('php', ['-r', 'require "vendor/autoload.php"; \\Tests\\Support\\EhiClinicalPackage::standard()->build()->writeZip($argv[1]);', zipPath], { stdio: 'inherit' })
  return zipPath
}

interface ConnectedApp {
  client_id: string
  name: string
  resources: Array<{ resource: string, scopes: string[], granted_at: string }>
}

async function connectedApps(page: Page): Promise<ConnectedApp[]> {
  const response = await page.request.get('/api/user/connected-apps')
  expect(response.status()).toBe(200)
  const payload = await response.json() as { data: ConnectedApp[] }
  return payload.data
}

function recordPatientListRequests(page: Page): string[] {
  const requests: string[] = []
  page.on('request', (request) => {
    if (request.method() === 'GET' && new URL(request.url()).pathname === '/api/phr/patients') {
      requests.push(request.url())
    }
  })
  return requests
}

test.describe.serial('PHR browser journeys', () => {
  test('requires authentication for PHR and OHIF routes, then completes OAuth PKCE login', async ({ page }) => {
    // A bare visit keeps the sign-in page.
    await page.goto('/login')
    await expect(page.getByRole('link', { name: 'Sign in' })).toBeVisible()

    await page.goto('/phr')
    await expect(page.getByRole('heading', { name: 'Synthetic identity provider' })).toBeVisible()

    await page.context().clearCookies()
    await page.goto('/ohif/viewer/dicomjson')
    await expect(page.getByRole('heading', { name: 'Synthetic identity provider' })).toBeVisible()

    await signIn(page, ownerCredentials)
    await expect(page.getByRole('heading', { name: 'Patients' })).toBeVisible()
  })

  test('lets the owner create and open a patient profile through the UI', async ({ page }, testInfo) => {
    const patientName = patientNameForAttempt(testInfo.retry)
    await signIn(page, ownerCredentials)
    await page.getByRole('link', { name: 'Add Patient' }).first().click()
    await expect(page.getByRole('heading', { name: 'Manage Patients' })).toBeVisible()

    await page.getByLabel('Name *').fill(patientName)
    await page.getByLabel('Relationship').fill('self')
    await page.getByRole('button', { name: 'Add Profile' }).click()
    // The shared list updates the still-mounted Patients column as well.
    const managePage = page.getByRole('heading', { name: 'Manage Patients', exact: true }).locator('..').locator('..')
    await expect(managePage.getByText(patientName, { exact: true })).toBeVisible()

    await page.goto('/phr/patients')
    await page.getByRole('link', { name: new RegExp(patientName) }).click()
    // A patient opens on their Summary, with the record sections in the sidebar.
    const sidebar = page.getByRole('complementary', { name: 'Patient record' })
    await expect(sidebar.getByRole('link', { name: 'Summary', exact: true })).toHaveAttribute('aria-current', 'page')
    await expect(page.getByRole('heading', { name: patientName })).toBeVisible()
  })

  test('moves through the Conditions table from the keyboard and returns focus from the panel', async ({ page }, testInfo) => {
    await signIn(page, ownerCredentials)
    const patientId = await ownerPatientId(page, patientNameForAttempt(testInfo.retry))
    await page.goto(`/phr/patient/${patientId}/conditions`)
    const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content')
    for (const name of ['Synthetic keyboard condition A', 'Synthetic keyboard condition B']) {
      const created = await page.request.post(`/api/phr/patients/${patientId}/conditions`, {
        data: { name, clinical_status: 'active', verification_status: 'confirmed' },
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf ?? '' },
      })
      expect(created.status()).toBe(201)
    }
    await page.reload()

    const table = page.getByRole('table', { name: 'Active Problems' })
    const rows = table.locator('tbody tr[data-record-row]')
    await rows.first().focus()
    await page.keyboard.press('ArrowDown')
    const second = rows.nth(1)
    await expect(second).toBeFocused()
    await expect(second).toHaveAttribute('aria-selected', 'true')
    const recordId = await second.getAttribute('data-record-row')

    await page.keyboard.press('Enter')
    await expect(page).toHaveURL(new RegExp(`/phr/patient/${patientId}/conditions/${recordId}$`))
    await expect(page.getByRole('complementary', { name: 'Condition detail' })).toBeVisible()
    await expect(second).toBeFocused()

    await page.keyboard.press('Escape')
    await expect(page).toHaveURL(new RegExp(`/phr/patient/${patientId}/conditions$`))
    await expect(page.getByRole('complementary', { name: 'Condition detail' })).toHaveCount(0)
    await expect(second).toBeFocused()
  })

  test('loads the Conditions table without shifting the layout', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 })
    await signIn(page, ownerCredentials)
    const patientId = await ownerPatientId(page, 'Synthetic Chart Patient')
    await page.addInitScript(() => {
      const scope = window as unknown as { layoutShift: number }
      scope.layoutShift = 0
      new PerformanceObserver((list) => {
        for (const entry of list.getEntries() as unknown as { value: number, hadRecentInput: boolean }[]) {
          if (!entry.hadRecentInput) scope.layoutShift += entry.value
        }
      }).observe({ type: 'layout-shift', buffered: true })
    })
    // Hold the records back so the skeleton is on screen first.
    let release: () => void = () => undefined
    const held = new Promise<void>((resolve) => { release = resolve })
    await page.route(`**/api/phr/patients/${patientId}/conditions`, async (route) => {
      await held
      await route.continue()
    })

    await page.goto(`/phr/patient/${patientId}/conditions`)
    const table = page.getByRole('table', { name: 'Active Problems' })
    await expect(table.locator('tr[data-skeleton-row]').first()).toBeVisible()
    release()
    await expect(table.getByText('Essential (primary) hypertension')).toBeVisible()
    await page.waitForTimeout(500)

    const shift = await page.evaluate(() => (window as unknown as { layoutShift: number }).layoutShift)
    expect(shift).toBeLessThan(0.02)
  })

  test('uploads and views a synthetic document', async ({ page }, testInfo) => {
    await signIn(page, ownerCredentials)
    const patientId = await ownerPatientId(page, patientNameForAttempt(testInfo.retry))
    // A bookmark from the old column shell still lands on the page it named.
    await page.goto(`/phr/patient/${patientId}#/documents`)
    await expect(page).toHaveURL(new RegExp(`/phr/patient/${patientId}/documents$`))
    await expect(page.getByRole('heading', { name: 'Documents' })).toBeVisible()

    const uploadForm = page.getByRole('heading', { name: 'Upload' }).locator('..')
    await uploadForm.locator('input[type="file"]').setInputFiles({
      name: 'synthetic-note.txt',
      mimeType: 'text/plain',
      buffer: Buffer.from('Synthetic E2E document content. No personal data.'),
    })
    await uploadForm.getByLabel('Title').fill('Synthetic Visit Note')
    await uploadForm.getByRole('button', { name: 'Upload' }).click()

    const documentCard = page.getByRole('button', { name: /Synthetic Visit Note/ })
    await expect(documentCard).toBeVisible()
    await documentCard.click()
    // The viewer opens full-width as its own page under the sidebar.
    await expect(page).toHaveURL(new RegExp(`/phr/patient/${patientId}/documents/\\d+$`))
    await expect(page.getByRole('heading', { name: 'Synthetic Visit Note' }).last()).toBeVisible()
    await expect(page.getByRole('link', { name: 'Download' })).toBeVisible()
    await expect(page.getByTitle('Document viewer').last()).toBeVisible()
    await page.goBack()
    await expect(page).toHaveURL(new RegExp(`/phr/patient/${patientId}/documents$`))
    await expect(documentCard).toBeVisible()
  })

  test('generates an export synchronously and exposes a working download', async ({ page }, testInfo) => {
    const patientName = patientNameForAttempt(testInfo.retry)
    await signIn(page, ownerCredentials)
    const patientId = await ownerPatientId(page, patientName)
    await page.goto(`/phr/patient/${patientId}`)
    await expect(page.getByRole('heading', { name: patientName })).toBeVisible()

    await page.getByRole('button', { name: 'Generate' }).click()
    await expect(page.getByText('ready')).toBeVisible()
    const downloadLink = page.getByRole('link', { name: 'Download' })
    await expect(downloadLink).toBeVisible()

    const downloadPromise = page.waitForEvent('download')
    await downloadLink.click()
    const download = await downloadPromise
    expect(download.suggestedFilename()).toMatch(/^patient-\d+-\d{8}\.zip$/)
  })

  test('denies an unrelated user access to patient and DICOM resources', async ({ page }, testInfo) => {
    await signIn(page, ownerCredentials)
    const patientId = await ownerPatientId(page, patientNameForAttempt(testInfo.retry))

    const ownerDicom = await page.request.get(`/api/phr/patients/${patientId}/dicom/studies`)
    expect(ownerDicom.status()).toBe(200)

    await signIn(page, outsiderCredentials)
    const patientPage = await page.goto(`/phr/patient/${patientId}`)
    expect(patientPage?.status()).toBe(404)

    const patientApi = await page.request.get(`/api/phr/patients/${patientId}`)
    expect(patientApi.status()).toBe(404)
    const dicomApi = await page.request.get(`/api/phr/patients/${patientId}/dicom/studies`)
    expect(dicomApi.status()).toBe(404)

    const ohif = await page.goto('/ohif/viewer/dicomjson')
    expect(ohif?.status()).toBe(404)
    await expect(page).not.toHaveURL(/\/login$/)
  })

  test('shares bootstrapped patients across navigation, creation and renaming without redundant list requests', async ({ page }, testInfo) => {
    test.setTimeout(60_000)
    const patientName = `Synthetic Shared List Patient ${testInfo.retry}`
    const renamed = `${patientName} Renamed`
    const patientRequests = recordPatientListRequests(page)
    await page.addInitScript(() => {
      const placeholders: string[] = []
      Object.defineProperty(window, '__patientListPlaceholders', { value: placeholders })
      new MutationObserver(() => {
        for (const heading of document.querySelectorAll('h1')) {
          if (heading.textContent !== 'Patients') continue
          const patientsPage = heading.parentElement?.parentElement?.parentElement
          const text = patientsPage?.innerText ?? ''
          if (/No patient profiles yet|Loading…/.test(text)) placeholders.push(text)
        }
      }).observe(document, { childList: true, subtree: true, characterData: true })
    })

    await signIn(page, ownerCredentials)
    await expect(page.getByRole('link', { name: /Synthetic Bootstrap Patient/ })).toBeVisible()
    expect(patientRequests).toHaveLength(0)
    await page.getByRole('link', { name: /Synthetic Bootstrap Patient/ }).click()
    await expect(page.getByRole('button', { name: 'Switch patient: Synthetic Bootstrap Patient' })).toBeVisible()

    await page.getByRole('link', { name: 'Manage Patients', exact: true }).click()
    await expect(page.getByRole('heading', { name: 'Manage Patients', exact: true })).toBeVisible()
    await page.getByLabel('Name *').fill(patientName)
    await page.getByLabel('Relationship').fill('self')
    await page.getByRole('button', { name: 'Add Profile', exact: true }).click()
    await expect(page.getByText(patientName, { exact: true })).toBeVisible()

    await page.getByRole('link', { name: 'Patients', exact: true }).click()
    await page.getByRole('link', { name: new RegExp(patientName) }).click()
    await expect(page.getByRole('button', { name: `Switch patient: ${patientName}` })).toBeVisible()
    const patientId = new URL(page.url()).pathname.match(/\/phr\/patient\/(\d+)/)?.[1]
    expect(patientId).toBeDefined()

    await page.getByRole('link', { name: 'Manage Patients', exact: true }).click()
    const profileCard = page.getByText(patientName, { exact: true }).locator('..').locator('..').locator('..')
    await profileCard.getByTitle('Edit', { exact: true }).click()
    const editForm = page.getByRole('heading', { name: 'Edit Profile', exact: true }).locator('..')
    await editForm.getByLabel('Name *').fill(renamed)
    await editForm.getByRole('button', { name: 'Save', exact: true }).click()
    await expect(page.getByText(renamed, { exact: true })).toBeVisible()

    await page.getByRole('link', { name: 'Patients', exact: true }).click()
    await expect(page.getByRole('link', { name: new RegExp(renamed) })).toBeVisible()
    await expect(page.getByText(patientName, { exact: true })).toHaveCount(0)
    await page.getByRole('link', { name: new RegExp(renamed) }).click()
    await expect(page.getByRole('button', { name: `Switch patient: ${renamed}` })).toBeVisible()
    await expect(page).toHaveURL(new RegExp(`/phr/patient/${patientId}$`))

    await page.getByRole('link', { name: 'Imports', exact: true }).click()
    const importPatient = page.getByRole('combobox', { name: 'Import into', exact: true })
    await expect(importPatient.getByRole('option', { name: renamed, exact: true })).toHaveAttribute('value', patientId!)
    await expect(importPatient.getByRole('option', { name: patientName, exact: true })).toHaveCount(0)
    await importPatient.selectOption(patientId!)
    await expect(importPatient).toHaveValue(patientId!)
    expect(patientRequests).toHaveLength(0)
    const placeholders = await page.evaluate(() => (
      window as unknown as { __patientListPlaceholders: string[] }
    ).__patientListPlaceholders)
    expect(placeholders).toEqual([])
  })

  test('shares one patient-list fallback request when shell bootstrap data is absent', async ({ page }) => {
    test.setTimeout(60_000)
    await signIn(page, ownerCredentials)
    const patientRequests = recordPatientListRequests(page)
    await page.route((url) => url.pathname === '/phr/patients', async (route) => {
      if (route.request().resourceType() !== 'document') {
        await route.continue()
        return
      }
      const response = await route.fetch()
      const html = await response.text()
      const script = /(<script\b[^>]*\bid="app-initial-data"[^>]*>)([\s\S]*?)(<\/script>)/
      const body = html.replace(script, (_match, opening: string, json: string, closing: string) => {
        const initial = JSON.parse(json) as { phr?: { patient_list?: unknown } }
        expect(initial.phr).toHaveProperty('patient_list')
        delete initial.phr?.patient_list
        return `${opening}${JSON.stringify(initial)}${closing}`
      })
      expect(body).not.toBe(html)
      await route.fulfill({ response, body })
    })

    await page.goto('/phr/patients')
    await expect(page.getByRole('link', { name: /Synthetic Bootstrap Patient/ })).toBeVisible()
    expect(patientRequests).toHaveLength(1)
    await page.getByRole('link', { name: 'Manage Patients', exact: true }).click()
    await expect(page.getByRole('heading', { name: 'Manage Patients', exact: true })).toBeVisible()
    await page.getByRole('link', { name: 'Imports', exact: true }).click()
    await expect(page.getByRole('combobox', { name: 'Import into', exact: true }).getByRole('option', { name: 'Synthetic Bootstrap Patient', exact: true })).toBeAttached()
    expect(patientRequests).toHaveLength(1)
  })

  test('reviews and withdraws only the signed-in person’s consent for a connected app', async ({ page }, testInfo) => {
    // This journey signs in as both people and checks multiple persisted API
    // transitions, so allow more time than a single-page interaction.
    test.setTimeout(60_000)
    const appName = `Synthetic Connected App ${testInfo.retry}`
    await signIn(page, ownerCredentials)
    await page.goto('/phr/config')
    await page.getByRole('tab', { name: 'Connected apps', exact: true }).click()
    await expect(page.getByRole('heading', { name: 'Connected apps', exact: true })).toBeVisible()

    const target = page.getByRole('article').filter({ has: page.getByRole('heading', { name: appName, exact: true }) })
    await expect(target).toBeVisible()
    await expect(target.getByText('http://127.0.0.1:4173/api/v1', { exact: true })).toBeVisible()
    await expect(target.getByText('http://127.0.0.1:4173/api/v1/mcp', { exact: true })).toBeVisible()
    await expect(target.getByText('identity:read', { exact: true })).toBeVisible()
    await expect(target.getByText('mcp:use', { exact: true })).toBeVisible()
    await expect(page.getByRole('heading', { name: 'Synthetic Other Connected App', exact: true })).toBeVisible()
    for (const hiddenName of ['Synthetic Outsider Only App', 'Synthetic Previously Revoked App', 'Synthetic Token Only App']) {
      await expect(page.getByRole('heading', { name: hiddenName, exact: true })).toHaveCount(0)
    }

    const appsBefore = await connectedApps(page)
    const selected = appsBefore.find((app) => app.name === appName)
    expect(selected).toBeDefined()
    expect(selected!.resources).toEqual(expect.arrayContaining([
      expect.objectContaining({ resource: 'http://127.0.0.1:4173/api/v1', scopes: ['identity:read'] }),
      expect.objectContaining({ resource: 'http://127.0.0.1:4173/api/v1/mcp', scopes: ['mcp:use'] }),
    ]))

    // Cookies alone must not authorize the mutation: this request deliberately
    // omits the CSRF header that the real browser UI sends.
    const withoutCsrf = await page.request.delete(`/api/user/connected-apps/${selected!.client_id}`)
    expect(withoutCsrf.status()).toBe(419)
    expect((await connectedApps(page)).some((app) => app.client_id === selected!.client_id)).toBeTruthy()

    await target.getByRole('button', { name: 'Revoke access', exact: true }).click()
    const dialog = page.getByRole('alertdialog', { name: `Revoke access for ${appName}?` })
    await expect(dialog).toBeVisible()
    await dialog.getByRole('button', { name: 'Cancel', exact: true }).click()
    await expect(dialog).toHaveCount(0)
    await expect(target).toBeVisible()
    expect((await connectedApps(page)).some((app) => app.client_id === selected!.client_id)).toBeTruthy()

    await target.getByRole('button', { name: 'Revoke access', exact: true }).click()
    await dialog.getByRole('button', { name: 'Revoke access', exact: true }).click()
    await expect(target).toHaveCount(0)
    await page.reload()
    await page.getByRole('tab', { name: 'Connected apps', exact: true }).click()
    await expect(page.getByRole('heading', { name: 'Connected apps', exact: true })).toBeVisible()
    await expect(page.getByRole('heading', { name: appName, exact: true })).toHaveCount(0)
    const appsAfter = await connectedApps(page)
    expect(appsAfter).toEqual(appsBefore.filter((app) => app.client_id !== selected!.client_id))

    await signIn(page, outsiderCredentials)
    const outsiderApps = await connectedApps(page)
    expect(outsiderApps).toEqual(expect.arrayContaining([
      expect.objectContaining({ client_id: selected!.client_id, name: appName }),
      expect.objectContaining({ name: 'Synthetic Outsider Only App' }),
    ]))
    expect(outsiderApps.some((app) => app.name === 'Synthetic Other Connected App')).toBeFalsy()
  })

  test('imports an EHI export from the Imports page, reviews a proposal, and flags the rest on the record page', async ({ page }, testInfo) => {
    await signIn(page, ownerCredentials)
    const patientName = patientNameForAttempt(testInfo.retry)
    const patientId = await ownerPatientId(page, patientName)
    await page.goto(`/phr/imports?patient_id=${patientId}`)
    await expect(page.getByLabel('Import into')).toHaveValue(String(patientId))

    await page.getByLabel('File to import').setInputFiles(syntheticEhiZip())
    await expect(page.getByRole('heading', { name: 'MyChart or Epic EHI export' })).toBeVisible()
    const start = page.getByRole('button', { name: 'Start import' })
    await expect(start).toBeDisabled()
    await page.getByLabel(`This file belongs to ${patientName}`).check()
    await start.click()

    // Intake runs from the queue (synchronously here); the page reads its result back.
    await expect(page.getByText('Every row accounted for')).toBeVisible()
    await page.getByRole('button', { name: 'Confirm source' }).click()
    const review = page.getByRole('button', { name: /^Review \d+ proposals$/ })
    await expect(review).toBeVisible()
    await review.click()

    await expect(page.getByRole('heading', { name: 'Review Epic EHI export' })).toBeVisible()
    const newRecords = page.getByRole('region', { name: 'New records' })
    const accept = newRecords.getByRole('button', { name: /^Accept: / }).first()
    const title = (await accept.getAttribute('aria-label'))!.replace(/^Accept: /, '')
    await accept.click()
    await expect(newRecords.getByRole('listitem', { name: title }).getByText('Accepted')).toBeVisible()

    await page.goto(`/phr/patient/${patientId}/immunizations`)
    const banner = page.getByRole('status').filter({ hasText: 'from an EHI export' })
    await expect(banner).toContainText(/\d+ immunizations from an EHI export are waiting for review\./)
    await expect(page.getByRole('cell', { name: title }).or(page.getByText(title)).first()).toBeVisible()
    await banner.getByRole('link', { name: 'Review with the import' }).click()
    await expect(page.getByRole('heading', { name: 'Review EHI export' })).toBeVisible()
  })

  test('imports a C-CDA file from the Imports page and leaves its records pending review', async ({ page }, testInfo) => {
    await signIn(page, ownerCredentials)
    const patientName = patientNameForAttempt(testInfo.retry)
    const patientId = await ownerPatientId(page, patientName)
    await page.goto(`/phr/imports?patient_id=${patientId}`)
    await expect(page.getByLabel('Import into')).toHaveValue(String(patientId))

    await page.getByLabel('File to import').setInputFiles(syntheticCcda())
    await expect(page.getByRole('heading', { name: 'Clinical summary or FHIR bundle' })).toBeVisible()
    await page.getByLabel(`This file belongs to ${patientName}`).check()
    await page.getByRole('button', { name: 'Import', exact: true }).click()

    await expect(page.getByRole('heading', { name: 'C-CDA clinical summary' })).toBeVisible()
    const added = page.getByText('Added as new records').locator('xpath=ancestor::dt[1]/following-sibling::dd[1]')
    await expect(added).not.toHaveText('0')

    // Written directly, but not reviewed: each record offers confirm and reject.
    await page.goto(`/phr/patient/${patientId}/immunizations`)
    await expect(page.getByRole('button', { name: /^Reject / }).first()).toBeVisible()
  })

  test('sends a PDF to the AI Agent Inbox from the Imports page by default', async ({ page }, testInfo) => {
    await signIn(page, ownerCredentials)
    const patientName = patientNameForAttempt(testInfo.retry)
    const patientId = await ownerPatientId(page, patientName)
    await page.goto(`/phr/imports?patient_id=${patientId}`)
    await expect(page.getByLabel('Import into')).toHaveValue(String(patientId))

    await page.getByLabel('File to import').setInputFiles({
      name: 'synthetic-letter.pdf',
      mimeType: 'application/pdf',
      buffer: Buffer.from('%PDF-1.4 synthetic letter for AI. No personal data.'),
    })
    // No provider key is saved, so AI work goes to the inbox by default.
    await expect(page.getByRole('heading', { name: 'Extract records with AI' })).toBeVisible()
    await page.getByLabel(`This file belongs to ${patientName}`).check()
    await page.getByRole('button', { name: 'Keep and send to AI Agent Inbox' }).click()

    await expect(page.getByText(/Sent synthetic-letter\.pdf to the AI Agent Inbox/)).toBeVisible()
    const row = page.getByRole('row').filter({ hasText: 'synthetic-letter.pdf' })
    await expect(row).toContainText('AI · AI Agent Inbox')
    await expect(row).toContainText('With your agent')

    await page.goto('/phr/config')
    await expect(page.getByText(/the default applies: the AI Agent Inbox/)).toBeVisible()
  })

  test('approves one item from the Review page and the sidebar count drops', async ({ page }, testInfo) => {
    await signIn(page, ownerCredentials)
    const patientName = patientNameForAttempt(testInfo.retry)
    const patientId = await ownerPatientId(page, patientName)
    // Import the synthetic C-CDA file so records wait for review whatever ran before.
    await page.goto(`/phr/imports?patient_id=${patientId}`)
    await page.getByLabel('File to import').setInputFiles(syntheticCcda())
    await page.getByLabel(`This file belongs to ${patientName}`).check()
    await page.getByRole('button', { name: 'Import', exact: true }).click()
    await expect(page.getByRole('heading', { name: 'C-CDA clinical summary' })).toBeVisible()

    await page.goto(`/phr/patient/${patientId}/review`)
    await expect(page.getByRole('heading', { name: 'Waiting for review' })).toBeVisible()
    const sidebar = page.getByRole('complementary', { name: 'Patient record' })
    const review = sidebar.getByRole('link', { name: /^Review, \d+ waiting for review$/ })
    await expect(review).toHaveAttribute('aria-current', 'page')
    const before = Number(/(\d+) waiting/.exec((await review.getAttribute('aria-label')) ?? '')?.[1])
    expect(before).toBeGreaterThan(0)

    const imported = page.getByRole('region', { name: /^C-CDA import/ })
    await imported.getByRole('checkbox', { name: /^Select all in / }).uncheck()
    await imported.getByRole('checkbox', { name: /^Select (?!all in )/ }).first().check()
    await imported.getByRole('button', { name: 'Approve 1 selected' }).click()
    await page.getByRole('alertdialog').getByRole('button', { name: 'Approve 1', exact: true }).click()

    await expect(page.getByText('Approved 1.', { exact: true })).toBeVisible()
    await expect(sidebar.getByRole('link', { name: before - 1 > 0 ? `Review, ${before - 1} waiting for review` : 'Review', exact: true })).toBeVisible()
  })
})
