import type { PhrSection } from '@/phr/shell/phrRoutes'

export type { PhrSection }

export function patientUrl(patientId: number): string {
  return `/phr/patient/${patientId}`
}

export function patientsListUrl(): string {
  return '/phr/patients'
}

export function phrSectionUrl(section: PhrSection): string {
  switch (section) {
    case 'patients':
      return patientsListUrl()
    case 'imports':
      return '/phr/imports'
    case 'data-hub':
      return '/phr/data-hub'
    case 'config':
      return '/phr/config'
  }
}
