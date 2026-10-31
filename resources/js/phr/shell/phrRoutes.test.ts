import {
  legacyHashUrl,
  parsePhrLocation,
  PHR_DETAIL_ROUTES,
  PHR_PAGE_IDS,
  type PhrDetailId,
  type PhrView,
  phrViewUrl,
  resolveNavTarget,
} from './phrRoutes'

const patient = (page: Extract<PhrView, { kind: 'patient' }>['page'], extra: Partial<Extract<PhrView, { kind: 'patient' }>> = {}): PhrView => ({
  kind: 'patient',
  patientId: 12,
  page,
  ...extra,
})

describe('parsePhrLocation and phrViewUrl', () => {
  it.each([
    ['/phr/patients', { kind: 'section', section: 'patients', search: '' }],
    ['/phr/patients/manage', { kind: 'section', section: 'manage-patients', search: '' }],
    ['/phr/imports', { kind: 'section', section: 'imports', search: '' }],
    ['/phr/data-hub', { kind: 'section', section: 'data-hub', search: '' }],
    ['/phr/config', { kind: 'section', section: 'config', search: '' }],
    ['/phr/patient/12', patient('summary')],
    ['/phr/patient/12/labs', patient('labs')],
    ['/phr/patient/12/labs/5', patient('labs', { detail: { module: 'lab-panel-detail', id: '5' } })],
    ['/phr/patient/12/documents/9', patient('documents', { detail: { module: 'document-viewer', id: '9' } })],
    ['/phr/patient/12/imaging/3', patient('imaging', { detail: { module: 'imaging-study-detail', id: '3' } })],
    ['/phr/patient/12/vitals/trend/blood%20pressure', patient('vitals', { detail: { module: 'vitals-trend', id: 'blood pressure' } })],
    ['/phr/patient/12/office-visits/procedure/7', patient('office-visits', { detail: { module: 'procedure-detail', id: '7' } })],
    ['/phr/patient/12/office-visits/4/imaging/3', patient('office-visits', {
      parent: { module: 'office-visit-detail', id: '4' },
      detail: { module: 'imaging-study-detail', id: '3' },
    })],
  ] as const)('round-trips %s', (path, view) => {
    expect(parsePhrLocation(path)).toEqual(view)
    expect(phrViewUrl(view)).toBe(path)
  })

  it('keeps a section query as part of the view', () => {
    const view = parsePhrLocation('/phr/imports', '?patient_id=12')
    expect(view).toEqual({ kind: 'section', section: 'imports', search: '?patient_id=12' })
    expect(phrViewUrl(view!)).toBe('/phr/imports?patient_id=12')
  })

  it('reads /summary and unknown pages as the patient Summary', () => {
    expect(parsePhrLocation('/phr/patient/12/summary')).toEqual(patient('summary'))
    expect(parsePhrLocation('/phr/patient/12/not-a-page/3')).toEqual(patient('summary'))
    expect(parsePhrLocation('/phr/patient/12/labs/')).toEqual(patient('labs'))
  })

  it('returns null outside the shell', () => {
    expect(parsePhrLocation('/phr')).toBeNull()
    expect(parsePhrLocation('/phr/patient/abc')).toBeNull()
    expect(parsePhrLocation('/ohif/viewer')).toBeNull()
  })

  it('gives every page and every detail type a path that parses back to it', () => {
    for (const page of PHR_PAGE_IDS) {
      expect(parsePhrLocation(phrViewUrl(patient(page)))).toEqual(patient(page))
    }
    for (const module of Object.keys(PHR_DETAIL_ROUTES) as PhrDetailId[]) {
      const view = patient(PHR_DETAIL_ROUTES[module].home, { detail: { module, id: '8' } })
      expect(parsePhrLocation(phrViewUrl(view))).toEqual(view)
    }
  })
})

