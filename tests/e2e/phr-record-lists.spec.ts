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

async function patientUrl(page: Page, section: string): Promise<string> {
  const response = await page.request.get('/api/phr/patients')
  const { patients } = await response.json() as { patients: Array<{ id: number, display_name: string | null }> }
  const patient = patients.find((candidate) => candidate.display_name === 'Synthetic Medication Patient')
  expect(patient).toBeDefined()
  return `/phr/patient/${patient!.id}/${section}`
}

/** The columns a person can see, and whether the table overflows the box it scrolls inside. */
async function layoutOf(page: Page, table: string): Promise<{ columns: string[], overflow: number }> {
  return page.getByRole('table', { name: table }).evaluate((element) => {
    const box = element.parentElement!
    const columns = [...element.querySelectorAll('thead th')]
      .filter((header) => getComputedStyle(header).display !== 'none')
      .map((header) => header.textContent ?? '')
    return { columns, overflow: box.scrollWidth - box.clientWidth }
  })
}

/** The page itself never scrolls sideways, whatever a list inside it does. */
async function expectNoPageOverflow(page: Page): Promise<void> {
  const overflow = await page.evaluate(() => {
    const panes = [...document.querySelectorAll<HTMLElement>('[data-pane]')]
    return Math.max(document.documentElement.scrollWidth - window.innerWidth, ...panes.map((pane) => pane.scrollWidth - pane.clientWidth))
  })
  expect(overflow).toBeLessThanOrEqual(0)
}

async function expectInViewport(page: Page, name: string | RegExp): Promise<void> {
  const box = await page.getByRole('button', { name }).first().boundingBox()
  const width = page.viewportSize()!.width
  expect(box).not.toBeNull()
  expect(box!.x).toBeGreaterThanOrEqual(0)
  expect(box!.x + box!.width).toBeLessThanOrEqual(width)
}

