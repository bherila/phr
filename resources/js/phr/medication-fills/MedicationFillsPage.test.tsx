import '@testing-library/jest-dom'

import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react'

import { resetDisplayPreferences } from '@/lib/displayPreferences'
import { recordChanged } from '@/phr/clinical/recordChanges'
import MedicationFillDetail from '@/phr/medication-fills/MedicationFillDetail'
import { MedicationFillHistory } from '@/phr/medication-fills/MedicationFillHistory'
import MedicationFillsPage from '@/phr/medication-fills/MedicationFillsPage'

const mockGet = jest.fn()
const mockPostRaw = jest.fn()
const mockPatchRaw = jest.fn()
const mockPatch = jest.fn()
const mockDelete = jest.fn()
const mockPut = jest.fn()

jest.mock('@/fetchWrapper', () => ({
  fetchWrapper: {
    get: (...args: unknown[]) => mockGet(...args),
    getRaw: async (...args: unknown[]) => {
      const body: unknown = await mockGet(...args)
      return { ok: true, status: 200, statusText: 'OK', text: async () => JSON.stringify(body), json: async () => body }
    },
    postRaw: (...args: unknown[]) => mockPostRaw(...args),
    patchRaw: (...args: unknown[]) => mockPatchRaw(...args),
    patch: (...args: unknown[]) => mockPatch(...args),
    delete: (...args: unknown[]) => mockDelete(...args),
    put: (...args: unknown[]) => mockPut(...args),
  },
}))

const DRUG = {
  substance_names: ['atorvastatin calcium'],
  strengths: ['20 mg/1'],
  brand_name: 'Lipitor',
  dosage_form: 'tablet, film coated',
  route: 'oral',
  labeler_name: 'Parke-Davis Div of Pfizer Inc',
  package_description: '90 tablet, film coated in 1 bottle (0071-0155-23)',
  rxcui: '617312',
}

function makeFill(overrides: Record<string, unknown> = {}) {
  return {
    id: 1,
    patient_id: 42,
    user_id: 7,
    medication_id: 7,
    medication: { id: 7, name: 'atorvastatin calcium' },
    source_kind: 'manual',
    review_status: 'confirmed',
    ndc_10: '0071-0155-23',
    ndc_status: 'resolved',
    drug: DRUG,
    filled_on: '2026-10-28',
    quantity: '90.000',
    quantity_unit: 'tablet',
    days_supply: 90,
    directions: 'Take 1 Tablet By Mouth Daily',
    prescriber_name: 'Dr. A. Smith',
    paid_cents: 1000,
    retail_cents: 18999,
    pharmacy_name: 'Example Pharmacy',
    pharmacy_location: null,
    ...overrides,
  }
}

function candidate(ndc10: string, extra: Record<string, unknown> = {}) {
  return {
    ndc_10: ndc10,
    ndc_11: null,
    product_ndc: null,
    drug: { ...DRUG, package_description: `pkg for ${ndc10}`, labeler_name: `Labeler ${ndc10}` },
    match_reasons: ['package NDC exact'],
    fetched_at: null,
    ...extra,
  }
}

function jsonResponse(status: number, body: unknown) {
  return { ok: status >= 200 && status < 300, status, statusText: 'x', text: async () => JSON.stringify(body) }
}

let fills: unknown[]
let lookups: Record<string, unknown>
let canManage: boolean

function renderPage() {
  return render(<MedicationFillsPage patientId={42} />)
}

/** The table once its rows are in: it is on screen, with skeleton rows, while they load. */
async function findLoadedFills(): Promise<HTMLElement> {
  const table = await screen.findByRole('table', { name: 'Fills' })
  await within(table).findAllByRole('cell')
  return table
}

/** Opens a row's ⋯ menu and picks an item from it. */
async function pickFromRowMenu(fill: string, item: string) {
  fireEvent.click(await screen.findByRole('button', { name: `More actions for ${fill}` }))
  fireEvent.click(await screen.findByRole('menuitem', { name: item }))
}

beforeEach(() => {
  jest.clearAllMocks()
  fills = [
    makeFill(),
    makeFill({ id: 2, filled_on: '2025-03-04', paid_cents: null, retail_cents: null, pharmacy_name: null, drug: null, ndc_status: 'unresolved', medication: { id: 9, name: 'Mystery' }, medication_id: 9, drug_as_printed: 'mystery tab', directions: null, quantity: '30', quantity_unit: null }),
  ]
  lookups = {}
  canManage = true
  mockGet.mockImplementation(async (url: string) => {
    if (url.startsWith('/api/ndc/lookup')) {
      const ndc = new URL(url, 'http://x').searchParams.get('ndc') ?? ''
      return lookups[ndc]
    }
    if (url.startsWith('/api/ndc/search')) return lookups['search']
    if (url === '/api/phr/patients/42/medications') {
      const medication = (id: number, name: string) => ({ id, patient_id: 42, user_id: 7, name, rxnorm_code: null, dose: null, dose_unit: null, route: null, frequency: null, started_on: null, ended_on: null, status: 'active', prescriber_name: null, reason_for_use: null, raw_text: null, created_at: null, updated_at: null })
      return { medications: [medication(7, 'atorvastatin calcium'), medication(9, 'Mystery')], can_manage: true }
    }
    if (url.endsWith('/drug-detail')) return lookups['detail']
    if (url.startsWith('/api/ndc/label')) return lookups['label']
    if (url === '/api/phr/patients/42/medication-fills/1') return { medication_fill: fills[0] }
    if (url === '/api/phr/patients/42/medication-fills') return { medication_fills: fills, can_manage: true }
    if (url === '/api/phr/patients/42/medications/7/fills') return { medication_fills: [fills[0]], can_manage: true }
    return {}
  })
})