describe('resolveNavTarget', () => {
  it('opens a page’s own record beside its list', () => {
    expect(resolveNavTarget(patient('conditions'), { id: 'condition-detail', instance: '5' }))
      .toEqual(patient('conditions', { detail: { module: 'condition-detail', id: '5' } }))
  })

  it('swaps the panel when another row is selected', () => {
    const open = patient('conditions', { detail: { module: 'condition-detail', id: '5' } })
    expect(resolveNavTarget(open, { id: 'condition-detail', instance: '6' }))
      .toEqual(patient('conditions', { detail: { module: 'condition-detail', id: '6' } }))
  })

  it('keeps Visits procedures and immunizations in the Visits panel', () => {
    expect(resolveNavTarget(patient('office-visits'), { id: 'immunization-detail', instance: '2' }))
      .toEqual(patient('office-visits', { detail: { module: 'immunization-detail', id: '2' } }))
  })

  it('shows a visit’s linked imaging study in the visit’s place, with the visit as parent', () => {
    const visit = patient('office-visits', { detail: { module: 'office-visit-detail', id: '4' } })
    const study = resolveNavTarget(visit, { id: 'imaging-study-detail', instance: '3' }, 'detail')
    expect(study).toEqual(patient('office-visits', {
      parent: { module: 'office-visit-detail', id: '4' },
      detail: { module: 'imaging-study-detail', id: '3' },
    }))
    // Choosing a row in the list replaces the chain.
    expect(resolveNavTarget(study, { id: 'office-visit-detail', instance: '5' }))
      .toEqual(patient('office-visits', { detail: { module: 'office-visit-detail', id: '5' } }))
  })

  it('opens records another page owns on that page', () => {
    expect(resolveNavTarget(patient('summary'), { id: 'document-viewer', instance: '9' }))
      .toEqual(patient('documents', { detail: { module: 'document-viewer', id: '9' } }))
    expect(resolveNavTarget(patient('labs'), { id: 'imaging-study-detail', instance: '3' }))
      .toEqual(patient('imaging', { detail: { module: 'imaging-study-detail', id: '3' } }))
  })

  it('treats cross-section jumps as ordinary navigation', () => {
    expect(resolveNavTarget(patient('conditions'), { id: 'documents' })).toEqual(patient('documents'))
    const allergy = patient('allergies', { detail: { module: 'allergy-detail', id: '1' } })
    expect(resolveNavTarget(allergy, { id: 'allergies' }, 'detail')).toEqual(patient('allergies'))
    expect(resolveNavTarget(patient('imaging'), { id: 'imports' }))
      .toEqual({ kind: 'section', section: 'imports', search: '?patient_id=12' })
  })

  it('sends patient record requests without a patient to the Patients page', () => {
    expect(resolveNavTarget({ kind: 'section', section: 'config', search: '' }, { id: 'labs' }))
      .toEqual({ kind: 'section', section: 'patients', search: '' })
  })
})

describe('legacyHashUrl', () => {
  it.each([
    ['/phr/patient/12', '#/labs', '/phr/patient/12/labs'],
    ['/phr/patient/12', '#/labs/lab-panel-detail:5', '/phr/patient/12/labs/5'],
    ['/phr/patient/12', '#/summary', '/phr/patient/12'],
    ['/phr/patient/12', '#/', '/phr/patient/12'],
    ['/phr/patient/12', '#/documents/document-viewer:9', '/phr/patient/12/documents/9'],
    ['/phr/patient/12', '#/office-visits/office-visit-detail:4/imaging-study-detail:3', '/phr/patient/12/office-visits/4/imaging/3'],
    ['/phr/patient/12', '#/vitals/vitals-trend:weight', '/phr/patient/12/vitals/trend/weight'],
    ['/phr/patient/12', '#/labs/bad-id/imaging', '/phr/patient/12/imaging'],
    ['/phr/patient/12', '#/imports', '/phr/imports?patient_id=12'],
    ['/phr/patients', '#/patients', '/phr/patients'],
    ['/phr/patients', '#/patients/patients-manage', '/phr/patients/manage'],
    ['/phr/config', '#/config', '/phr/config'],
  ])('redirects %s%s to %s', (path, hash, expected) => {
    expect(legacyHashUrl(path, '', hash)).toBe(expected)
  })

  it('keeps the section query', () => {
    expect(legacyHashUrl('/phr/imports', '?patient_id=12&ehi_package=3', '#/imports')).toBe('/phr/imports?patient_id=12&ehi_package=3')
  })

  it('ignores hashes that are not Miller routes, and paths outside the shell', () => {
    expect(legacyHashUrl('/phr/patient/12', '', '')).toBeNull()
    expect(legacyHashUrl('/phr/patient/12', '', '#section-2')).toBeNull()
    expect(legacyHashUrl('/ohif/viewer', '', '#/labs')).toBeNull()
  })
})
