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
    await expect(page.getByText(patientName)).toBeVisible()

    await page.goto('/phr/patients')
    await page.getByRole('link', { name: new RegExp(patientName) }).click()
    await page.getByRole('button', { name: 'Summary', exact: true }).click()
    await expect(page.getByRole('heading', { name: patientName })).toBeVisible()
  })

  test('uploads and views a synthetic document', async ({ page }, testInfo) => {
    await signIn(page, ownerCredentials)
    const patientId = await ownerPatientId(page, patientNameForAttempt(testInfo.retry))
    await page.goto(`/phr/patient/${patientId}#/documents`)
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
    // Selecting a document intentionally leaves its title visible in the library
    // pane while opening a second, detail-column heading. Assert the opened column
    // instead of relying on a globally unique title.
    await expect(page.getByRole('heading', { name: 'Synthetic Visit Note' }).last()).toBeVisible()
    await expect(page.getByRole('link', { name: 'Download' })).toBeVisible()
    await expect(page.getByTitle('Document viewer').last()).toBeVisible()
  })

  test('generates an export synchronously and exposes a working download', async ({ page }, testInfo) => {
    const patientName = patientNameForAttempt(testInfo.retry)
    await signIn(page, ownerCredentials)
    const patientId = await ownerPatientId(page, patientName)
    await page.goto(`/phr/patient/${patientId}#/summary`)
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
})
