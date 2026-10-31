import { act, fireEvent, render, screen } from '@testing-library/react'
import type { ReactElement } from 'react'

import { bindingMatches, eventKeyToken, isEditableTarget, keyLabel, SEQUENCE_TIMEOUT_MS, type ShortcutBinding, useShortcutRegistry } from '@/lib/shortcuts'

function Harness({ bindings, dialog = false }: { bindings: ShortcutBinding[], dialog?: boolean }): ReactElement {
  useShortcutRegistry(bindings)
  return (
    <>
      <input aria-label="Field" />
      <textarea aria-label="Notes" />
      <select aria-label="Choice"><option>One</option></select>
      <div aria-label="Rich text" role="textbox" contentEditable tabIndex={0} />
      <button type="button">Plain button</button>
      {dialog && <div role="dialog" data-open>Open dialog</div>}
    </>
  )
}

function binding(id: string, keys: ShortcutBinding['keys'], run: jest.Mock, extra: Partial<ShortcutBinding> = {}): ShortcutBinding {
  return { id, keys, description: id, group: 'Test', run, ...extra }
}

describe('useShortcutRegistry', () => {
  afterEach(() => {
    jest.useRealTimers()
  })

  it('runs a single-key binding and consumes the press', () => {
    const run = jest.fn()
    render(<Harness bindings={[binding('help', [['?']], run)]} />)

    const event = new KeyboardEvent('keydown', { key: '?', shiftKey: true, bubbles: true, cancelable: true })
    act(() => { window.dispatchEvent(event) })

    expect(run).toHaveBeenCalledTimes(1)
    expect(event.defaultPrevented).toBe(true)
  })

  it.each(['Field', 'Notes', 'Choice', 'Rich text'])('ignores shortcuts while focus is in %s', (label) => {
    const run = jest.fn()
    render(<Harness bindings={[binding('help', [['?']], run), binding('go', [['g', 's']], run)]} />)
    const target = screen.getByLabelText(label)
    act(() => target.focus())

    fireEvent.keyDown(target, { key: '?' })
    fireEvent.keyDown(target, { key: 'g' })
    fireEvent.keyDown(target, { key: 's' })

    expect(run).not.toHaveBeenCalled()
  })

  it('ignores shortcuts while a dialog is open unless the binding opts in', () => {
    const blocked = jest.fn()
    const allowed = jest.fn()
    render(<Harness dialog bindings={[binding('help', [['?']], blocked), binding('escape', [['Escape']], allowed, { allowInDialogs: true })]} />)

    fireEvent.keyDown(window, { key: '?' })
    fireEvent.keyDown(window, { key: 'Escape' })

    expect(blocked).not.toHaveBeenCalled()
    expect(allowed).toHaveBeenCalledTimes(1)
  })

  it('skips presses another handler already consumed, and disabled or listing-only bindings', () => {
    const run = jest.fn()
    render(<Harness bindings={[binding('help', [['?']], run, { enabled: false }), { id: 'listed', keys: [['j']], description: 'Listed', group: 'Test' }]} />)
    fireEvent.keyDown(window, { key: '?' })
    fireEvent.keyDown(window, { key: 'j' })
    expect(run).not.toHaveBeenCalled()

    const enabled = jest.fn()
    render(<Harness bindings={[binding('help2', [['/']], enabled)]} />)
    const button = screen.getAllByRole('button', { name: 'Plain button' })[1]!
    button.addEventListener('keydown', (event) => event.preventDefault())
    fireEvent.keyDown(button, { key: '/' })
    expect(enabled).not.toHaveBeenCalled()
  })

  it('runs a two-key sequence, and drops it after a timeout or an unknown second key', () => {
    jest.useFakeTimers()
    const goSummary = jest.fn()
    const toggle = jest.fn()
    render(<Harness bindings={[binding('go-summary', [['g', 's']], goSummary), binding('toggle', [['[']], toggle)]} />)

    fireEvent.keyDown(window, { key: 'g' })
    fireEvent.keyDown(window, { key: 's' })
    expect(goSummary).toHaveBeenCalledTimes(1)

    fireEvent.keyDown(window, { key: 'g' })
    act(() => { jest.advanceTimersByTime(SEQUENCE_TIMEOUT_MS + 1) })
    fireEvent.keyDown(window, { key: 's' })
    expect(goSummary).toHaveBeenCalledTimes(1)

    fireEvent.keyDown(window, { key: 'g' })
    fireEvent.keyDown(window, { key: 'q' })
    fireEvent.keyDown(window, { key: 's' })
    expect(goSummary).toHaveBeenCalledTimes(1)

    // A key that is not part of the sequence still does its own job.
    fireEvent.keyDown(window, { key: 'g' })
    fireEvent.keyDown(window, { key: '[' })
    expect(toggle).toHaveBeenCalledTimes(1)
    expect(goSummary).toHaveBeenCalledTimes(1)
  })

  it('needs the modifier for ⌘K / Ctrl+K and never fires a bare letter with one', () => {
    const palette = jest.fn()
    const go = jest.fn()
    render(<Harness bindings={[binding('palette', [['Mod+k']], palette), binding('go', [['g', 's']], go)]} />)

    fireEvent.keyDown(window, { key: 'k' })
    expect(palette).not.toHaveBeenCalled()
    fireEvent.keyDown(window, { key: 'k', metaKey: true })
    fireEvent.keyDown(window, { key: 'K', ctrlKey: true })
    expect(palette).toHaveBeenCalledTimes(2)

    fireEvent.keyDown(window, { key: 'g', metaKey: true })
    fireEvent.keyDown(window, { key: 's' })
    expect(go).not.toHaveBeenCalled()
  })
})

const plain = { metaKey: false, ctrlKey: false, altKey: false }

describe('key tokens', () => {
  it('names presses and prints them for the dialog', () => {
    expect(eventKeyToken({ key: 'J', metaKey: false, ctrlKey: false, altKey: false })).toBe('j')
    expect(eventKeyToken({ key: ' ', metaKey: false, ctrlKey: false, altKey: false })).toBe('Space')
    expect(eventKeyToken({ key: 'k', metaKey: false, ctrlKey: true, altKey: false })).toBe('Mod+k')
    expect(eventKeyToken({ key: 'g', metaKey: false, ctrlKey: false, altKey: true })).toBeNull()
    expect(keyLabel('ArrowDown')).toBe('↓')
    expect(keyLabel('g')).toBe('G')
    expect(keyLabel('Escape')).toBe('Esc')
  })

  it('matches single-key bindings and never a bare letter held with a modifier', () => {
    const next = { keys: [['ArrowDown'], ['j']] }
    expect(bindingMatches(next, { ...plain, key: 'j' })).toBe(true)
    expect(bindingMatches(next, { ...plain, key: 'ArrowDown' })).toBe(true)
    expect(bindingMatches(next, { ...plain, key: 'j', metaKey: true })).toBe(false)
  })

  it('treats fields and contenteditable as places where keys are typed', () => {
    document.body.innerHTML = '<input aria-label="field" /><div contenteditable="true"><span>rich</span></div><button>plain</button>'
    expect(isEditableTarget(document.querySelector('input'))).toBe(true)
    expect(isEditableTarget(document.querySelector('span'))).toBe(true)
    expect(isEditableTarget(document.querySelector('button'))).toBe(false)
  })
})