test.describe('Record lists never cut anything off', () => {
  test.beforeEach(async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 })
    await signIn(page)
  })

  test('Fills at full width: every column fits, rows stay short, actions are on screen', async ({ page }) => {
    await page.goto(await patientUrl(page, 'medication-fills'))
    await expect(page.getByRole('table', { name: 'Fills' }).getByRole('row').nth(1)).toBeVisible()

    const { columns, overflow } = await layoutOf(page, 'Fills')
    expect(columns).toEqual(['Date', 'Medication', 'Qty', 'Paid', 'No insurance', 'Prescriber', 'Pharmacy', 'Review', 'Actions'])
    expect(overflow).toBeLessThanOrEqual(0)
    await expectNoPageOverflow(page)
    await expectInViewport(page, /^More actions for /)

    // A row was 150px when the package description wrapped in it.
    const rowHeights = await page.locator('tbody tr[data-record-row]').evaluateAll((rows) => rows.map((row) => row.getBoundingClientRect().height))
    expect(rowHeights.length).toBe(30)
    expect(Math.max(...rowHeights)).toBeLessThanOrEqual(110)
  })

  test('Fills: search anywhere in the name, and choose the columns', async ({ page }) => {
    await page.goto(await patientUrl(page, 'medication-fills'))
    const table = page.getByRole('table', { name: 'Fills' })
    await expect(table.getByText('mometasone furoate').first()).toBeVisible()

    await page.getByRole('searchbox', { name: 'Search by name' }).fill('METAS')
    await expect(table.getByText('lisinopril')).toHaveCount(0)
    await expect(table.getByText('mometasone furoate').first()).toBeVisible()
    await page.getByRole('searchbox', { name: 'Search by name' }).fill('')

    await page.getByRole('checkbox', { name: 'Prescriber' }).uncheck()
    await expect.poll(async () => (await layoutOf(page, 'Fills')).columns).not.toContain('Prescriber')
    await page.reload()
    await expect(table.getByText('mometasone furoate').first()).toBeVisible()
    expect((await layoutOf(page, 'Fills')).columns).not.toContain('Prescriber')
    await page.getByRole('checkbox', { name: 'Prescriber' }).check()
    await expect.poll(async () => (await layoutOf(page, 'Fills')).columns).toContain('Prescriber')
  })

  test('Hiding the Medications list is a setting that follows the person', async ({ page }) => {
    await page.goto('/phr/config')
    await page.getByRole('tab', { name: 'Display' }).click()
    await page.getByRole('checkbox', { name: /^Hide the Medications list/ }).check()

    await page.goto(await patientUrl(page, 'medication-fills'))
    await expect(page.getByRole('table', { name: 'Fills' }).getByRole('row').nth(1)).toBeVisible()
    await expect(page.getByRole('tablist', { name: 'Medications views' })).toHaveCount(0)

    await page.goto('/phr/config')
    await page.getByRole('tab', { name: 'Display' }).click()
    await page.getByRole('checkbox', { name: /^Hide the Medications list/ }).uncheck()
    await page.goto(await patientUrl(page, 'medication-fills'))
    await expect(page.getByRole('tablist', { name: 'Medications views' })).toBeVisible()
  })

  test('The section has one header row, and the list starts high on the page', async ({ page }) => {
    await page.goto(await patientUrl(page, 'medication-fills'))
    await expect(page.getByRole('table', { name: 'Fills' }).getByRole('row').nth(1)).toBeVisible()
    await expect(page.getByRole('navigation', { name: 'Breadcrumb' })).toHaveCount(0)

    const tabs = (await page.getByRole('tablist', { name: 'Medications views' }).boundingBox())!
    const add = (await page.getByRole('button', { name: 'Add fill' }).boundingBox())!
    expect(add.y).toBeLessThan(tabs.y + tabs.height)
    expect(add.y + add.height).toBeGreaterThan(tabs.y)
    // Tabs, then filters: the table was 245px down with the title and breadcrumb above it.
    expect((await page.getByRole('table', { name: 'Fills' }).boundingBox())!.y).toBeLessThan(190)
  })

  test('Fills beside the panel: the list drops secondary columns and the panel holds the rest', async ({ page }) => {
    await page.goto(await patientUrl(page, 'medication-fills'))
    await page.getByRole('table', { name: 'Fills' }).getByText('mometasone furoate').first().click()
    const panel = page.getByRole('complementary', { name: 'Fill detail' })
    await expect(panel.getByRole('heading', { name: /mometasone furoate/ })).toBeVisible()
    await expect(page).toHaveURL(/medication-fills\/\d+$/)

    const { columns, overflow } = await layoutOf(page, 'Fills')
    expect(columns).toEqual(['Date', 'Medication', 'Qty', 'Paid', 'Pharmacy', 'Review'])
    expect(overflow).toBeLessThanOrEqual(0)
    await expectNoPageOverflow(page)

    await expect(panel.getByText('Rivera, Ana Maria, MD')).toBeVisible()
    await expect(panel.getByText('Example Pharmaceutical Manufacturing Company, Incorporated')).toHaveCount(0)
    await expect(panel.getByText(/120 spray, metered in 1 bottle/)).toHaveCount(0)
    // Where to find the rest, folded away until asked for.
    await expect(panel.getByText('Rx number')).toBeHidden()
    await panel.getByText('More details').click()
    await expect(panel.getByText('Rx number')).toBeVisible()

    // A decision in the panel reaches the row beside it without a reload.
    const pendingBefore = await page.getByRole('table', { name: 'Fills' }).getByText('Pending review').count()
    await panel.getByRole('button', { name: /^Approve/ }).click()
    await expect(panel.getByTestId('record-review-bar')).toHaveCount(0)
    await expect(page.getByRole('table', { name: 'Fills' }).getByText('Pending review')).toHaveCount(pendingBefore - 1)
  })

  test('Medications at full width and beside the panel', async ({ page }) => {
    await page.goto(await patientUrl(page, 'medications'))
    const table = page.getByRole('table', { name: 'Medications' })
    await expect(table.getByText('mometasone furoate')).toBeVisible()

    // Each medication groups its fills: the last one and how many.
    await expect(table.locator('tbody tr', { hasText: 'mometasone furoate' }).first().getByText(/^\d+ fills?$/)).toBeVisible()

    let layout = await layoutOf(page, 'Medications')
    expect(layout.columns).toEqual(['Medication', 'Dose', 'Route', 'Schedule', 'Started', 'Last fill', 'Prescriber', 'Status', 'Actions'])
    expect(layout.overflow).toBeLessThanOrEqual(0)
    await expectInViewport(page, /^More actions for /)

    // The name keeps the room: it used to be squeezed to five lines by a details column that could not wrap.
    const nameCell = table.getByText('fluticasone propionate and salmeterol xinafoate (Advair Diskus)')
    const nameBox = await nameCell.boundingBox()
    expect(nameBox!.height).toBeLessThan(70)
    // Nor does a long free-text schedule take that room back.
    const rowHeights = await table.locator('tbody tr[data-record-row]').evaluateAll((rows) => rows.map((row) => row.getBoundingClientRect().height))
    expect(Math.max(...rowHeights)).toBeLessThanOrEqual(110)

    await page.getByRole('button', { name: /^Ended/ }).click()
    await expect(table.getByText('amoxicillin-clavulanate (Augmentin)')).toBeVisible()
    layout = await layoutOf(page, 'Medications')
    expect(layout.columns).toEqual(['Medication', 'Dose', 'Route', 'Schedule', 'Started', 'Ended', 'Last fill', 'Prescriber', 'Status', 'Actions'])
    expect(layout.overflow).toBeLessThanOrEqual(0)

    await page.getByRole('button', { name: /^Active/ }).click()
    await table.getByText('mometasone furoate').click()
    const panel = page.getByRole('complementary', { name: 'Medication detail' })
    await expect(panel.getByRole('heading', { name: 'mometasone furoate' })).toBeVisible()
    await expect(panel.getByRole('table', { name: 'Fill history' }).getByRole('row').nth(1)).toBeVisible()

    layout = await layoutOf(page, 'Medications')
    expect(layout.columns).toEqual(['Medication', 'Started', 'Last fill', 'Status'])
    expect(layout.overflow).toBeLessThanOrEqual(0)
    await expect(table.getByText('50 ug · nasal · daily')).toBeVisible()
    expect((await layoutOf(page, 'Fill history')).overflow).toBeLessThanOrEqual(0)
    await expectNoPageOverflow(page)

    // The medication's fills open filtered to it, through an in-app navigation.
    await panel.getByRole('link', { name: 'Show these fills' }).click()
    await expect(page).toHaveURL(/medication-fills\?medication_id=\d+$/)
    await expect(page.getByRole('button', { name: /^Show all fills, not only / })).toBeVisible()
    const fills = page.getByRole('table', { name: 'Fills' })
    await expect(fills.getByText('mometasone furoate').first()).toBeVisible()
    await expect(fills.getByText('lisinopril')).toHaveCount(0)
  })

  test('An open menu keeps the keyboard: shortcuts stay off, and Esc leaves the panel open', async ({ page }) => {
    await page.setViewportSize({ width: 2000, height: 1000 })
    await page.goto(await patientUrl(page, 'medications'))
    const table = page.getByRole('table', { name: 'Medications' })
    await table.getByText('lisinopril', { exact: true }).click()
    const panel = page.getByRole('complementary', { name: 'Medication detail' })
    await expect(panel.getByRole('heading', { name: 'lisinopril' })).toBeVisible()

    // Wide enough for the row's own actions beside the panel.
    expect((await layoutOf(page, 'Medications')).columns).toContain('Actions')
    await table.getByRole('button', { name: 'More actions for omeprazole' }).click()
    await expect(page.getByRole('menuitem', { name: 'Edit' })).toBeVisible()

    // G then L would go to Labs if the page's shortcuts were listening behind the menu.
    const url = page.url()
    await page.keyboard.press('g')
    await page.keyboard.press('l')
    await page.waitForTimeout(200)
    expect(page.url()).toBe(url)

    await page.keyboard.press('Escape')
    await expect(page.getByRole('menuitem', { name: 'Edit' })).toHaveCount(0)
    await expect(panel).toBeVisible()
  })
})

