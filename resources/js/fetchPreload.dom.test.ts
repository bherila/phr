import { z } from 'zod'

import { clearPhrPreload, installPhrPreload, PHR_PRELOAD_ELEMENT_ID, PHR_PRELOAD_TTL_MS, phrPreloadKey } from '@/fetchPreload'
import { fetchWrapper } from '@/fetchWrapper'
import { resetReviewSummaries, reviewStateChanged } from '@/phr/review/reviewSummary'
import { fetchPhrDetail } from '@/phr/shared'
import { PHR_LOCATION_CHANGE_EVENT } from '@/phr/shell/phrLocation'

// jsdom has no Fetch API. Browsers do; this stand-in carries the parts callers read.
class TestResponse {
  readonly ok: boolean
  readonly status: number
  readonly statusText: string
  readonly headers: Map<string, string>
  readonly #body: string

  constructor(body: string, init: { status?: number, statusText?: string, headers?: Record<string, string> } = {}) {
    this.#body = body
    this.status = init.status ?? 200
    this.statusText = init.statusText ?? ''
    this.ok = this.status >= 200 && this.status < 300
    this.headers = new Map(Object.entries(init.headers ?? {}).map(([name, value]) => [name.toLowerCase(), value]))
  }

  async text(): Promise<string> {
    return this.#body
  }

