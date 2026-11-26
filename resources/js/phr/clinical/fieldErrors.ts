import type { ZodError } from 'zod'

export type FieldErrors<TForm> = Partial<Record<keyof TForm & string, string>>

/**
 * One message per field from a failed form parse, for showing under each field rather than in a
 * banner. `messages` replaces Zod's own wording ("Too small: expected string to have >=1
 * characters") with the form's, field by field; a field without one keeps Zod's.
 */
export function fieldErrorsFrom<TForm>(error: ZodError, messages: FieldErrors<TForm> = {}): FieldErrors<TForm> {
  const errors: FieldErrors<TForm> = {}
  for (const issue of error.issues) {
    const field = issue.path[0]
    if (typeof field !== 'string' || field in errors) continue
    errors[field as keyof TForm & string] = messages[field as keyof TForm & string] ?? issue.message
  }
  return errors
}

/** The first invalid field of a form, by its `name`, to move focus to after a refused save. */
export function focusFirstInvalid(form: HTMLFormElement | null): void {
  form?.querySelector<HTMLElement>('[aria-invalid="true"]')?.focus()
}
