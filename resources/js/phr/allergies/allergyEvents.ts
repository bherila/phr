import type { PhrAllergy } from '@/phr/types'

const ALLERGY_CHANGED_EVENT = 'phr:allergy-changed'

type AllergyChange =
  | { action: 'updated', allergy: PhrAllergy, patientId: number }
  | { action: 'deleted', allergyId: number, patientId: number }

export function notifyAllergyChanged(change: AllergyChange): void {
  window.dispatchEvent(new CustomEvent<AllergyChange>(ALLERGY_CHANGED_EVENT, { detail: change }))
}

export function subscribeToAllergyChanges(listener: (change: AllergyChange) => void): () => void {
  const handleChange = (event: Event): void => {
    listener((event as CustomEvent<AllergyChange>).detail)
  }

  window.addEventListener(ALLERGY_CHANGED_EVENT, handleChange)
  return () => window.removeEventListener(ALLERGY_CHANGED_EVENT, handleChange)
}
