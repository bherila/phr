import { expect, type Page, test } from '@playwright/test'

const ownerCredentials = {
  email: 'record-owner@example.test',
  password: 'owner-password',
}

async function signIn(page: Page): Promise<void> {
  await page.context().clearCookies()
  await page.goto('/phr')
  await expect(page.getByRole('heading', { name: 'Synthetic identity provider' })).toBeVisible()
  await page.getByLabel('Email').fill(ownerCredentials.email)
  await page.getByLabel('Password').fill(ownerCredentials.password)
  await page.getByRole('button', { name: 'Authorize' }).click()
  await expect(page).toHaveURL(/\/phr\/patients/)
}

/** POST through the signed-in session, with the page's CSRF token. */
async function post(page: Page, url: string, data: Record<string, unknown>): Promise<Record<string, unknown>> {
  const token = await page.locator('meta[name="csrf-token"]').getAttribute('content')
  const response = await page.request.post(url, {
    data,
    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token ?? '', 'X-Requested-With': 'XMLHttpRequest' },
  })
  expect(response.status()).toBe(201)
  return await response.json() as Record<string, unknown>
}

/** The same-origin API requests the page sends from now on, as path plus query. */
function apiRequests(page: Page): string[] {
  const urls: string[] = []
  page.on('request', (request) => {
    const url = new URL(request.url())
    if (url.pathname.startsWith('/api/') && ['fetch', 'xhr'].includes(request.resourceType())) {
      urls.push(`${request.method()} ${url.pathname}${url.search}`)
    }
  })
  return urls
}

/** The keys of the page's preload, read from the HTML the server sent. */
async function preloadedUrls(page: Page, path: string): Promise<string[]> {
  const response = await page.request.get(path)
  expect(response.status()).toBe(200)
  const match = /<script type="application\/json" id="phr-preload">([\s\S]*?)<\/script>/.exec(await response.text())
  return match ? Object.keys(JSON.parse(match[1]!) as Record<string, unknown>) : []
}

/** Milliseconds from navigation start to the first table row, recorded in the page itself. */
async function firstRowAt(page: Page): Promise<number> {
  await expect(page.locator('tbody tr').first()).toBeVisible()
  return await page.evaluate(() => (window as unknown as { __firstRowAt: number }).__firstRowAt)
}

/** Serves the page without its preload element: the request pattern before this change. */
async function withoutPreload(page: Page): Promise<void> {
  await page.route((url) => url.pathname.startsWith('/phr/patient/'), async (route) => {
    if (route.request().resourceType() !== 'document') {
      await route.continue()
      return
    }
    const response = await route.fetch()
    const body = (await response.text()).replace(/<script type="application\/json" id="phr-preload">[\s\S]*?<\/script>/, '')
    await route.fulfill({ response, body })
  })
}

test.describe.serial('PHR first-load preload', () => {
  let patientId = 0
  let conditionId = 0

  test.beforeEach(async ({ page }) => {
    await page.addInitScript(() => {
      new MutationObserver((_records, observer) => {
        if (document.querySelector('tbody tr')) {
          Object.defineProperty(window, '__firstRowAt', { value: performance.now() })
          observer.disconnect()
        }
      }).observe(document, { childList: true, subtree: true })
    })
  })

  test('seeds a synthetic patient', async ({ page }, testInfo) => {
    await signIn(page)
    const created = await post(page, '/api/phr/patients', { display_name: `Synthetic Preload Patient ${testInfo.retry}` })
    patientId = (created.patient as { id: number }).id
    for (const analyte of ['Hemoglobin', 'Hematocrit', 'Platelets']) {
      await post(page, `/api/phr/patients/${patientId}/lab-results`, { test_name: 'Synthetic CBC', analyte, value: '12', unit: 'g/dL' })
    }
    const condition = await post(page, `/api/phr/patients/${patientId}/conditions`, { name: 'Synthetic condition' })
    conditionId = (condition.condition as { id: number }).id
  })

  for (const view of [
    { name: 'labs', path: () => `/phr/patient/${patientId}/labs` },
    { name: 'a condition', path: () => `/phr/patient/${patientId}/conditions/${conditionId}` },
  ]) {
    test(`opens ${view.name} without a request for any preloaded URL`, async ({ page }) => {
      await signIn(page)
      const preloaded = await preloadedUrls(page, view.path())
      expect(preloaded.length).toBeGreaterThan(0)

      const requests = apiRequests(page)
      await page.goto(view.path())
      const at = await firstRowAt(page)
      if (view.name === 'a condition') await expect(page.getByRole('heading', { name: 'Synthetic condition', level: 2 })).toBeVisible()
      await page.waitForLoadState('networkidle')
      console.log(`[preload] ${view.name}: ${requests.length} API requests on first load, first row at ${Math.round(at)} ms`, requests)

      expect(requests.filter((request) => preloaded.some((url) => request === `GET ${url}`))).toEqual([])
      expect(requests).toEqual([])
    })

    test(`baseline: ${view.name} without the preload element`, async ({ page }) => {
      await signIn(page)
      await withoutPreload(page)
      const requests = apiRequests(page)
      await page.goto(view.path())
      const at = await firstRowAt(page)
      await page.waitForLoadState('networkidle')
      console.log(`[preload] baseline ${view.name}: ${requests.length} API requests on first load, first row at ${Math.round(at)} ms`, requests)
      expect(requests.length).toBeGreaterThan(0)
    })
  }

  test('uses each entry once: returning to a view after navigating refetches it', async ({ page }) => {
    await signIn(page)
    const requests = apiRequests(page)
    await page.goto(`/phr/patient/${patientId}/labs`)
    await firstRowAt(page)
    expect(requests).toEqual([])

    const sidebar = page.getByRole('complementary', { name: 'Patient record' })
    await sidebar.getByRole('link', { name: 'Conditions', exact: true }).click()
    await expect(page.getByText('Synthetic condition')).toBeVisible()
    await sidebar.getByRole('link', { name: 'Labs', exact: true }).click()
    await expect(page.locator('tbody tr').first()).toBeVisible()
    await page.waitForLoadState('networkidle')

    expect(requests).toContain(`GET /api/phr/patients/${patientId}/conditions`)
    expect(requests).toContain(`GET /api/phr/patients/${patientId}/lab-results`)
  })
})