  async json(): Promise<unknown> {
    return JSON.parse(this.#body) as unknown
  }
}

const LABS = '/api/phr/patients/7/lab-results'
const LABS_BODY = { lab_results: [{ id: 1, analyte: 'Synthetic analyte', value: '5' }], can_manage: true, empty: {} }

function seed(map: unknown, raw?: string): void {
  const script = document.createElement('script')
  script.type = 'application/json'
  script.id = PHR_PRELOAD_ELEMENT_ID
  script.textContent = raw ?? JSON.stringify(map)
  document.head.append(script)
}

function networkResponse(body: unknown): TestResponse {
  return new TestResponse(JSON.stringify(body), { status: 200, statusText: 'OK', headers: { 'Content-Type': 'application/json' } })
}

let fetchMock: jest.Mock

beforeAll(() => {
  Object.defineProperty(globalThis, 'Response', { configurable: true, writable: true, value: TestResponse })
})

beforeEach(() => {
  window.history.replaceState(null, '', '/phr/patient/7/labs')
  fetchMock = jest.fn(async () => networkResponse({ from: 'network' }))
  Object.defineProperty(globalThis, 'fetch', { configurable: true, writable: true, value: fetchMock })
})

afterEach(() => {
  clearPhrPreload()
  document.getElementById(PHR_PRELOAD_ELEMENT_ID)?.remove()
  jest.restoreAllMocks()
})

describe('PHR first-load preload', () => {
  it('answers a preloaded GET without the network, resolving exactly as the network path does', async () => {
    seed({ [LABS]: LABS_BODY })
    installPhrPreload()

    const preloaded: unknown = await fetchWrapper.get(LABS)
    expect(fetchMock).not.toHaveBeenCalled()

    fetchMock.mockResolvedValueOnce(networkResponse(LABS_BODY))
    const fromNetwork: unknown = await fetchWrapper.get(LABS)
    expect(fetchMock).toHaveBeenCalledTimes(1)
    expect(preloaded).toStrictEqual(fromNetwork)
    expect(preloaded).toStrictEqual(LABS_BODY)
  })

  it('gives getRaw callers a 200 JSON Response and feeds fetchPhrDetail', async () => {
    const detailUrl = '/api/phr/patients/7/conditions/3'
    seed({ [LABS]: LABS_BODY, [detailUrl]: { condition: { id: 3, name: 'Synthetic condition' }, can_manage: false } })
    installPhrPreload()

    const raw = await fetchWrapper.getRaw(LABS)
    expect(raw).toBeInstanceOf(TestResponse)
    expect(raw.ok).toBe(true)
    expect(raw.status).toBe(200)
    expect(raw.headers.get('content-type')).toBe('application/json')
    expect(await raw.json()).toStrictEqual(LABS_BODY)

    const schema = z.object({ condition: z.object({ id: z.number(), name: z.string() }), can_manage: z.boolean() })
    await expect(fetchPhrDetail(detailUrl, schema)).resolves.toStrictEqual({
      data: { condition: { id: 3, name: 'Synthetic condition' }, can_manage: false },
      notFound: false,
    })
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it('sends a miss to the network unchanged', async () => {
    seed({ [LABS]: LABS_BODY })
    installPhrPreload()

    await expect(fetchWrapper.get('/api/phr/patients/7/vitals')).resolves.toStrictEqual({ from: 'network' })
    expect(fetchMock).toHaveBeenCalledWith('/api/phr/patients/7/vitals', {
      method: 'GET',
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'include',
    })
    // Rejected-record views are a different URL, so they are not served the default list.
    await fetchWrapper.get(`${LABS}?include_rejected=1`)
    expect(fetchMock).toHaveBeenCalledTimes(2)
  })

  it('serves each entry once, so a refetch reaches the network', async () => {
    seed({ [LABS]: LABS_BODY })
    installPhrPreload()

    await fetchWrapper.get(LABS)
    await expect(fetchWrapper.get(LABS)).resolves.toStrictEqual({ from: 'network' })
    await fetchWrapper.getRaw(LABS)
    expect(fetchMock).toHaveBeenCalledTimes(2)
  })

  it('matches on the same-origin path and a sorted query string', async () => {
    seed({ '/api/phr/patients/7/documents?type=lab&source=upload': { documents: [] } })
    installPhrPreload()

    expect(phrPreloadKey('/api/phr/patients/7/documents?type=lab&source=upload')).toBe('/api/phr/patients/7/documents?source=upload&type=lab')
    expect(phrPreloadKey(`${window.location.origin}/api/phr/patients/7/documents?source=upload&type=lab`)).toBe('/api/phr/patients/7/documents?source=upload&type=lab')
    expect(phrPreloadKey('https://other.example.test/api/phr/patients/7/documents')).toBeNull()

    await fetchWrapper.get('https://other.example.test/api/phr/patients/7/documents?source=upload&type=lab')
    expect(fetchMock).toHaveBeenCalledTimes(1)
    await expect(fetchWrapper.get(`${window.location.origin}/api/phr/patients/7/documents?source=upload&type=lab`)).resolves.toStrictEqual({ documents: [] })
    expect(fetchMock).toHaveBeenCalledTimes(1)
  })

  it('reads the element once and removes it from the page', async () => {
    seed({ [LABS]: LABS_BODY })
    installPhrPreload()
    expect(document.getElementById(PHR_PRELOAD_ELEMENT_ID)).toBeNull()

    await fetchWrapper.get(LABS)
    installPhrPreload()
    await fetchWrapper.get(LABS)
    expect(fetchMock).toHaveBeenCalledTimes(1)
  })

  it('ignores a malformed element without failing the page', async () => {
    seed(null, '{"broken"')
    expect(() => installPhrPreload()).not.toThrow()
    expect(document.getElementById(PHR_PRELOAD_ELEMENT_ID)).toBeNull()
    await fetchWrapper.get(LABS)
    expect(fetchMock).toHaveBeenCalledTimes(1)

    seed(null, '[1, 2]')
    installPhrPreload()
    await fetchWrapper.get(LABS)
    expect(fetchMock).toHaveBeenCalledTimes(2)
  })

  it.each([
    ['an in-app navigation', () => { window.dispatchEvent(new Event(PHR_LOCATION_CHANGE_EVENT)) }],
    ['Back or Forward', () => { window.dispatchEvent(new PopStateEvent('popstate')) }],
    ['a location change nothing announced', () => { window.history.pushState(null, '', '/phr/patient/7/vitals'); window.history.pushState(null, '', '/phr/patient/7/labs?x=1') }],
    ['a write', () => { void fetchWrapper.post('/api/phr/patients/7/lab-results', {}) }],
    ['a restore from the back/forward cache', () => {
      const event = new Event('pageshow')
      Object.defineProperty(event, 'persisted', { value: true })
      window.dispatchEvent(event)
    }],
  ])('drops every unused entry after %s', async (_label, leave) => {
    seed({ [LABS]: LABS_BODY })
    installPhrPreload()
    leave()
    fetchMock.mockClear()

    await fetchWrapper.get(LABS)
    expect(fetchMock).toHaveBeenCalledTimes(1)
  })

  it('keeps entries across an ordinary first page show', async () => {
    seed({ [LABS]: LABS_BODY })
    installPhrPreload()
    const event = new Event('pageshow')
    Object.defineProperty(event, 'persisted', { value: false })
    window.dispatchEvent(event)

    await fetchWrapper.get(LABS)
    expect(fetchMock).not.toHaveBeenCalled()
  })

  describe('the review counts', () => {
    const SUMMARY = '/api/phr/patients/7/review/summary'
    const SUMMARY_BODY = { summary: { total: 2, sections: { labs: { pending: 2, records: 2 } }, ai_results: 0 }, can_manage: true }

    afterEach(() => {
      resetReviewSummaries()
    })

    it('fetches them from the network after a write, never the counts the page loaded with', async () => {
      seed({ [SUMMARY]: SUMMARY_BODY })
      installPhrPreload()

      await fetchWrapper.patch('/api/phr/patients/7/lab-results/1/review', { review_status: 'confirmed' })
      void reviewStateChanged(7)
      fetchMock.mockClear()
      await fetchWrapper.get(SUMMARY)
      expect(fetchMock).toHaveBeenCalledTimes(1)
      expect(fetchMock.mock.calls[0]?.[0]).toBe(SUMMARY)
    })

    it('drops the preload when review state changes without a fetchWrapper write', async () => {
      seed({ [SUMMARY]: SUMMARY_BODY, [LABS]: LABS_BODY })
      installPhrPreload()

      await reviewStateChanged(7)
      await fetchWrapper.get(SUMMARY)
      await fetchWrapper.get(LABS)
      expect(fetchMock).toHaveBeenCalledTimes(2)
    })
  })

  it('expires entries a late consumer would otherwise read stale', async () => {
    const now = jest.spyOn(performance, 'now').mockReturnValue(1_000)
    seed({ [LABS]: LABS_BODY })
    installPhrPreload()
    now.mockReturnValue(1_000 + PHR_PRELOAD_TTL_MS + 1)

    await fetchWrapper.get(LABS)
    expect(fetchMock).toHaveBeenCalledTimes(1)
  })
})
