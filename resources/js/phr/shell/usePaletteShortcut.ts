import { type Dispatch, type SetStateAction, useEffect } from 'react'

/**
 * Cmd/Ctrl-K toggles the command palette. Ignored while typing in a field or while another
 * dialog is open, so the shortcut never steals input from a form.
 */
export function usePaletteShortcut(open: boolean, setOpen: Dispatch<SetStateAction<boolean>>): void {
  useEffect(() => {
    const handler = (event: KeyboardEvent): void => {
      if (!isPaletteShortcut(event) || event.defaultPrevented) {
        return
      }
      if (!open && shouldSuppress(event)) {
        return
      }
      event.preventDefault()
      setOpen((prev) => !prev)
    }

    window.addEventListener('keydown', handler)
    return () => {
      window.removeEventListener('keydown', handler)
    }
  }, [open, setOpen])
}

function isPaletteShortcut(event: KeyboardEvent): boolean {
  return (event.key === 'k' || event.key === 'K') && (event.metaKey || event.ctrlKey)
}

function shouldSuppress(event: KeyboardEvent): boolean {
  return isEditableTarget(event) || document.querySelector('[role="dialog"][data-open]') !== null
}

function isEditableTarget(event: KeyboardEvent): boolean {
  const target = event.target instanceof HTMLElement ? event.target : document.activeElement
  if (!(target instanceof HTMLElement)) {
    return false
  }
  if (target.isContentEditable || target.closest('[contenteditable="true"]')) {
    return true
  }
  return ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName)
}
