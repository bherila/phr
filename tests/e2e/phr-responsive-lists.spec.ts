import { expect, type Page, test } from '@playwright/test'

async function signIn(page: Page): Promise<void> {
  await page.context().clearCookies()
  await page.goto('/phr')
  await page.getByLabel('Email').fill('record-owner@example.test')
  await page.getByLabel('Password').fill('owner-password')
  await page.getByRole('button', { name: 'Authorize' }).click()
  await expect(page).toHaveURL(/\/phr\/patients/)
}

async function post(page: Page, url: string, data: Record<string, unknown>): Promise<Record<string, unknown>> {
  const token = await page.locator('meta[name="csrf-token"]').getAttribute('content')
  const response = await page.request.post(url, { data, headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token ?? '', 'X-Requested-With': 'XMLHttpRequest' } })
  expect(response.status()).toBe(201)
  return await response.json() as Record<string, unknown>
}

const longName = `Synthetic ${'LongUnbrokenRecordName'.repeat(8)}`
const longLabName = `Synthetic ${'LongLabAnalyte'.repeat(6)}`
const scenarios = [
  { section: 'conditions', table: 'Active Problems', name: longName, wideOnly: 'Actions', edit: true,
    data: { name: longName, clinical_status: 'active', onset_date: '2026-03-14', notes: 'Synthetic condition notes' } },
  { section: 'allergies', table: 'Active Allergies', name: longName, wideOnly: null, edit: true,
    data: { substance: longName, clinical_status: 'active', criticality: 'high', severity: 'severe', reaction: `Synthetic ${'LongReaction'.repeat(12)}` } },
  { section: 'immunizations', table: 'Immunizations', name: longName, wideOnly: 'Facility', edit: true,
    data: { vaccine_name: longName, administered_on: '2026-03-14', dose_number: 1, facility_name: `Synthetic ${'LongFacility'.repeat(12)}` } },
  { section: 'labs', endpoint: 'lab-results', table: 'Other', name: longLabName, wideOnly: 'Range', edit: false,
    data: { test_name: 'Synthetic layout panel', analyte: longLabName, value: 'NotDetected'.repeat(18), reference_range_text: 'Synthetic range', result_datetime: '2026-03-14 08:00:00' } },
  { section: 'vitals', table: 'Vitals', name: 'Blood Pressure', wideOnly: 'Body Site', edit: false,
    data: { vital_name: 'Blood Pressure', vital_date: '2026-03-14', vital_value: '120/80', value_numeric: 120, value_numeric_secondary: 80, unit: 'mmHg', secondary_unit: 'mmHg', body_site: `Synthetic ${'LongSite'.repeat(10)}` } },
] as const

for (const scenario of scenarios) {
  test(`${scenario.section}: full width, the same viewport beside detail, and a phone retain readable records`, async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 })
    await signIn(page)
    const created = await post(page, '/api/phr/patients', { display_name: `Synthetic responsive ${scenario.section} patient` })
    const patient = created.patient as { id: number }
    const endpoint = 'endpoint' in scenario ? scenario.endpoint : scenario.section
    await post(page, `/api/phr/patients/${patient.id}/${endpoint}`, scenario.data)
    const url = `/phr/patient/${patient.id}/${scenario.section}`
    await page.goto(url)

    const table = page.getByRole('table', { name: scenario.table, exact: true })
    await expect(table.getByText(scenario.name, { exact: true })).toBeVisible()
    const layout = () => table.evaluate((element) => {
      const box = element.parentElement!
      return {
        width: box.clientWidth, overflow: box.scrollWidth - box.clientWidth,
        columns: [...element.querySelectorAll('thead th')].filter((th) => getComputedStyle(th).display !== 'none').map((th) => th.textContent?.trim()),
      }
    })
    let current = await layout()
    expect(current.width).toBeGreaterThanOrEqual(960)
    expect(current.overflow).toBeLessThanOrEqual(0)
    if (scenario.wideOnly) expect(current.columns).toContain(scenario.wideOnly)

    await table.getByText(scenario.name, { exact: true }).click()
    await expect(page.locator('[data-pane="detail"]')).toBeVisible()
    // The viewport is unchanged: the detail, not a media query, narrows the list.
    await expect.poll(async () => (await layout()).width).toBeLessThan(960)
    current = await layout()
    expect(current.width).toBeGreaterThanOrEqual(560)
    expect(current.overflow).toBeLessThanOrEqual(0)
    if (scenario.wideOnly) expect(current.columns).not.toContain(scenario.wideOnly)

    await page.setViewportSize({ width: 390, height: 844 })
    await page.goto(url)
    await expect(table.getByText(scenario.name, { exact: true })).toBeVisible()
    current = await layout()
    expect(current.width).toBeLessThan(560)
    expect(current.columns).toHaveLength(1)
    expect(current.overflow).toBeLessThanOrEqual(0)
    const pageOverflow = await page.evaluate(() => Math.max(document.documentElement.scrollWidth - innerWidth,
      ...[...document.querySelectorAll<HTMLElement>('[data-pane]')].map((pane) => pane.scrollWidth - pane.clientWidth)))
    expect(pageOverflow).toBeLessThanOrEqual(0)
    await table.getByText(scenario.name, { exact: true }).click()
    await expect(page).toHaveURL(new RegExp(`/${scenario.section}/\\d+$`))
    const detail = page.locator('[data-pane="detail"]')
    await expect(detail).toBeVisible()
    await expect(table).toHaveCount(0)
    if (scenario.edit) await expect(detail.getByRole('button', { name: scenario.section === 'allergies' ? 'Edit' : `Edit ${scenario.name}` })).toBeVisible()
    if (scenario.section === 'vitals') await expect(detail.getByRole('button', { name: 'Diastolic BP trend' })).toBeVisible()
    if (scenario.section === 'labs') await expect(detail.getByText(`Selected result: ${scenario.name}`, { exact: true })).toBeVisible()
  })
}
