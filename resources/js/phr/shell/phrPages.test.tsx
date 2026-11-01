import { fireEvent, render, screen } from '@testing-library/react'
import { Suspense } from 'react'

jest.mock('@/phr/config/AiProviderSettingsPage', () => ({ __esModule: true, default: () => <h1>AI Provider Settings</h1> }))
jest.mock('@/phr/config/ConnectedAppsPage', () => ({ __esModule: true, default: () => <h1>Connected apps</h1> }))

import { ConfigPage, PHR_DETAILS, PHR_NAV_GROUPS, PHR_PAGES } from './phrPages'
import { PHR_DETAIL_ROUTES, PHR_PAGE_IDS } from './phrRoutes'

describe('phrPages', () => {
  it('lists every patient page in the sidebar exactly once, or under its section', () => {
    const listed = PHR_NAV_GROUPS.flat()
    expect([...listed].sort()).toEqual(PHR_PAGE_IDS.filter((page) => !PHR_PAGES[page].sectionPage).sort())
    expect(new Set(listed).size).toBe(listed.length)
    for (const page of PHR_PAGE_IDS) {
      const home = PHR_PAGES[page].sectionPage
      if (home) expect(listed).toContain(home)
    }
  })

  it('has a component and label for every page and detail the URL model can name', () => {
    for (const page of PHR_PAGE_IDS) {
      expect(PHR_PAGES[page].label).not.toBe('')
      expect(PHR_PAGES[page].keywords.length).toBeGreaterThan(0)
    }
    expect(Object.keys(PHR_DETAILS).sort()).toEqual(Object.keys(PHR_DETAIL_ROUTES).sort())
  })

  it('opens connected apps from Config without requiring a selected patient', async () => {
    render(<Suspense fallback={null}><ConfigPage /></Suspense>)
    await screen.findByRole('heading', { name: 'AI Provider Settings' })
    const appsTab = screen.getByRole('tab', { name: 'Connected apps' })
    fireEvent.click(appsTab)
    expect(await screen.findByRole('heading', { name: 'Connected apps' })).toBeTruthy()
    expect(appsTab.getAttribute('aria-selected')).toBe('true')
    expect(screen.queryByRole('heading', { name: 'AI Provider Settings' })).toBeNull()
  })
})
