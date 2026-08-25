import { relyingApplications } from '@/lib/appShell'

function withInitialData(data: unknown): void {
  document.body.innerHTML = ''
  const node = document.createElement('script')
  node.id = 'app-initial-data'
  node.type = 'application/json'
  node.textContent = JSON.stringify(data)
  document.body.append(node)
}

describe('relyingApplications', () => {
  it('returns the applications the provider reported', () => {
    withInitialData({
      applications: [{ key: 'games', name: 'Games', url: 'https://games.example.test/' }],
    })

    expect(relyingApplications()).toEqual([
      { key: 'games', name: 'Games', url: 'https://games.example.test/' },
    ])
  })

  // These become an `href` the browser will follow. A scheme that executes rather than
  // navigates must not survive, whatever the provider said.
  it.each([
    'javascript:alert(1)',
    'JavaScript:alert(1)',
    'data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==',
    'vbscript:msgbox(1)',
    'not a url at all',
  ])('drops %s', (url) => {
    withInitialData({ applications: [{ key: 'evil', name: 'Evil', url }] })

    expect(relyingApplications()).toEqual([])
  })

  it('drops malformed entries without losing the sound ones', () => {
    withInitialData({
      applications: [
        { key: 'ok', name: 'Fine', url: 'https://fine.example.test/' },
        { key: 'no-url', name: 'Broken' },
        { name: 'No key', url: 'https://nokey.example.test/' },
        'not an object',
      ],
    })

    expect(relyingApplications()).toEqual([
      { key: 'ok', name: 'Fine', url: 'https://fine.example.test/' },
    ])
  })

  it('is empty when there is no initial data at all', () => {
    document.body.innerHTML = ''

    expect(relyingApplications()).toEqual([])
  })
})
