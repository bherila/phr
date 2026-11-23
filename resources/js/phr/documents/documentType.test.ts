import { documentTypeLabel, noteAuthorship } from '@/phr/documents/documentType'

describe('documentType', () => {
  it('labels the clinical note types', () => {
    expect(documentTypeLabel('progress_note')).toBe('Progress Note')
    expect(documentTypeLabel('history_and_physical')).toBe('History & Physical')
    expect(documentTypeLabel('ems_report')).toBe('EMS Report')
  })

  it('reads a note\'s authorship in one line', () => {
    expect(noteAuthorship({ author_name: 'Jordan Fellow, MD', author_role: 'Nephrology fellow', cosigner_name: 'Casey Consultant, MD', doc_status: 'final' }))
      .toBe('By Jordan Fellow, MD (Nephrology fellow) · cosigned by Casey Consultant, MD · Final')
    expect(noteAuthorship({ patient_signature_status: 'to_sign' })).toBe('Awaiting patient signature')
    expect(noteAuthorship({ doc_status: 'unknown-status' })).toBe('unknown-status')
    expect(noteAuthorship({})).toBeNull()
  })
})
