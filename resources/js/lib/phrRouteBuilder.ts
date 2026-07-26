export type PhrSection = 'patients' | 'manage-patients' | 'imports' | 'config'

export function patientUrl(patientId: number): string {
  return `/phr/patient/${patientId}`
}

export function patientsListUrl(): string {
  return '/phr/patients'
}

export function managePatientsUrl(): string {
  return '/phr/patients/manage'
}

export function phrSectionUrl(section: PhrSection): string {
  switch (section) {
    case 'patients':
      return patientsListUrl()
    case 'manage-patients':
      return managePatientsUrl()
    case 'imports':
      return '/phr/imports'
    case 'config':
      return '/phr/config'
  }
}
