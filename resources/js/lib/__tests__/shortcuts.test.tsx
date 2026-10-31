import { bindingMatches, eventKeyToken, isEditableTarget } from '@/lib/shortcuts'

const plain = { metaKey: false, ctrlKey: false, altKey: false }

describe('key tokens', () => {
  it('names presses the way bindings spell them', () => {
    expect(eventKeyToken({ ...plain, key: 'J' })).toBe('j')
    expect(eventKeyToken({ ...plain, key: ' ' })).toBe('Space')
    expect(eventKeyToken({ ...plain, key: 'ArrowDown' })).toBe('ArrowDown')
    expect(eventKeyToken({ ...plain, key: 'k', ctrlKey: true })).toBe('Mod+k')
    expect(eventKeyToken({ ...plain, key: 'g', altKey: true })).toBeNull()
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