describe('MedicationFillsPage table', () => {
  it('has no tabs for someone who hides the Medications list, and asks for no list it will not show', async () => {
    const initial = document.createElement('script')
    initial.id = 'app-initial-data'
    initial.type = 'application/json'
    initial.textContent = JSON.stringify({ currentUser: { name: 'Example', display_preferences: { hide_medication_list: true } } })
    document.body.append(initial)
    resetDisplayPreferences()
    renderPage()
    await findLoadedFills()
    expect(screen.queryByRole('tablist', { name: 'Medications views' })).not.toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Medications', level: 1 })).not.toHaveClass('sr-only')
    expect(mockGet).not.toHaveBeenCalledWith('/api/phr/patients/42/medications')
    initial.remove()
    resetDisplayPreferences()
  })

  it('merges strength, form and route beside the name by default, and never for someone who chose separate columns', async () => {
    const initial = document.createElement('script')
    initial.id = 'app-initial-data'
    initial.type = 'application/json'
    initial.textContent = JSON.stringify({ currentUser: { name: 'Example', display_preferences: { separate_columns: true } } })
    document.body.append(initial)
    resetDisplayPreferences()
    renderPage()
    const table = await findLoadedFills()
    expect(within(table).getAllByRole('columnheader').map((header) => header.textContent)).toEqual(['Date', 'Medication', 'Strength', 'Form', 'Route', 'Qty', 'Paid', 'No insurance', 'Prescriber', 'Pharmacy', 'Actions'])
    const cells = within(within(table).getAllByRole('row')[1]!).getAllByRole('cell')
    expect(cells[2]).toHaveTextContent(/^20 mg\/1$/)
    expect(cells[3]).toHaveTextContent(/^tablet, film coated$/)
    expect(cells[4]).toHaveTextContent(/^oral$/)
    expect(cells[1]).not.toHaveTextContent('film coated')
    initial.remove()
    resetDisplayPreferences()
  })

  it('sits under the Medications title, on the section\'s Fills tab with every tab counted', async () => {
    renderPage()
    expect(await screen.findByRole('heading', { name: 'Medications', level: 1 })).toHaveClass('sr-only')
    expect(await screen.findByRole('tab', { name: /^Fills \d+$/ })).toHaveAttribute('aria-selected', 'true')
    const medications = await screen.findByRole('tab', { name: /^Medications \d+$/ })
    fireEvent.click(medications)
    expect(window.location.pathname).toBe('/phr/patient/42/medications')
    window.history.replaceState(null, '', '/')
  })

  it('lists what differs from fill to fill, with the drug beside its name', async () => {
    renderPage()

    const table = await findLoadedFills()
    const headers = within(table).getAllByRole('columnheader').map((header) => header.textContent)
    expect(headers).toEqual(['Date', 'Medication', 'Qty', 'Paid', 'No insurance', 'Pharmacy and prescriber', 'Actions'])

    const row = within(table).getAllByRole('row')[1]!
    const cells = within(row).getAllByRole('cell')
    expect(cells[0]).toHaveTextContent('2026-10-28')
    expect(within(cells[1]!).getByText('atorvastatin calcium')).toBeInTheDocument()
    expect(cells[1]).toHaveTextContent('20 mg/1 · oral tablet, film coated')
    expect(cells[1]).not.toHaveTextContent('Lipitor')
    expect(cells[2]).toHaveTextContent(/^90$/)
    expect(cells[3]).toHaveTextContent(/^\$10\.00$/)
    expect(cells[4]).toHaveTextContent(/^\$189\.99$/)
    expect(cells[5]).toHaveTextContent(/^Example PharmacyDr\. A\. Smith$/)
    // Facts of the drug itself moved to the fill's detail.
    expect(within(table).queryByText(/Parke-Davis/)).not.toBeInTheDocument()
    expect(within(table).queryByText(/in 1 bottle/)).not.toBeInTheDocument()
  })

  it('keeps every shown column and its stand-in on the widths the list changes shape at', async () => {
    renderPage()
    const table = await findLoadedFills()
    const tier = (name: string) => within(table).getByRole('columnheader', { name })
    expect(tier('Date')).toHaveClass('hidden', '@[560px]/record-list:table-cell')
    expect(tier('Pharmacy and prescriber')).toHaveClass('hidden', '@[560px]/record-list:table-cell')
    expect(tier('No insurance')).toHaveClass('hidden', '@[960px]/record-list:table-cell')
    expect(tier('Actions')).toHaveClass('hidden', '@[960px]/record-list:table-cell')
    expect(tier('Medication')).not.toHaveClass('hidden')
    expect(tier('Paid')).not.toHaveClass('hidden')
    // Below 560px the date, the quantity and the pharmacy are on the row's second line.
    const line = screen.getByText('2026-10-28 · Qty 90 · Example Pharmacy')
    expect(line).toHaveClass('@[560px]/record-list:hidden')
  })

  it('shows the review status as a dot, named for a screen reader, with no column of its own', async () => {
    fills = [makeFill({ review_status: 'pending_review' })]
    renderPage()
    expect(await screen.findByRole('img', { name: 'Pending review' })).toBeInTheDocument()
    expect(screen.queryByRole('columnheader', { name: 'Review' })).not.toBeInTheDocument()
  })

  it('shows a retail price only on a fill that has one', async () => {
    fills = [makeFill({ retail_cents: null })]
    renderPage()
    const table = await findLoadedFills()
    await within(table).findByText('Example Pharmacy')
    expect(within(table).queryByText(/^retail /)).not.toBeInTheDocument()
  })

  it('lists several ingredients and their strengths semicolon-delimited, in the same order', async () => {
    fills = [makeFill({
      drug: {
        ...DRUG,
        substance_names: ['amoxicillin', 'clavulanate potassium'],
        strengths: ['600 mg/5 mL', '42.9 mg/5 mL'],
        dosage_form: 'powder, for suspension',
      },
    })]
    renderPage()
    const table = await findLoadedFills()
    const cells = within(within(table).getAllByRole('row')[1]!).getAllByRole('cell')
    expect(cells[1]).toHaveTextContent('amoxicillin; clavulanate potassium')
    expect(cells[1]).toHaveTextContent('600 mg/5 mL; 42.9 mg/5 mL')
  })

  it('shows a dash for missing values', async () => {
    renderPage()
    const table = await findLoadedFills()
    const row = within(table).getAllByRole('row')[2]!
    const cells = within(row).getAllByRole('cell')
    expect(cells[0]).toHaveTextContent('2025-03-04')
    for (const index of [3, 4]) {
      expect(cells[index]).toHaveTextContent(/^—$/)
    }
  })

  it('opens a row in the detail, and leaves it alone when its menu is used', async () => {
    const onDrill = jest.fn()
    render(<MedicationFillsPage patientId={42} onDrill={onDrill} />)
    const table = await findLoadedFills()
    fireEvent.click(await within(table).findByText('atorvastatin calcium'))
    expect(onDrill).toHaveBeenCalledWith({ id: 'medication-fill-detail', instance: '1' })

    onDrill.mockClear()
    await pickFromRowMenu('atorvastatin calcium fill 2026-10-28', 'Delete')
    expect(onDrill).not.toHaveBeenCalled()
    expect(await screen.findByText(/This cannot be undone/)).toBeInTheDocument()
  })

  it('opens filtered to one medication from the link on its detail', async () => {
    window.history.pushState(null, '', '/phr/patient/42/medication-fills?medication_id=9')
    try {
      renderPage()
      expect(await screen.findByText('2025-03-04')).toBeInTheDocument()
      expect(screen.queryByText('2026-10-28')).not.toBeInTheDocument()
      fireEvent.click(await screen.findByRole('button', { name: 'Show all fills, not only Mystery' }))
      expect(screen.getByText('2026-10-28')).toBeInTheDocument()
    } finally {
      window.history.pushState(null, '', '/')
    }
  })

  it('refreshes the list when the detail beside it changes a fill', async () => {
    renderPage()
    await screen.findByRole('table', { name: 'Fills' })
    const before = mockGet.mock.calls.length
    fills = [makeFill({ pharmacy_name: 'Moved Pharmacy' })]
    act(() => recordChanged({ resource: 'medication-fills', patientId: 42, recordId: 1, origin: 'detail' }))
    expect(await screen.findByText('Moved Pharmacy')).toBeInTheDocument()
    expect(mockGet.mock.calls.length).toBeGreaterThan(before)
  })

  it('renders the server-derived display values and applies no lowercase class', async () => {
    fills = [makeFill({
      pharmacy_name: 'CVS PHARMACY #1234',
      prescriber_name: 'SMITH, JOHN A MD',
      display: { drug_as_printed: 'atorvastatin 10 mg', pharmacy_name: 'CVS Pharmacy #1234', pharmacy_location: null, prescriber_name: 'Smith, John A MD', directions: null },
    })]
    renderPage()
    const table = await findLoadedFills()
    expect(within(table).getByText('CVS Pharmacy #1234')).toBeInTheDocument()
    expect(within(table).getByText('Smith, John A MD')).toBeInTheDocument()
    expect(within(table).queryByText('CVS PHARMACY #1234')).toBeNull()
    expect(document.querySelector('.lowercase')).toBeNull()
  })

  it('shows the derived name for a fill linked to a medication named from the printed text', async () => {
    fills = [makeFill({
      drug: null,
      medication: { id: 9, name: 'ATORVASTATIN 10MG TAB' },
      drug_as_printed: 'ATORVASTATIN 10MG TAB',
      display: { drug_as_printed: 'atorvastatin 10 mg tab', pharmacy_name: null, pharmacy_location: null, prescriber_name: null, directions: null },
    })]
    renderPage()
    const table = await findLoadedFills()
    expect(within(table).getByText('atorvastatin 10 mg tab')).toBeInTheDocument()
    expect(within(table).queryByText('ATORVASTATIN 10MG TAB')).toBeNull()
  })

  it('explains how fills arrive when there are none', async () => {
    fills = []
    renderPage()
    expect(await screen.findByText('No fills recorded.')).toBeInTheDocument()
    expect(screen.getByText(/add one by hand with its NDC/)).toHaveTextContent(/label.*receipt.*CSV.*MCP/)
  })

  it('searches anywhere in the name, ignoring case, and filters by dates', async () => {
    renderPage()
    await findLoadedFills()

    const search = screen.getByRole('searchbox', { name: 'Search by name' })
    fireEvent.change(search, { target: { value: 'VAST' } })
    expect(screen.getByText('2026-10-28')).toBeInTheDocument()
    expect(screen.queryByText('2025-03-04')).not.toBeInTheDocument()
    fireEvent.change(search, { target: { value: '' } })
    expect(screen.getByText('2025-03-04')).toBeInTheDocument()

    fireEvent.change(screen.getByLabelText('Filled from'), { target: { value: '2026-01-01' } })
    expect(screen.queryByText('2025-03-04')).not.toBeInTheDocument()
    expect(screen.getByText('2026-10-28')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Clear dates' }))
    expect(screen.getByText('2025-03-04')).toBeInTheDocument()

    fireEvent.change(screen.getByLabelText('Filled to'), { target: { value: '2024-12-31' } })
    expect(screen.getByText('No fills match these filters.')).toBeInTheDocument()
  })

  it('filters from a sheet where the row of filters has no room, with the same state', async () => {
    renderPage()
    await screen.findByRole('table', { name: 'Fills' })

    fireEvent.click(screen.getByRole('button', { name: 'Filter' }))
    const sheet = await screen.findByRole('dialog', { name: 'Filter fills' })
    fireEvent.change(within(sheet).getByLabelText('Filled to'), { target: { value: '2025-12-31' } })
    expect(screen.queryByText('2026-10-28')).not.toBeInTheDocument()

    fireEvent.click(within(sheet).getByRole('button', { name: 'Show 1 fill' }))
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    expect(screen.getByText('2025-03-04')).toBeInTheDocument()
  })

  it('records a review decision on a pending fill', async () => {
    fills = [makeFill({ review_status: 'pending_review' })]
    mockPatch.mockResolvedValue({ medication_fill: makeFill({ review_status: 'confirmed' }) })
    renderPage()

    expect(await screen.findByRole('img', { name: 'Pending review' })).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: /^Confirm/ }))

    await waitFor(() => expect(mockPatch).toHaveBeenCalledWith('/api/phr/patients/42/medication-fills/1/review', { review_status: 'confirmed' }))
  })

  it('badges a fill with an open possible-duplicate flag and no other', async () => {
    fills = [
      makeFill({ review_status: 'pending_review', review_flags: [{ code: 'possible_duplicate', message: 'May be fill 2', fill_id: 2 }] }),
      makeFill({ id: 2, filled_on: '2025-03-04', review_flags: [] }),
    ]
    renderPage()

    expect(await screen.findAllByText('Possible duplicate')).toHaveLength(1)
    const row = screen.getByText('2026-10-28').closest('tr') as HTMLElement
    expect(within(row).getByText('Possible duplicate')).toBeInTheDocument()
    expect(within(screen.getByText('2025-03-04').closest('tr') as HTMLElement).queryByText('Possible duplicate')).not.toBeInTheDocument()
  })
})

