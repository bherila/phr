import '@testing-library/jest-dom'

import { fireEvent, render, screen, waitFor } from '@testing-library/react'

import { EobDeleteAction, eobDeleteMountProps } from './EobDeleteAction'

const mockDelete = jest.fn()

jest.mock('@/fetchWrapper', () => ({
  fetchWrapper: {
    delete: (...args: unknown[]) => mockDelete(...args),
  },
}))

function renderAction(navigate = jest.fn()) {
  render(<EobDeleteAction patientId={12} eobId={34} claimLabel="claim CLM-1 from Example Provider" returnUrl="/phr/patient/12" navigate={navigate} />)

  return navigate
}

describe('EobDeleteAction', () => {
  beforeEach(() => {
    mockDelete.mockReset()
  })

  it('deletes only after the person confirms, then returns to the patient', async () => {
    mockDelete.mockResolvedValue(null)
    const navigate = renderAction()

    fireEvent.click(screen.getByRole('button', { name: 'Delete EOB' }))
    expect(await screen.findByText('Delete this EOB?')).toBeInTheDocument()
    expect(screen.getByText(/claim CLM-1 from Example Provider will be removed/)).toBeInTheDocument()
    expect(mockDelete).not.toHaveBeenCalled()

    fireEvent.click(screen.getByRole('button', { name: 'Delete' }))
    await waitFor(() => expect(navigate).toHaveBeenCalledWith('/phr/patient/12'))
    expect(mockDelete).toHaveBeenCalledWith('/api/phr/patients/12/eobs/34', {})
  })

  it('cancelling deletes nothing', async () => {
    const navigate = renderAction()

    fireEvent.click(screen.getByRole('button', { name: 'Delete EOB' }))
    fireEvent.click(await screen.findByRole('button', { name: 'Cancel' }))

    await waitFor(() => expect(screen.queryByText('Delete this EOB?')).not.toBeInTheDocument())
    expect(mockDelete).not.toHaveBeenCalled()
    expect(navigate).not.toHaveBeenCalled()
  })

  it('stays on the page and says why when the delete fails', async () => {
    mockDelete.mockRejectedValue({ message: 'This action is unauthorized.' })
    const navigate = renderAction()

    fireEvent.click(screen.getByRole('button', { name: 'Delete EOB' }))
    fireEvent.click(await screen.findByRole('button', { name: 'Delete' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('This action is unauthorized.')
    expect(navigate).not.toHaveBeenCalled()
  })
})

describe('eobDeleteMountProps', () => {
  function mount(data: Record<string, string>): HTMLElement {
    const element = document.createElement('div')
    Object.assign(element.dataset, data)

    return element
  }

  it('reads the page the server rendered', () => {
    expect(eobDeleteMountProps(mount({ patientId: '12', eobId: '34', canDelete: 'true', claimLabel: 'claim CLM-1', returnUrl: '/phr/patient/12' })))
      .toEqual({ patientId: 12, eobId: 34, claimLabel: 'claim CLM-1', returnUrl: '/phr/patient/12' })
  })

  it('offers nothing to a person who may not delete, or for a malformed mount', () => {
    expect(eobDeleteMountProps(mount({ patientId: '12', eobId: '34', canDelete: 'false', returnUrl: '/phr/patient/12' }))).toBeNull()
    expect(eobDeleteMountProps(mount({ patientId: 'x', eobId: '34', canDelete: 'true', returnUrl: '/phr/patient/12' }))).toBeNull()
    expect(eobDeleteMountProps(mount({ patientId: '12', eobId: '34', canDelete: 'true' }))).toBeNull()
  })
})
