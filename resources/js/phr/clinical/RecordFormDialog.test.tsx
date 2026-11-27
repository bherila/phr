import '@testing-library/jest-dom'

import { render, screen, within } from '@testing-library/react'

import { RecordFormDialog } from './RecordFormDialog'

const props = {
  title: 'Add synthetic record',
  description: 'Synthetic fields.',
  submitLabel: 'Save',
  busy: false,
  onSubmit: jest.fn(),
  onClose: jest.fn(),
}

it('shows every field failure even when the caller has no field error renderer', () => {
  const { rerender } = render(
    <RecordFormDialog {...props} fieldErrors={{ missing: 'The hidden field is too long.', other: 'Choose a valid status.', empty: undefined }}>
      <input aria-label="Name" />
    </RecordFormDialog>,
  )
  const alert = within(screen.getByRole('dialog')).getByRole('alert')
  expect(alert).toBeVisible()
  expect(alert).toHaveTextContent('The hidden field is too long.')
  expect(alert).toHaveTextContent('Choose a valid status.')
  expect(within(alert).getAllByRole('listitem')).toHaveLength(2)

  rerender(<RecordFormDialog {...props} fieldErrors={{}}><input aria-label="Name" /></RecordFormDialog>)
  expect(screen.queryByRole('alert')).not.toBeInTheDocument()
})

it('also shows a refused server save when there are no validation failures', () => {
  render(<RecordFormDialog {...props} error="The record could not be saved."><input aria-label="Name" /></RecordFormDialog>)
  expect(screen.getByRole('alert')).toHaveTextContent('The record could not be saved.')
})