describe('Fill entry form', () => {
  async function openForm() {
    renderPage()
    fireEvent.click(await screen.findByRole('button', { name: /add fill/i }))
    return screen.getByLabelText(/^NDC/)
  }

  function fillRequired() {
    fireEvent.change(screen.getByLabelText(/^Fill date/), { target: { value: '2026-10-28' } })
    fireEvent.change(screen.getByLabelText(/^Quantity/), { target: { value: '90' } })
  }

  it('shows the drug preview for a resolved lookup and sends dollars as cents', async () => {
    lookups['00071-0155-23'] = { status: 'resolved', input: '00071015523', selected: candidate('0071-0155-23'), candidates: [candidate('0071-0155-23')] }
    mockPostRaw.mockResolvedValue(jsonResponse(201, { medication_fill: makeFill({ id: 3 }) }))
    const ndc = await openForm()

    fireEvent.change(ndc, { target: { value: '00071-0155-23' } })
    const preview = await screen.findByLabelText('Drug preview')
    expect(preview).toHaveTextContent('atorvastatin calcium')
    expect(preview).toHaveTextContent('Labeler 0071-0155-23')

    fillRequired()
    fireEvent.change(screen.getByLabelText('Unit'), { target: { value: 'tablet' } })
    fireEvent.change(screen.getByLabelText('Paid ($)'), { target: { value: '10.00' } })
    fireEvent.change(screen.getByLabelText('No insurance price ($)'), { target: { value: '189.99' } })
    fireEvent.change(within(screen.getByRole('dialog')).getByLabelText('Pharmacy'), { target: { value: 'Example Pharmacy' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save fill' }))

    await waitFor(() => expect(mockPostRaw).toHaveBeenCalledTimes(1))
    const [url, body] = mockPostRaw.mock.calls[0] as [string, Record<string, unknown>]
    expect(url).toBe('/api/phr/patients/42/medication-fills')
    expect(body).toMatchObject({
      ndc: '00071-0155-23',
      filled_on: '2026-10-28',
      quantity: '90',
      quantity_unit: 'tablet',
      paid_cents: 1000,
      retail_cents: 18999,
      pharmacy_name: 'Example Pharmacy',
      days_supply: null,
      medication_id: null,
    })
    expect(body).not.toHaveProperty('drug')
    expect(body).not.toHaveProperty('ndc_candidate')
    // The saved fill joins the table and the form closes.
    await waitFor(() => expect(screen.queryByLabelText(/^NDC/)).not.toBeInTheDocument())
  })

  it('looks up a 10-digit NDC typed without dashes', async () => {
    lookups['0071015523'] = { status: 'resolved', input: '0071015523', selected: candidate('0071-0155-23'), candidates: [candidate('0071-0155-23')] }
    const ndc = await openForm()
    fireEvent.change(ndc, { target: { value: '0071015523' } })
    await screen.findByLabelText('Drug preview')
    expect(mockGet).toHaveBeenCalledWith('/api/ndc/lookup?ndc=0071015523')
  })

  it('does not look up until the NDC has 10 or 11 digits', async () => {
    const ndc = await openForm()
    fireEvent.change(ndc, { target: { value: '0071-01' } })
    expect(mockGet.mock.calls.some(([url]) => String(url).startsWith('/api/ndc/lookup'))).toBe(false)
  })

  it('shows a Discontinued badge in the drug preview of a discontinued lookup, not of a listed one', async () => {
    lookups['00093-0150-10'] = { status: 'resolved', input: '00093015010', selected: candidate('0093-0150-10', { ndc_source: 'rxnav', discontinued: true }), candidates: [] }
    lookups['00071-0155-23'] = { status: 'resolved', input: '00071015523', selected: candidate('0071-0155-23', { ndc_source: 'openfda', discontinued: false }), candidates: [] }
    const ndc = await openForm()

    fireEvent.change(ndc, { target: { value: '00093-0150-10' } })
    expect(await within(await screen.findByLabelText('Drug preview')).findByText('Discontinued')).toBeInTheDocument()

    fireEvent.change(ndc, { target: { value: '00071-0155-23' } })
    await waitFor(() => expect(screen.getByLabelText('Drug preview')).toHaveTextContent('Labeler 0071-0155-23'))
    expect(screen.queryByText('Discontinued')).not.toBeInTheDocument()
  })

  it('marks a discontinued package in the candidate list', async () => {
    const a = candidate('60505-0134-0', { ndc_source: 'rxnav', discontinued: true })
    const b = candidate('60505-134-00')
    lookups['6050501340'] = { status: 'ambiguous', input: '6050501340', selected: null, candidates: [a, b] }
    const ndc = await openForm()

    fireEvent.change(ndc, { target: { value: '6050501340' } })
    expect(await screen.findByText('NDC 60505-0134-0')).toBeInTheDocument()
    expect(screen.getAllByText('Discontinued')).toHaveLength(1)
  })

  it('offers a candidate picker for an ambiguous NDC and sends the chosen package', async () => {
    const a = candidate('60505-0134-0', { match_reasons: ['package NDC exact (5-4-1)'] })
    const b = candidate('60505-134-00', { match_reasons: ['package NDC exact (5-3-2)'] })
    lookups['6050501340'] = { status: 'ambiguous', input: '6050501340', selected: null, candidates: [a, b] }
    mockPostRaw.mockResolvedValue(jsonResponse(201, { medication_fill: makeFill({ id: 4 }) }))
    const ndc = await openForm()

    fireEvent.change(ndc, { target: { value: '6050501340' } })
    expect(await screen.findByText('NDC 60505-0134-0')).toBeInTheDocument()
    expect(screen.getByText('NDC 60505-134-00')).toBeInTheDocument()
    expect(screen.getByText('pkg for 60505-0134-0')).toBeInTheDocument()
    expect(screen.getByText('Labeler 60505-134-00')).toBeInTheDocument()
    expect(screen.getByText(/package NDC exact \(5-3-2\)/)).toBeInTheDocument()
    expect(screen.queryByLabelText('Drug preview')).not.toBeInTheDocument()

    // Saving before choosing is refused here, without a request.
    fillRequired()
    fireEvent.click(screen.getByRole('button', { name: 'Save fill' }))
    expect(await screen.findByText('Choose one of the matching packages')).toBeInTheDocument()
    expect(mockPostRaw).not.toHaveBeenCalled()

    fireEvent.click(screen.getByRole('radio', { name: /60505-134-00/ }))
    expect(screen.getByLabelText('Drug preview')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Save fill' }))

    await waitFor(() => expect(mockPostRaw).toHaveBeenCalledTimes(1))
    expect(mockPostRaw.mock.calls[0]![1]).toMatchObject({ ndc: '6050501340', ndc_candidate: '60505-134-00' })
  })

  it('explains an unresolved NDC, searches by name and can save unresolved', async () => {
    lookups['99999-9999-99'] = { status: 'unresolved', input: '99999999999', selected: null, candidates: [] }
    lookups['search'] = { candidates: [candidate('0071-0155-23')] }
    mockPostRaw.mockResolvedValue(jsonResponse(201, { medication_fill: makeFill({ id: 5, ndc_status: 'unresolved' }) }))
    const ndc = await openForm()

    fireEvent.change(ndc, { target: { value: '99999-9999-99' } })
    expect(await screen.findByText('No FDA listing matches this NDC.')).toBeInTheDocument()

    // Without choosing to save unresolved, the form stays put.
    fillRequired()
    fireEvent.click(screen.getByRole('button', { name: 'Save fill' }))
    expect(await screen.findByText(/Search by name, or choose to save it without a match/)).toBeInTheDocument()
    expect(mockPostRaw).not.toHaveBeenCalled()

    // Search by name.
    fireEvent.click(screen.getByRole('button', { name: 'Search by name' }))
    fireEvent.change(screen.getByLabelText('Drug name'), { target: { value: 'atorvastatin' } })
    fireEvent.change(screen.getByLabelText('Strength'), { target: { value: '20 mg' } })
    fireEvent.change(screen.getByLabelText('Dosage form'), { target: { value: 'tablet' } })
    fireEvent.change(screen.getByLabelText('Package'), { target: { value: '90' } })
    fireEvent.click(screen.getByRole('button', { name: 'Search FDA listings' }))
    expect(await screen.findByText('NDC 0071-0155-23')).toBeInTheDocument()
    const searchUrl = mockGet.mock.calls.map(([url]) => String(url)).find((url) => url.startsWith('/api/ndc/search'))!
    expect(new URL(searchUrl, 'http://x').searchParams.toString()).toBe('name=atorvastatin&strength=20+mg&dosage_form=tablet&package=90')

    // Save unresolved instead.
    fireEvent.click(screen.getByRole('checkbox', { name: 'Save without an FDA match' }))
    fireEvent.change(screen.getByLabelText(/^Drug name as printed/), { target: { value: 'mystery tab' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save fill' }))

    await waitFor(() => expect(mockPostRaw).toHaveBeenCalledTimes(1))
    expect(mockPostRaw.mock.calls[0]![1]).toMatchObject({ ndc: '99999-9999-99', allow_unresolved: true, drug_as_printed: 'mystery tab' })
  })

  it('picking a search result re-runs the lookup with its NDC', async () => {
    lookups['99999-9999-99'] = { status: 'unresolved', input: '99999999999', selected: null, candidates: [] }
    lookups['0071-0155-23'] = { status: 'resolved', input: '0071015523', selected: candidate('0071-0155-23'), candidates: [candidate('0071-0155-23')] }
    lookups['search'] = { candidates: [candidate('0071-0155-23')] }
    const ndc = await openForm()
    fireEvent.change(ndc, { target: { value: '99999-9999-99' } })
    fireEvent.click(await screen.findByRole('button', { name: 'Search by name' }))
    fireEvent.change(screen.getByLabelText('Drug name'), { target: { value: 'atorvastatin' } })
    fireEvent.click(screen.getByRole('button', { name: 'Search FDA listings' }))
    fireEvent.click(await screen.findByRole('button', { name: 'Use NDC 0071-0155-23' }))

    expect(await screen.findByLabelText('Drug preview')).toBeInTheDocument()
    expect(screen.getByLabelText(/^NDC/)).toHaveValue('0071-0155-23')
  })

  it('shows 422 errors next to their fields', async () => {
    lookups['00071-0155-23'] = { status: 'resolved', input: '00071015523', selected: candidate('0071-0155-23'), candidates: [candidate('0071-0155-23')] }
    mockPostRaw.mockResolvedValue(jsonResponse(422, {
      message: 'The given data was invalid.',
      errors: { quantity: ['The quantity must be greater than 0.'], paid_cents: ['The paid cents field must be at least 0.'] },
    }))
    const ndc = await openForm()
    fireEvent.change(ndc, { target: { value: '00071-0155-23' } })
    await screen.findByLabelText('Drug preview')
    fillRequired()
    fireEvent.click(screen.getByRole('button', { name: 'Save fill' }))

    const quantityError = await screen.findByText('The quantity must be greater than 0.')
    expect(quantityError.closest('label')).toHaveTextContent('Quantity')
    expect(screen.getByText('The paid cents field must be at least 0.').closest('label')).toHaveTextContent('Paid ($)')
    expect(screen.getByText('The given data was invalid.')).toBeInTheDocument()
    // The form stays open for correction.
    expect(screen.getByLabelText(/^NDC/)).toBeInTheDocument()
  })

  it('rejects a dollar amount that is not money before sending', async () => {
    lookups['00071-0155-23'] = { status: 'resolved', input: '00071015523', selected: candidate('0071-0155-23'), candidates: [candidate('0071-0155-23')] }
    const ndc = await openForm()
    fireEvent.change(ndc, { target: { value: '00071-0155-23' } })
    await screen.findByLabelText('Drug preview')
    fillRequired()
    fireEvent.change(screen.getByLabelText('Paid ($)'), { target: { value: 'ten' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save fill' }))

    expect(await screen.findByText('Enter a dollar amount such as 18.99.')).toBeInTheDocument()
    expect(mockPostRaw).not.toHaveBeenCalled()
  })
})

describe('MedicationFillHistory', () => {
  it('lists only what differs between fills, each linking to its record', async () => {
    fills = [makeFill({ review_status: 'pending_review' })]
    render(<MedicationFillHistory patientId={42} medicationId={7} />)

    const table = await screen.findByRole('table', { name: 'Fill history' })
    expect(mockGet).toHaveBeenCalledWith('/api/phr/patients/42/medications/7/fills')
    expect(within(table).getAllByRole('columnheader').map((header) => header.textContent)).toEqual(['Date', 'Qty', 'Paid', 'Pharmacy'])
    expect(await within(table).findByRole('link', { name: 'atorvastatin calcium fill 2026-10-28' })).toHaveAttribute('href', '/phr/patient/42/medication-fills/1')
    expect(within(table).getByRole('img', { name: 'Pending review' })).toBeInTheDocument()
    expect(screen.getByText('1 fill · last 2026-10-28 · 1 waiting for review')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Show these fills' })).toHaveAttribute('href', '/phr/patient/42/medication-fills?medication_id=7')
    expect(screen.queryByText('2025-03-04')).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /delete|edit/i })).not.toBeInTheDocument()
  })

  it('lists the five newest, and says how many there are', async () => {
    const many = Array.from({ length: 7 }, (_, index) => makeFill({ id: index + 1, filled_on: `2026-10-0${7 - index}` }))
    mockGet.mockResolvedValue({ medication_fills: many, can_manage: true })
    render(<MedicationFillHistory patientId={42} medicationId={7} />)

    const table = await screen.findByRole('table', { name: 'Fill history' })
    await screen.findByText('7 fills · last 2026-10-07')
    expect(within(table).getAllByRole('row')).toHaveLength(6)
    expect(screen.getByText('7 fills · last 2026-10-07')).toBeInTheDocument()
  })

  it('says so when a medication has no fills', async () => {
    mockGet.mockResolvedValue({ medication_fills: [], can_manage: true })
    render(<MedicationFillHistory patientId={42} medicationId={7} />)
    expect(await screen.findByText('No fills recorded for this medication.')).toBeInTheDocument()
    expect(screen.queryByRole('link', { name: 'Open in Fills' })).not.toBeInTheDocument()
  })
})

describe('Fill detail', () => {
  function renderDetail(onDrill = jest.fn()) {
    render(<MedicationFillDetail patientId={42} recordId="1" onDrill={onDrill} />)
    return onDrill
  }

  beforeEach(() => {
    mockGet.mockImplementation(async (url: string) => {
      if (url.endsWith('/drug-detail')) return lookups['detail']
      if (url.startsWith('/api/ndc/label')) return lookups['label']
      if (url === '/api/phr/patients/42/medication-fills/1') return { medication_fill: fills[0], can_manage: canManage }
      return {}
    })
  })

  it('shows the fill\'s own facts and the drug, leaving manufacturer and package to the drug dialog', async () => {
    fills = [makeFill({ source_kind: 'receipt' })]
    renderDetail()

    expect(await screen.findByRole('heading', { name: 'atorvastatin calcium 20 mg/1' })).toBeInTheDocument()
    expect(screen.getByText('Filled 2026-10-28')).toBeInTheDocument()
    for (const value of ['Dr. A. Smith', '90 tablet', '$189.99', 'No insurance', 'tablet, film coated', 'oral', 'Lipitor']) {
      expect(screen.getByText(value)).toBeInTheDocument()
    }
    expect(screen.queryByText('Parke-Davis Div of Pfizer Inc')).not.toBeInTheDocument()
    expect(screen.queryByText('90 tablet, film coated in 1 bottle (0071-0155-23)')).not.toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'atorvastatin calcium' })).toHaveAttribute('href', '/phr/patient/42/medications/7')
  })

  it('leaves out what was not recorded', async () => {
    fills = [makeFill({ rx_number: null, refills_remaining: null, days_supply: null })]
    renderDetail()
    await screen.findByRole('heading', { name: /atorvastatin/ })
    expect(screen.queryByText('Rx number')).not.toBeInTheDocument()
    expect(screen.queryByText('Refills left')).not.toBeInTheDocument()
    expect(screen.queryByText('Days supply')).not.toBeInTheDocument()
  })

  it('approves a pending fill, says so to the list, and the bar goes', async () => {
    fills = [makeFill({ review_status: 'pending_review' })]
    mockPatch.mockResolvedValue({ medication_fill: makeFill({ review_status: 'confirmed' }) })
    const changes = jest.fn()
    const listener = (event: Event) => changes((event as CustomEvent).detail)
    window.addEventListener('phr:record-changed', listener)
    renderDetail()

    fireEvent.click(await screen.findByRole('button', { name: 'Approve atorvastatin calcium fill 2026-10-28' }))
    await waitFor(() => expect(mockPatch).toHaveBeenCalledWith('/api/phr/patients/42/medication-fills/1/review', { review_status: 'confirmed' }))
    await waitFor(() => expect(screen.queryByTestId('record-review-bar')).not.toBeInTheDocument())
    expect(changes).toHaveBeenCalledWith({ resource: 'medication-fills', patientId: 42, recordId: 1, origin: 'detail' })
    window.removeEventListener('phr:record-changed', listener)
  })

  it('holds the bar at the bottom of the screen where the detail is its own page', async () => {
    fills = [makeFill({ review_status: 'pending_review' })]
    renderDetail()
    expect(await screen.findByTestId('record-review-bar')).toHaveClass('sticky', 'bottom-0', 'lg:static')
    expect(within(screen.getByTestId('record-review-bar')).getByRole('button', { name: /^Reject/ })).toHaveClass('max-lg:h-11')
  })

  it('offers nothing to someone who cannot change the record', async () => {
    canManage = false
    fills = [makeFill({ review_status: 'pending_review' })]
    renderDetail()
    await screen.findByRole('heading', { name: /atorvastatin/ })
    expect(screen.queryByTestId('record-review-bar')).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /^Edit/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /^More actions/ })).not.toBeInTheDocument()
  })

  it('reloads when the row beside it is decided', async () => {
    fills = [makeFill({ review_status: 'pending_review' })]
    renderDetail()
    await screen.findByTestId('record-review-bar')
    fills = [makeFill({ review_status: 'confirmed' })]
    act(() => recordChanged({ resource: 'medication-fills', patientId: 42, recordId: 1, origin: 'list' }))
    await waitFor(() => expect(screen.queryByTestId('record-review-bar')).not.toBeInTheDocument())
  })

  it('edits in the dialog and shows the saved fill', async () => {
    mockPatchRaw.mockResolvedValue(jsonResponse(200, { medication_fill: makeFill({ paid_cents: 1250 }) }))
    renderDetail()
    fireEvent.click(await screen.findByRole('button', { name: /^Edit atorvastatin calcium fill/ }))
    const dialog = await screen.findByRole('dialog', { name: 'Edit Fill' })
    fireEvent.change(within(dialog).getByLabelText('Paid ($)'), { target: { value: '12.50' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save changes' }))
    expect(await screen.findByText('$12.50')).toBeInTheDocument()
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
  })

  it('deletes after asking, and goes back to the list', async () => {
    mockDelete.mockResolvedValue({})
    const onDrill = renderDetail()
    await screen.findByRole('heading', { name: /atorvastatin/ })
    await pickFromRowMenu('atorvastatin calcium fill 2026-10-28', 'Delete')
    const confirm = await screen.findByRole('alertdialog')
    expect(mockDelete).not.toHaveBeenCalled()
    fireEvent.click(within(confirm).getByRole('button', { name: 'Delete' }))
    await waitFor(() => expect(mockDelete).toHaveBeenCalledWith('/api/phr/patients/42/medication-fills/1', {}))
    await waitFor(() => expect(onDrill).toHaveBeenCalledWith({ id: 'medication-fills' }))
  })

  it('opens the drug\'s label and links', async () => {
    lookups['detail'] = DETAIL
    lookups['label'] = { status: 'found', spl_set_id: DETAIL.drug_detail.spl_set_id, effective_date: '2026-05-21', version: '3', sections: [{ key: 'indications_and_usage', title: 'Indications and usage', text: 'For high cholesterol.' }] }
    renderDetail()
    fireEvent.click(await screen.findByRole('button', { name: 'Prescribing information' }))

    const panel = await screen.findByRole('dialog')
    expect(await within(panel).findByText('NDA020702')).toBeInTheDocument()
    expect(within(panel).getByText('HMG-CoA Reductase Inhibitor [EPC]')).toBeInTheDocument()
    expect(within(panel).getByText(/atorvastatin calcium trihydrate 20 mg\/1/)).toBeInTheDocument()
    expect(await within(panel).findByText('Indications and usage')).toBeInTheDocument()
    expect(within(panel).getByText(/effective 2026-05-21/)).toBeInTheDocument()
    for (const name of ['DailyMed', 'FDA NDC listing']) {
      const link = within(panel).getByRole('link', { name })
      expect(link).toHaveAttribute('target', '_blank')
      expect(link).toHaveAttribute('rel', 'noopener noreferrer')
    }
    expect(mockGet).toHaveBeenCalledWith('/api/ndc/label?ndc_10=0071-0155-23')
  })

  it('shows RxNav extras for an rxnav listing, without the Discontinued badge, and says so when there is no label', async () => {
    fills = [makeFill({ ndc_source: 'rxnav', discontinued: true })]
    lookups['detail'] = { drug_detail: { ...DETAIL.drug_detail, ndc_source: 'rxnav', discontinued: true, dea_schedule: 'CIV', imprint: '3;TV;150' } }
    lookups['label'] = { status: 'not_found', sections: [] }
    renderDetail()
    fireEvent.click(await screen.findByRole('button', { name: 'Prescribing information' }))

    const panel = await screen.findByRole('dialog')
    expect(within(panel).queryByText('Discontinued')).not.toBeInTheDocument()
    expect(await within(panel).findByText('CIV')).toBeInTheDocument()
    expect(within(panel).getByText('3;TV;150')).toBeInTheDocument()
    expect(await within(panel).findByText(/No FDA label is on file/)).toBeInTheDocument()
  })

  it('explains an unresolved fill without fetching anything', async () => {
    fills = [makeFill({ ndc_status: 'unresolved', ndc_10: null, drug: null, drug_as_printed: 'mystery tab', medication: null })]
    renderDetail()
    expect(await screen.findByText(/No FDA listing is stored for this fill/)).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Prescribing information' }))

    const panel = await screen.findByRole('dialog')
    expect(within(panel).getByText(/No FDA listing is stored for this fill/)).toBeInTheDocument()
    expect(mockGet).not.toHaveBeenCalledWith(expect.stringContaining('/api/ndc/label'))
  })

  it('names the pharmacy and folds its details and source documents away', async () => {
    fills = [makeFill({ rx_number: 'RX123', refills_remaining: 2, source_kind: 'receipt', source_document_id: 5, evidence_document_ids: [6], pharmacy_location: '#1234, Seattle WA', display: { pharmacy_name: 'Example Pharmacy', pharmacy_location: '#1234, Seattle WA', prescriber_name: 'Dr. A. Smith', directions: null } })]
    renderDetail()

    expect(await screen.findByText('Example Pharmacy')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Example Pharmacy' })).not.toBeInTheDocument()
    const more = screen.getByText('More details').closest('details') as HTMLDetailsElement
    expect(more.open).toBe(false)
    expect(within(more).getByText('#1234, Seattle WA')).toBeInTheDocument()
    expect(within(more).getByText('RX123')).toBeInTheDocument()
    expect(within(more).getByText('2')).toBeInTheDocument()
    expect(within(more).getByText('Source document 5')).toHaveAttribute('href', '/phr/patient/42/documents/5')
    expect(within(more).getByText('Source document 6')).toBeInTheDocument()
  })
})

describe('Fill entry without an NDC', () => {
  it('saves a manual fill with only the drug name as printed', async () => {
    mockPostRaw.mockResolvedValue(jsonResponse(201, { medication_fill: makeFill({ id: 6, ndc_status: 'manual' }) }))
    renderPage()
    fireEvent.click(await screen.findByRole('button', { name: /add fill/i }))
    fireEvent.click(screen.getByRole('button', { name: 'Save fill' }))
    expect(await screen.findByText('Enter an NDC, or the drug name as printed.')).toBeInTheDocument()

    fireEvent.change(screen.getByLabelText(/^Drug name as printed/), { target: { value: 'aspirin 81mg' } })
    fireEvent.change(screen.getByLabelText(/^Fill date/), { target: { value: '2026-10-28' } })
    fireEvent.change(screen.getByLabelText(/^Quantity/), { target: { value: '30' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save fill' }))

    await waitFor(() => expect(mockPostRaw).toHaveBeenCalledTimes(1))
    const body = mockPostRaw.mock.calls[0]![1] as Record<string, unknown>
    expect(body).not.toHaveProperty('ndc')
    expect(body).toMatchObject({ drug_as_printed: 'aspirin 81mg' })
  })
})

describe('Editing a fill', () => {
  async function openEdit() {
    renderPage()
    await screen.findByRole('table', { name: 'Fills' })
    await pickFromRowMenu('atorvastatin calcium fill 2026-10-28', 'Edit')
    return screen.findByRole('button', { name: 'Save changes' })
  }

  it('prefills the form from the fill and keeps the stored drug', async () => {
    await openEdit()

    expect(screen.getByText('Edit Fill')).toBeInTheDocument()
    expect(screen.getByLabelText(/^Fill date/)).toHaveValue('2026-10-28')
    expect(screen.getByLabelText(/^Quantity/)).toHaveValue('90.000')
    expect(screen.getByLabelText('Unit')).toHaveValue('tablet')
    expect(screen.getByLabelText('Paid ($)')).toHaveValue('10.00')
    expect(screen.getByLabelText('No insurance price ($)')).toHaveValue('189.99')
    expect(within(screen.getByRole('dialog')).getByLabelText('Pharmacy')).toHaveValue('Example Pharmacy')
    expect(screen.getByLabelText('Days supply')).toHaveValue('90')
    expect(screen.getByLabelText(/^NDC/)).toHaveValue('0071-0155-23')
    expect(screen.getByLabelText('Drug preview')).toBeInTheDocument()
    // Unchanged NDC: no lookup.
    expect(mockGet.mock.calls.some(([url]) => String(url).startsWith('/api/ndc/lookup'))).toBe(false)
  })

  it('PATCHes the changes, with the version, without re-sending the unchanged NDC', async () => {
    fills = [makeFill({ version: 'v1' })]
    mockPatchRaw.mockResolvedValue(jsonResponse(200, { medication_fill: makeFill({ version: 'v2', paid_cents: 1250 }) }))
    await openEdit()

    fireEvent.change(screen.getByLabelText('Paid ($)'), { target: { value: '12.50' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))

    await waitFor(() => expect(mockPatchRaw).toHaveBeenCalledTimes(1))
    const [url, body] = mockPatchRaw.mock.calls[0] as [string, Record<string, unknown>]
    expect(url).toBe('/api/phr/patients/42/medication-fills/1')
    expect(body).toMatchObject({ version: 'v1', paid_cents: 1250 })
    expect(body).not.toHaveProperty('ndc')
    // The medication link is the server's to keep unless the person picks another.
    expect(body).not.toHaveProperty('medication_id')
    expect(await screen.findByText('$12.50')).toBeInTheDocument()
    expect(screen.queryByText('Edit Fill')).not.toBeInTheDocument()
  })

  it('re-runs the lookup when the NDC changes and sends the new NDC', async () => {
    lookups['00002-0800-01'] = { status: 'resolved', input: '00002080001', selected: candidate('0002-0800-01'), candidates: [candidate('0002-0800-01')] }
    mockPatchRaw.mockResolvedValue(jsonResponse(200, { medication_fill: makeFill() }))
    await openEdit()

    fireEvent.change(screen.getByLabelText(/^NDC/), { target: { value: '00002-0800-01' } })
    await waitFor(() => expect(mockGet).toHaveBeenCalledWith('/api/ndc/lookup?ndc=00002-0800-01'))
    expect(await screen.findByText(/NDC 0002-0800-01/)).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))

    await waitFor(() => expect(mockPatchRaw).toHaveBeenCalledTimes(1))
    expect(mockPatchRaw.mock.calls[0]![1]).toMatchObject({ ndc: '00002-0800-01' })
  })

  it('shows a conflict message instead of overwriting, and reloads the latest', async () => {
    mockPatchRaw.mockResolvedValue(jsonResponse(409, { message: 'This record changed since it was loaded.' }))
    await openEdit()
    fireEvent.change(within(screen.getByRole('dialog')).getByLabelText('Pharmacy'), { target: { value: 'Other Pharmacy' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))

    expect(await screen.findByText('This fill changed since you opened it. Reload to see the latest')).toBeInTheDocument()
    // The row was not overwritten.
    expect(screen.queryByText('Other Pharmacy')).not.toBeInTheDocument()

    fills = [makeFill({ pharmacy_name: 'Latest Pharmacy' })]
    fireEvent.click(screen.getByRole('button', { name: 'Reload' }))
    await waitFor(() => expect(within(screen.getByRole('dialog')).getByLabelText('Pharmacy')).toHaveValue('Latest Pharmacy'))
    expect(screen.queryByText('This fill changed since you opened it. Reload to see the latest')).not.toBeInTheDocument()
  })
})

describe('Medication select', () => {
  it('sends medication_id for a chosen medication and null for automatic matching', async () => {
    lookups['00071-0155-23'] = { status: 'resolved', input: '00071015523', selected: candidate('0071-0155-23'), candidates: [candidate('0071-0155-23')] }
    mockPostRaw.mockResolvedValue(jsonResponse(201, { medication_fill: makeFill({ id: 8 }) }))
    renderPage()
    fireEvent.click(await screen.findByRole('button', { name: /add fill/i }))
    fireEvent.change(screen.getByLabelText(/^NDC/), { target: { value: '00071-0155-23' } })
    await screen.findByLabelText('Drug preview')
    fireEvent.change(screen.getByLabelText(/^Fill date/), { target: { value: '2026-10-28' } })
    fireEvent.change(screen.getByLabelText(/^Quantity/), { target: { value: '90' } })

    const select = await screen.findByRole('combobox', { name: 'Medication' })
    expect(within(select).getByRole('option', { name: 'Match or create automatically' })).toBeInTheDocument()
    expect(select).toHaveValue('')
    await waitFor(() => expect(within(screen.getByRole('combobox', { name: 'Medication' })).getByRole('option', { name: 'Mystery' })).toBeInTheDocument())

    fireEvent.click(screen.getByRole('button', { name: 'Save fill' }))
    await waitFor(() => expect(mockPostRaw).toHaveBeenCalledTimes(1))
    expect(mockPostRaw.mock.calls[0]![1]).toMatchObject({ medication_id: null })

    fireEvent.click(await screen.findByRole('button', { name: /add fill/i }))
    fireEvent.change(screen.getByLabelText(/^NDC/), { target: { value: '00071-0155-23' } })
    await screen.findByLabelText('Drug preview')
    fireEvent.change(screen.getByLabelText(/^Fill date/), { target: { value: '2026-10-28' } })
    fireEvent.change(screen.getByLabelText(/^Quantity/), { target: { value: '90' } })
    await waitFor(() => expect(within(screen.getByRole('combobox', { name: 'Medication' })).getByRole('option', { name: 'Mystery' })).toBeInTheDocument())
    fireEvent.change(screen.getByRole('combobox', { name: 'Medication' }), { target: { value: '9' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save fill' }))
    await waitFor(() => expect(mockPostRaw).toHaveBeenCalledTimes(2))
    expect(mockPostRaw.mock.calls[1]![1]).toMatchObject({ medication_id: 9 })
  })
})

describe('Editing a fill: review fixes', () => {
  async function openEdit(name = 'atorvastatin calcium fill 2026-10-28') {
    renderPage()
    await screen.findByRole('table', { name: 'Fills' })
    await pickFromRowMenu(name, 'Edit')
    return screen.findByRole('button', { name: 'Save changes' })
  }

  it('sends medication_id only when the person changed the Medication select', async () => {
    fills = [makeFill({ version: 'v1' })]
    mockPatchRaw.mockResolvedValue(jsonResponse(200, { medication_fill: makeFill() }))
    await openEdit()

    await waitFor(() => expect(within(screen.getByRole('combobox', { name: 'Medication' })).getByRole('option', { name: 'Mystery' })).toBeInTheDocument())
    fireEvent.change(within(screen.getByRole('dialog')).getByLabelText('Pharmacy'), { target: { value: 'Other Pharmacy' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))
    await waitFor(() => expect(mockPatchRaw).toHaveBeenCalledTimes(1))
    expect(mockPatchRaw.mock.calls[0]![1]).not.toHaveProperty('medication_id')

    await screen.findByRole('table', { name: 'Fills' })
    await pickFromRowMenu('atorvastatin calcium fill 2026-10-28', 'Edit')
    await waitFor(() => expect(within(screen.getByRole('combobox', { name: 'Medication' })).getByRole('option', { name: 'Mystery' })).toBeInTheDocument())
    fireEvent.change(screen.getByRole('combobox', { name: 'Medication' }), { target: { value: '9' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))
    await waitFor(() => expect(mockPatchRaw).toHaveBeenCalledTimes(2))
    expect(mockPatchRaw.mock.calls[1]![1]).toMatchObject({ medication_id: 9 })
  })

  it('does not send medication_id when the NDC changes, so the server can re-link', async () => {
    lookups['00002-0800-01'] = { status: 'resolved', input: '00002080001', selected: candidate('0002-0800-01'), candidates: [candidate('0002-0800-01')] }
    mockPatchRaw.mockResolvedValue(jsonResponse(200, { medication_fill: makeFill() }))
    await openEdit()

    fireEvent.change(screen.getByLabelText(/^NDC/), { target: { value: '00002-0800-01' } })
    await screen.findByText(/NDC 0002-0800-01/)
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))

    await waitFor(() => expect(mockPatchRaw).toHaveBeenCalledTimes(1))
    const body = mockPatchRaw.mock.calls[0]![1] as Record<string, unknown>
    expect(body).toMatchObject({ ndc: '00002-0800-01' })
    expect(body).not.toHaveProperty('medication_id')
  })

  it('sends ndc: null when the person clears the NDC, and needs a drug name then', async () => {
    mockPatchRaw.mockResolvedValue(jsonResponse(200, { medication_fill: makeFill({ ndc_status: 'manual' }) }))
    await openEdit()

    fireEvent.change(screen.getByLabelText(/^NDC/), { target: { value: '' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))
    expect(await screen.findByText('Enter an NDC, or the drug name as printed.')).toBeInTheDocument()
    expect(mockPatchRaw).not.toHaveBeenCalled()

    fireEvent.change(screen.getByLabelText(/^Drug name as printed/), { target: { value: 'atorvastatin 20mg tab' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))
    await waitFor(() => expect(mockPatchRaw).toHaveBeenCalledTimes(1))
    const body = mockPatchRaw.mock.calls[0]![1] as Record<string, unknown>
    expect(body).toHaveProperty('ndc', null)
    expect(body).toMatchObject({ drug_as_printed: 'atorvastatin 20mg tab' })
  })

  it('accepts a stored NDC with a label and Unicode dashes without checking or re-sending it', async () => {
    fills = [makeFill({ ndc_as_captured: 'NDC: 0071\u20110155\u201123', version: 'v1' })]
    mockPatchRaw.mockResolvedValue(jsonResponse(200, { medication_fill: makeFill() }))
    await openEdit()

    fireEvent.change(within(screen.getByRole('dialog')).getByLabelText('Pharmacy'), { target: { value: 'Other Pharmacy' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))

    await waitFor(() => expect(mockPatchRaw).toHaveBeenCalledTimes(1))
    expect(screen.queryByText('Enter a 10 or 11 digit NDC.')).not.toBeInTheDocument()
    expect(mockPatchRaw.mock.calls[0]![1]).not.toHaveProperty('ndc')
    expect(mockGet.mock.calls.some(([url]) => String(url).startsWith('/api/ndc/lookup'))).toBe(false)
  })

  it('shows the candidate picker for an ambiguous stored fill even though the NDC is unchanged', async () => {
    const a = candidate('60505-0134-0')
    const b = candidate('60505-134-00')
    lookups['60505-0134-00'] = { status: 'ambiguous', input: '60505-0134-00', selected: null, candidates: [a, b] }
    fills = [makeFill({ id: 1, ndc_status: 'ambiguous', ndc_as_captured: '60505-0134-00', ndc_10: null, drug: null, drug_as_printed: 'cyclosporine 100mg', version: 'v1' })]
    mockPatchRaw.mockResolvedValue(jsonResponse(200, { medication_fill: makeFill() }))
    renderPage()
    expect(await screen.findByText('Ambiguous NDC')).toBeInTheDocument()
    fireEvent.click(await screen.findByRole('button', { name: /^More actions for .* fill 2026-10-28/ }))
    fireEvent.click(await screen.findByRole('menuitem', { name: 'Edit' }))

    expect(await screen.findByText('NDC 60505-0134-0')).toBeInTheDocument()
    expect(screen.getByText('NDC 60505-134-00')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('radio', { name: /60505-134-00/ }))
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))
    await waitFor(() => expect(mockPatchRaw).toHaveBeenCalledTimes(1))
    expect(mockPatchRaw.mock.calls[0]![1]).toMatchObject({ ndc: '60505-0134-00', ndc_candidate: '60505-134-00' })
  })

  it('marks an ambiguous fill in the table beside the no-match badge', async () => {
    fills = [makeFill({ ndc_status: 'ambiguous', drug: null, drug_as_printed: 'x' }), makeFill({ id: 2, ndc_status: 'unresolved', drug: null, drug_as_printed: 'y' })]
    renderPage()

    expect(await screen.findByText('Ambiguous NDC')).toBeInTheDocument()
    expect(screen.getByText('No FDA match')).toBeInTheDocument()
  })

  it('keeps the Discontinued badge off the list', async () => {
    fills = [makeFill({ id: 1, ndc_source: 'rxnav', discontinued: true })]
    renderPage()
    await findLoadedFills()
    expect(screen.queryByText('Discontinued')).not.toBeInTheDocument()
  })

  it('says only the medication\'s detail that the product on its latest fill is off the market', async () => {
    fills = [makeFill({ id: 1, ndc_source: 'rxnav', discontinued: true })]
    const { unmount } = render(<MedicationFillHistory patientId={42} medicationId={7} />)
    expect(await screen.findByText(/^Product no longer listed/)).toBeInTheDocument()
    unmount()

    fills = [makeFill({ id: 2, discontinued: false }), makeFill({ id: 1, filled_on: '2020-01-01', ndc_source: 'rxnav', discontinued: true })]
    render(<MedicationFillHistory patientId={42} medicationId={7} />)
    await screen.findByRole('link', { name: 'atorvastatin calcium fill 2026-10-28' })
    expect(screen.queryByText(/^Product no longer listed/)).not.toBeInTheDocument()
  })

  it('shows the NDC as read from its source only in the edit form, when it differs', async () => {
    fills = [makeFill({ ndc_as_captured: '00071015523' })]
    const detail = render(<MedicationFillDetail patientId={42} recordId="1" />)
    fireEvent.click(await screen.findByRole('button', { name: 'Prescribing information' }))
    expect(await screen.findByRole('dialog')).toBeInTheDocument()
    expect(screen.queryByText(/00071015523/)).not.toBeInTheDocument()
    detail.unmount()

    await openEdit()
    expect(await screen.findByText('Read from its source as 00071015523.')).toBeInTheDocument()
  })

  it('saves which columns show for this person, and applies it at once', async () => {
    renderPage()
    const table = await findLoadedFills()
    expect(within(table).getByRole('columnheader', { name: 'Pharmacy and prescriber' })).toBeInTheDocument()
    fireEvent.click(screen.getByRole('checkbox', { name: 'Prescriber' }))
    expect(within(table).getByRole('columnheader', { name: 'Pharmacy' })).toBeInTheDocument()
    expect(within(table).queryByText('Dr. A. Smith')).not.toBeInTheDocument()
    expect(mockPut).toHaveBeenCalledWith('/api/user/display-preferences', { hidden_fill_columns: ['prescriber'] })

    fireEvent.click(screen.getByRole('checkbox', { name: 'Prescriber' }))
    expect(within(table).getByRole('columnheader', { name: 'Pharmacy and prescriber' })).toBeInTheDocument()
    expect(mockPut).toHaveBeenLastCalledWith('/api/user/display-preferences', { hidden_fill_columns: [] })
    await act(async () => { await Promise.resolve() })
  })

  it('keeps No FDA match for an unresolved fill, with no Discontinued badge', async () => {
    fills = [makeFill({ ndc_status: 'unresolved', drug: null, drug_as_printed: 'y', discontinued: false, ndc_source: null })]
    renderPage()

    expect(await screen.findByText('No FDA match')).toBeInTheDocument()
    expect(screen.queryByText('Discontinued')).not.toBeInTheDocument()
  })
})

describe('Fill entry lookups', () => {
  it('waits for typing to pause and looks each normalised NDC up only once', async () => {
    lookups['00071-0155-23'] = { status: 'resolved', input: '00071015523', selected: candidate('0071-0155-23'), candidates: [candidate('0071-0155-23')] }
    renderPage()
    fireEvent.click(await screen.findByRole('button', { name: /add fill/i }))
    const ndc = screen.getByLabelText(/^NDC/)
    const lookupCalls = () => mockGet.mock.calls.filter(([url]) => String(url).startsWith('/api/ndc/lookup')).length

    // Typing through valid lengths does not fire per keystroke.
    for (const typed of ['0007101552', '00071015523', 'NDC: 00071\u20110155\u201123']) fireEvent.change(ndc, { target: { value: typed } })
    expect(lookupCalls()).toBe(0)
    await waitFor(() => expect(lookupCalls()).toBe(1))
    expect(mockGet).toHaveBeenCalledWith('/api/ndc/lookup?ndc=00071-0155-23')

    // Retyping the same NDC another way, or coming back to it, does not ask again.
    fireEvent.change(ndc, { target: { value: '' } })
    fireEvent.change(ndc, { target: { value: '00071-0155-23' } })
    await screen.findByLabelText('Drug preview')
    expect(lookupCalls()).toBe(1)
  })
})

const DETAIL = {
  drug_detail: {
    ndc_source: 'openfda',
    discontinued: false,
    ndc_10: '0071-0155-23',
    ingredients: [{ name: 'atorvastatin calcium trihydrate', strength: '20 mg/1' }],
    dosage_form: 'tablet, film coated',
    route: 'oral',
    pharm_class: ['HMG-CoA Reductase Inhibitor [EPC]'],
    application_number: 'NDA020702',
    marketing_category: 'NDA',
    manufacturer: 'Parke-Davis Div of Pfizer Inc',
    package_description: '90 tablet in 1 bottle',
    dea_schedule: null,
    imprint: null,
    spl_set_id: '52561b3e-c20f-3536-e063-6394a90a6198',
    links: { dailymed: 'https://dailymed.nlm.nih.gov/dailymed/lookup.cfm?setid=52561b3e-c20f-3536-e063-6394a90a6198', ndc_directory: 'https://api.fda.gov/drug/ndc.json?search=product_ndc%3A%220071-0155%22' },
  },
}

describe('Fill dialog', () => {
  it('opens Add Fill in a dialog with the NDC focused, and closes without asking when nothing changed', async () => {
    renderPage()
    fireEvent.click(await screen.findByRole('button', { name: /add fill/i }))

    const dialog = await screen.findByRole('dialog', { name: 'Add Fill' })
    await waitFor(() => expect(within(dialog).getByLabelText(/^NDC/)).toHaveFocus())
    fireEvent.click(within(dialog).getByRole('button', { name: 'Cancel' }))
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
  })

  it('asks before discarding unsaved edits, and keeps the dialog open on Keep editing', async () => {
    renderPage()
    fireEvent.click(await screen.findByRole('button', { name: /add fill/i }))
    const dialog = await screen.findByRole('dialog', { name: 'Add Fill' })
    fireEvent.change(within(dialog).getByLabelText(/^Quantity/), { target: { value: '30' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Cancel' }))

    expect(await screen.findByText('Discard your changes?')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Keep editing' }))
    await waitFor(() => expect(screen.queryByText('Discard your changes?')).not.toBeInTheDocument())
    expect(screen.getByRole('dialog', { name: 'Add Fill' })).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }))
    fireEvent.click(await screen.findByRole('button', { name: 'Discard changes' }))
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
  })
})

describe('Fill dialog while saving', () => {
  it('does not close on Escape while a save is in flight', async () => {
    let finish: (value: unknown) => void = () => {}
    mockPatchRaw.mockReturnValue(new Promise((resolve) => { finish = resolve }))
    renderPage()
    await screen.findByRole('table', { name: 'Fills' })
    await pickFromRowMenu('atorvastatin calcium fill 2026-10-28', 'Edit')
    const dialog = await screen.findByRole('dialog', { name: 'Edit Fill' })
    fireEvent.change(within(dialog).getByLabelText('Pharmacy'), { target: { value: 'Other Pharmacy' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save changes' }))
    await within(dialog).findByRole('button', { name: 'Saving…' })

    fireEvent.keyDown(dialog, { key: 'Escape' })
    expect(screen.getByRole('dialog', { name: 'Edit Fill' })).toBeInTheDocument()
    expect(screen.queryByText('Discard your changes?')).not.toBeInTheDocument()

    finish(jsonResponse(200, { medication_fill: makeFill({ pharmacy_name: 'Other Pharmacy' }) }))
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
  })
})
