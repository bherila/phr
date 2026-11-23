import '@testing-library/jest-dom'

import { fireEvent, render, screen, waitFor } from '@testing-library/react'

import EpisodeDetail from './EpisodeDetail'
import OfficeVisitEpisodes from './OfficeVisitEpisodes'

const EPISODE = {
  id: 3,
  patient_id: 42,
  user_id: 7,
  import_source: 'agent-client:synthetic',
  external_id: 'synthetic-episode-pneumonia',
  source_document_id: null,
  review_status: 'pending_review',
  title: 'Community-acquired pneumonia',
  kind: 'illness',
  status: 'active',
  started_at: '2025-11-03 00:00:00',
  ended_at: null,
  reason: 'The earlier pneumonia admission led to the current stay.',
  created_at: null,
  updated_at: null,
}

const ENCOUNTERS = [
  { id: 18, visit_date: '2025-11-03', visit_started_at: null, visit_ended_at: '2025-11-06 17:00:00', visit_type: 'Synthetic hospital admission', encounter_class: 'IMP', facility_name: 'Other Community Hospital', review_status: 'confirmed' },
  { id: 19, visit_date: '2026-02-09', visit_started_at: null, visit_ended_at: null, visit_type: 'Synthetic hospital admission', encounter_class: 'IMP', facility_name: 'Example General Hospital', review_status: 'confirmed' },
]

function jsonResponse(payload: unknown): Response {
  return {
    ok: true,
    status: 200,
    statusText: 'OK',
    json: async () => payload,
    text: async () => JSON.stringify(payload),
  } as Response
}

describe('EpisodeDetail', () => {
  afterEach(() => {
    jest.restoreAllMocks()
  })

  it('shows the episode with the visits it groups, each opening its visit', async () => {
    const onDrill = jest.fn()
    jest.spyOn(globalThis, 'fetch').mockResolvedValue(jsonResponse({ episode: EPISODE, encounters: ENCOUNTERS, can_manage: false }))

    render(<EpisodeDetail patientId={42} recordId="3" onDrill={onDrill} />)

    expect(await screen.findByRole('heading', { name: 'Community-acquired pneumonia' })).toBeInTheDocument()
    expect(screen.getByText('Illness')).toBeInTheDocument()
    expect(screen.getByText('Pending review')).toBeInTheDocument()
    expect(screen.getByText(/earlier pneumonia admission/)).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: /Other Community Hospital/ }))
    expect(onDrill).toHaveBeenCalledWith({ id: 'office-visit-detail', instance: '18' })
    expect(screen.getByRole('button', { name: /Example General Hospital/ })).toBeInTheDocument()
  })
})

describe('OfficeVisitEpisodes', () => {
  afterEach(() => {
    jest.restoreAllMocks()
  })

  it('lists the episodes a visit is grouped under and opens one', async () => {
    const onDrill = jest.fn()
    const fetchSpy = jest.spyOn(globalThis, 'fetch').mockResolvedValue(jsonResponse({ episodes: [{ ...EPISODE, encounters: ENCOUNTERS }], can_manage: true }))

    render(<OfficeVisitEpisodes patientId={42} visitId="19" onDrill={onDrill} />)

    fireEvent.click(await screen.findByRole('button', { name: /Community-acquired pneumonia/ }))
    expect(onDrill).toHaveBeenCalledWith({ id: 'episode-detail', instance: '3' })
    expect(screen.getByText(/1 other visit$/)).toBeInTheDocument()
    expect(fetchSpy).toHaveBeenCalledWith('/api/phr/patients/42/episodes?office_visit_id=19', expect.anything())
  })

  it('shows nothing for a visit in no episode', async () => {
    const fetchSpy = jest.spyOn(globalThis, 'fetch').mockResolvedValue(jsonResponse({ episodes: [], can_manage: true }))

    render(<OfficeVisitEpisodes patientId={42} visitId="19" />)

    await waitFor(() => expect(fetchSpy).toHaveBeenCalled())
    expect(screen.queryByRole('heading', { name: 'Episodes of care' })).not.toBeInTheDocument()
  })
})
