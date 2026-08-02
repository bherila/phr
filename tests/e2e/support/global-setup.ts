import { execFileSync } from 'node:child_process'
import { mkdirSync, rmSync, writeFileSync } from 'node:fs'
import path from 'node:path'

import { e2eDatabasePath, e2eEnv, e2eStorageRoot } from './environment'

export default function globalSetup(): void {
  const safeDatabaseRoot = `${path.join(process.cwd(), 'storage/framework/testing')}${path.sep}`
  if (!e2eDatabasePath.startsWith(safeDatabaseRoot)) {
    throw new Error('Refusing to recreate a Playwright database outside storage/framework/testing.')
  }

  execFileSync('php', ['artisan', 'config:clear', '--no-interaction', '--no-ansi'], {
    env: e2eEnv,
    stdio: 'inherit',
  })

  mkdirSync(path.dirname(e2eDatabasePath), { recursive: true })
  rmSync(e2eDatabasePath, { force: true })
  rmSync(e2eStorageRoot, { force: true, recursive: true })
  writeFileSync(e2eDatabasePath, '')

  execFileSync(
    'php',
    [
      'artisan',
      'migrate:fresh',
      '--seed',
      '--seeder=Database\\Seeders\\PlaywrightSeeder',
      '--no-interaction',
      '--no-ansi',
    ],
    {
      env: e2eEnv,
      stdio: 'inherit',
    },
  )
}