test.describe('Record lists on a phone', () => {
  test.beforeEach(async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 })
    await signIn(page)
    await page.setViewportSize({ width: 390, height: 844 })
  })

  test('Fills are two-line rows, filtered from a sheet, opening their own page with a review bar', async ({ page }) => {
    await page.goto(await patientUrl(page, 'medication-fills'))
    const table = page.getByRole('table', { name: 'Fills' })
    await expect(table.getByRole('row').nth(1)).toBeVisible()

    const { columns, overflow } = await layoutOf(page, 'Fills')
    expect(columns).toEqual(['Medication', 'Paid'])
    expect(overflow).toBeLessThanOrEqual(0)
    await expectNoPageOverflow(page)
    const row = table.locator('tbody tr[data-record-row]').first()
    expect((await row.boundingBox())!.height).toBeGreaterThanOrEqual(44)
    await expect(row.getByText(/^2026-10-28 · Qty 90 · /)).toBeVisible()
    await expect(table.getByRole('img', { name: 'Pending review' }).first()).toBeVisible()

    const add = page.getByRole('button', { name: 'Add fill' })
    expect((await add.boundingBox())!.height).toBeGreaterThanOrEqual(44)

    await page.getByRole('button', { name: 'Filter', exact: true }).click()
    const sheet = page.getByRole('dialog', { name: 'Filter fills' })
    await expect(sheet.getByLabel('Filled from')).toBeVisible()
    const sheetBox = (await sheet.boundingBox())!
    expect(sheetBox.y + sheetBox.height).toBeLessThanOrEqual(844)
    await sheet.getByRole('button', { name: /^Show 30 fills$/ }).click()
    await expect(sheet).toHaveCount(0)

    await table.locator('tbody tr[data-record-row]', { has: page.getByRole('img', { name: 'Pending review' }) }).first().click()
    await expect(page).toHaveURL(/medication-fills\/\d+$/)
    const bar = page.getByTestId('record-review-bar')
    await expect(bar).toBeVisible()
    await expectNoPageOverflow(page)
    expect((await page.getByRole('link', { name: 'Close detail' }).boundingBox())!.height).toBeGreaterThanOrEqual(44)
    const barBox = (await bar.boundingBox())!
    expect(barBox.x).toBeLessThanOrEqual(1)
    expect(barBox.width).toBeGreaterThanOrEqual(388)
    expect(barBox.y + barBox.height).toBeLessThanOrEqual(844)
    for (const name of [/^Approve/, /^Reject/]) {
      expect((await bar.getByRole('button', { name }).boundingBox())!.height).toBeGreaterThanOrEqual(44)
    }
  })

  test('The top bar is one row, and the menu holds the sections', async ({ page }) => {
    await page.goto(await patientUrl(page, 'medications'))
    await expect(page.getByRole('table', { name: 'Medications' }).getByText('mometasone furoate')).toBeVisible()

    const header = page.locator('header').first()
    expect((await header.boundingBox())!.height).toBeLessThanOrEqual(60)
    await expect(header.getByText('Synthetic Medication Patient')).toBeVisible()
    await expect(page.getByRole('navigation', { name: 'Sections' })).toBeHidden()
    for (const name of ['Open menu', /^Search this patient/, 'Account']) {
      expect((await header.getByRole('button', { name }).boundingBox())!.height).toBeGreaterThanOrEqual(44)
      await expectInViewport(page, name)
    }

    // The bar stays where it is: the page behind it does not scroll, only the pane does.
    await page.evaluate(() => window.scrollTo(0, 500))
    expect(await page.evaluate(() => window.scrollY)).toBe(0)
    expect((await header.boundingBox())!.y).toBe(0)
    await expect(page.getByRole('navigation', { name: 'Breadcrumb' })).toBeHidden()

    await page.getByRole('button', { name: 'Open menu' }).click()
    const menu = page.getByRole('dialog', { name: 'Menu' })
    await expect(menu.getByRole('link', { name: /^Medications/ })).toHaveAttribute('aria-current', 'page')
    await expect(menu.getByRole('link', { name: /^Medications/ })).toHaveAttribute('href', /medication-fills$/)
    await expect(menu.getByText('Clinical')).toHaveCount(0)
    await expect(menu.getByRole('link', { name: /^Fills/ })).toHaveCount(0)
    expect((await menu.getByRole('button', { name: 'Close' }).boundingBox())!.height).toBeGreaterThanOrEqual(44)
    // A long patient name stays inside the sheet.
    const menuBox = (await menu.boundingBox())!
    const switcher = (await menu.getByRole('button', { name: /^Switch patient/ }).boundingBox())!
    expect(switcher.x + switcher.width).toBeLessThanOrEqual(menuBox.x + menuBox.width)
    await menu.getByRole('link', { name: 'Imports' }).click()
    await expect(page).toHaveURL(/\/phr\/imports/)
    await expect(menu).toHaveCount(0)
    await expect(header.locator('span', { hasText: 'Imports' })).toBeVisible()

    // A section page has the same menu, without a patient's record in it.
    await page.getByRole('button', { name: 'Open menu' }).click()
    await expect(page.getByRole('dialog', { name: 'Menu' }).getByRole('link', { name: 'Imports' })).toHaveAttribute('aria-current', 'page')
  })

  test('Medications and Fills are one section, moving between them with the tabs', async ({ page }) => {
    await page.goto(await patientUrl(page, 'medications'))
    await page.getByRole('tab', { name: /^Fills \d+/ }).click()
    await expect(page).toHaveURL(/medication-fills$/)
    await expect(page.getByRole('heading', { name: 'Medications', level: 1 })).toBeVisible()
    await expect(page.getByRole('table', { name: 'Fills' }).getByRole('row').nth(1)).toBeVisible()
    await expectNoPageOverflow(page)

    await page.getByRole('tab', { name: /^Medications/ }).click()
    await expect(page).toHaveURL(/medications$/)
    await page.getByRole('button', { name: /^Ended/ }).click()
    await expect(page).toHaveURL(/medications\?status=ended$/)
    await expect(page.getByRole('table', { name: 'Medications' }).getByText('amoxicillin-clavulanate (Augmentin)')).toBeVisible()
    await expectNoPageOverflow(page)
  })

  test('Medications stay on the phone with tabs, and a medication\'s fills fit', async ({ page }) => {
    await page.goto(await patientUrl(page, 'medications'))
    const table = page.getByRole('table', { name: 'Medications' })
    await expect(table.getByText('mometasone furoate')).toBeVisible()

    const { columns, overflow } = await layoutOf(page, 'Medications')
    expect(columns).toEqual(['Medication'])
    expect(overflow).toBeLessThanOrEqual(0)
    await expectNoPageOverflow(page)
    expect((await page.getByRole('button', { name: /^Ended/ }).boundingBox())!.height).toBeGreaterThanOrEqual(44)
    await expect(page.getByRole('button', { name: 'Show rejected' })).toHaveCount(0)

    await table.getByText('mometasone furoate').click()
    await expect(page.getByRole('heading', { name: 'mometasone furoate' })).toBeVisible()
    await expect(page.getByRole('table', { name: 'Fill history' }).getByRole('row').nth(1)).toBeVisible()
    await expectNoPageOverflow(page)
    expect((await layoutOf(page, 'Fill history')).overflow).toBeLessThanOrEqual(0)
    await expect(page.getByTestId('record-review-bar')).toBeVisible()
  })
})
