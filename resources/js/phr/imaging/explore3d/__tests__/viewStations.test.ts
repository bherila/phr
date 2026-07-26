import { pocketStationLabel, VIEW_STATIONS } from '../scene/viewStations'

describe('VIEW_STATIONS', () => {
  it('has unique ids', () => {
    const ids = VIEW_STATIONS.map((station) => station.id)
    expect(new Set(ids).size).toBe(ids.length)
  })

  it('places the front station on the anterior (+z) axis, matching the spawn', () => {
    const front = VIEW_STATIONS.find((station) => station.id === 'front')
    expect(front?.offset).toEqual([0, 0, 0.9])
    expect(front?.targetOffset).toEqual([0, 0, 0])
  })

  it('includes an inside station at the center for air-snapping', () => {
    const inside = VIEW_STATIONS.find((station) => station.id === 'inside')
    expect(inside?.offset).toEqual([0, 0, 0])
  })
})

describe('pocketStationLabel', () => {
  it('rounds large volumes to whole milliliters', () => {
    expect(pocketStationLabel(0, 12345)).toBe('Pocket 1 · 12 mL')
  })

  it('keeps one decimal for small volumes', () => {
    expect(pocketStationLabel(1, 6480)).toBe('Pocket 2 · 6.5 mL')
    expect(pocketStationLabel(2, 500)).toBe('Pocket 3 · 0.5 mL')
  })
})
