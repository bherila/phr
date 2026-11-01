import { getDisplayPreferences, resetDisplayPreferences, saveDisplayPreferences } from './displayPreferences'

const mockPut = jest.fn()

jest.mock('@/fetchWrapper', () => ({
  fetchWrapper: { put: (...args: unknown[]) => mockPut(...args) },
}))

function deferred(): { promise: Promise<unknown>, resolve: () => void, reject: (reason: Error) => void } {
  let resolve!: () => void
  let reject!: (reason: Error) => void
  const promise = new Promise((done, fail) => {
    resolve = () => done({})
    reject = fail
  })
  return { promise, resolve, reject }
}

beforeEach(() => {
  mockPut.mockReset()
  resetDisplayPreferences()
})

describe('saving display settings', () => {
  it('sends quick changes one at a time, in order', async () => {
    const first = deferred()
    mockPut.mockReturnValueOnce(first.promise).mockResolvedValueOnce({})

    const saves = [saveDisplayPreferences({ hide_medication_list: true }), saveDisplayPreferences({ separate_columns: true })]
    await Promise.resolve()
    expect(mockPut).toHaveBeenCalledTimes(1)
    expect(mockPut).toHaveBeenLastCalledWith('/api/user/display-preferences', { hide_medication_list: true })
    expect(getDisplayPreferences()).toMatchObject({ hide_medication_list: true, separate_columns: true })

    first.resolve()
    await Promise.all(saves)
    expect(mockPut).toHaveBeenCalledTimes(2)
    expect(mockPut).toHaveBeenLastCalledWith('/api/user/display-preferences', { separate_columns: true })
  })

  it('puts back only what a failed save changed, and still sends the next one', async () => {
    const first = deferred()
    mockPut.mockReturnValueOnce(first.promise).mockResolvedValueOnce({})

    const failing = saveDisplayPreferences({ hide_medication_list: true })
    const next = saveDisplayPreferences({ separate_columns: true })
    first.reject(new Error('Refused'))

    await expect(failing).rejects.toThrow('Refused')
    await next
    expect(getDisplayPreferences()).toMatchObject({ hide_medication_list: false, separate_columns: true })
    expect(mockPut).toHaveBeenCalledTimes(2)
  })
})
