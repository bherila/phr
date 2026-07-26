import { fireEvent, render, screen, within } from '@testing-library/react'

import type { MillerColumnSpec, MillerDrillTarget } from '@/components/ui/miller'

import { PhrDockHomeView } from './PhrDockHomeView'
import type { PhrModuleId } from './phrModuleRegistry'
import { phrModuleRegistry } from './phrModuleRegistry'

const PATIENT_ID = 123
const STORAGE_KEY = `phr-dock-prefs-patient-${PATIENT_ID}`
const originalLabsKeyAmounts = phrModuleRegistry.labs.keyAmounts

interface RenderDockOptions {
  patientId?: number | undefined
  replaceFrom?: (depth: number, column: MillerColumnSpec<PhrModuleId>) => void
  onDrill?: (target: MillerDrillTarget<PhrModuleId>) => void
}

function renderDock(options: RenderDockOptions = {}) {
  const patientId = Object.prototype.hasOwnProperty.call(options, 'patientId') ? options.patientId : PATIENT_ID
  const replaceFrom = options.replaceFrom ?? jest.fn((_: number, _column: MillerColumnSpec<PhrModuleId>) => {})
  const onDrill = options.onDrill
  const rendered = render(<PhrDockHomeView patientId={patientId} replaceFrom={replaceFrom} onDrill={onDrill} />)

  return { ...rendered, replaceFrom }
}

function cardFor(title: string): HTMLElement {
  return screen.getByText(title).closest('[data-slot="card"]') as HTMLElement
}

function launchLabelsIn(container: HTMLElement, labels: string[]): string[] {
  const expectedLabels = new Set(labels)

  return within(container)
    .getAllByRole('button')
    .map((button) => button.textContent?.replace(/\s+/g, ' ').trim() ?? '')
    .filter((label) => expectedLabels.has(label))
}

beforeEach(() => {
  window.localStorage.clear()
  delete phrModuleRegistry.labs.keyAmounts
})

afterAll(() => {
  if (originalLabsKeyAmounts === undefined) {
    delete phrModuleRegistry.labs.keyAmounts
    return
  }

  phrModuleRegistry.labs.keyAmounts = originalLabsKeyAmounts
})

describe('PhrDockHomeView', () => {
  it('does not render Pinned or Recent cards when prefs are empty', () => {
    renderDock()

    expect(screen.queryByText('Pinned')).not.toBeInTheDocument()
    expect(screen.queryByText('Recent')).not.toBeInTheDocument()
    expect(screen.getByText('Clinical')).toBeInTheDocument()
  })

  it('renders the Pinned card with stored entries', () => {
    window.localStorage.setItem(STORAGE_KEY, JSON.stringify({ pinned: ['medications'], recent: [] }))

    renderDock()

    const pinnedCard = cardFor('Pinned')
    expect(within(pinnedCard).getByText('Meds')).toBeInTheDocument()
    expect(within(pinnedCard).getByText('Medications')).toHaveClass('sr-only')
  })

  it('renders the Recent card and excludes pinned ids', () => {
    window.localStorage.setItem(
      STORAGE_KEY,
      JSON.stringify({ pinned: ['medications'], recent: ['medications', 'labs', 'vitals'] }),
    )

    renderDock()

    const recentCard = cardFor('Recent')
    expect(within(recentCard).getByRole('button', { name: 'Labs' })).toBeInTheDocument()
    expect(within(recentCard).getByRole('button', { name: 'Vitals' })).toBeInTheDocument()
    expect(within(recentCard).queryByText('Meds')).not.toBeInTheDocument()
  })

  it('keeps Recent order stable when opening an existing recent module', () => {
    window.localStorage.setItem(STORAGE_KEY, JSON.stringify({ pinned: [], recent: ['labs', 'vitals', 'summary'] }))
    const { replaceFrom } = renderDock()

    const recentCard = cardFor('Recent')
    expect(launchLabelsIn(recentCard, ['Labs', 'Vitals', 'Summary'])).toEqual(['Labs', 'Vitals', 'Summary'])

    fireEvent.click(within(recentCard).getByRole('button', { name: 'Vitals' }))

    expect(replaceFrom).toHaveBeenCalledWith(0, { id: 'vitals' })
    expect(launchLabelsIn(recentCard, ['Labs', 'Vitals', 'Summary'])).toEqual(['Labs', 'Vitals', 'Summary'])
    expect(JSON.parse(window.localStorage.getItem(STORAGE_KEY) ?? '{}')).toMatchObject({
      recent: ['labs', 'vitals', 'summary'],
    })
  })

  it('Clear button empties the Recent list for the selected patient', () => {
    window.localStorage.setItem(STORAGE_KEY, JSON.stringify({ pinned: [], recent: ['labs'] }))

    renderDock()
    fireEvent.click(screen.getByRole('button', { name: /clear/i }))

    expect(screen.queryByText('Recent')).not.toBeInTheDocument()
    expect(JSON.parse(window.localStorage.getItem(STORAGE_KEY) ?? '{}')).toMatchObject({ recent: [] })
  })

  it('clicking the pin icon toggles a module into the Pinned card', () => {
    renderDock()

    expect(screen.queryByText('Pinned')).not.toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: /^Pin Labs$/i }))

    expect(screen.getByText('Pinned')).toBeInTheDocument()
    expect(JSON.parse(window.localStorage.getItem(STORAGE_KEY) ?? '{}')).toMatchObject({ pinned: ['labs'] })
  })

  it('records a recent module when opening from the dock', () => {
    const { replaceFrom } = renderDock()

    fireEvent.click(screen.getByRole('button', { name: /^Access$/i }))

    expect(replaceFrom).toHaveBeenCalledWith(0, { id: 'access' })
    expect(JSON.parse(window.localStorage.getItem(STORAGE_KEY) ?? '{}')).toMatchObject({ recent: ['access'] })
  })

  it('renders category sections in registry order', () => {
    renderDock()

    const titleTexts = Array.from(document.querySelectorAll('[data-slot="card-title"]')).map((el) => el.textContent)

    expect(titleTexts).toEqual(['Clinical', 'Documents & Imaging', 'Admin'])
  })

  it('groups modules by category', () => {
    renderDock()

    expect(within(cardFor('Clinical')).getByText('Summary')).toBeInTheDocument()
    expect(within(cardFor('Documents & Imaging')).getByText('Docs')).toBeInTheDocument()
    expect(within(cardFor('Admin')).getByText('Access')).toBeInTheDocument()
  })

  it('renders key amounts from module metadata', () => {
    phrModuleRegistry.labs.keyAmounts = (state) => (state.patientId ? [{ label: 'Records', value: state.patientId }] : null)

    renderDock()

    const clinicalCard = cardFor('Clinical')
    expect(within(clinicalCard).getByText('Records')).toBeInTheDocument()
    expect(within(clinicalCard).getByText('123')).toBeInTheDocument()
    expect(within(clinicalCard).getByText('Records').parentElement).not.toHaveClass('font-currency')
  })

  it('renders an empty state and no dock sections when patientId is undefined', () => {
    window.localStorage.setItem(STORAGE_KEY, JSON.stringify({ pinned: ['labs'], recent: ['vitals'] }))

    renderDock({ patientId: undefined })

    expect(screen.getByText('No patient selected')).toBeInTheDocument()
    expect(screen.queryByText('Pinned')).not.toBeInTheDocument()
    expect(screen.queryByText('Clinical')).not.toBeInTheDocument()
  })
})
