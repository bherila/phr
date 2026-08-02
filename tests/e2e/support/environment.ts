import path from 'node:path'

const projectRoot = process.cwd()

export const e2eDatabasePath = path.join(projectRoot, 'storage/framework/testing/playwright.sqlite')
export const e2eStorageRoot = path.join(projectRoot, 'storage/framework/testing/playwright-storage')

export const e2eEnv: Record<string, string> = {
  ...Object.fromEntries(Object.entries(process.env).filter((entry): entry is [string, string] => entry[1] !== undefined)),
  APP_ENV: 'testing',
  APP_DEBUG: 'false',
  APP_KEY: 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
  APP_URL: 'http://127.0.0.1:4173',
  CACHE_STORE: 'array',
  DB_CONNECTION: 'sqlite',
  DB_DATABASE: e2eDatabasePath,
  FILESYSTEM_DISK: 'local',
  LOG_CHANNEL: 'stderr',
  MAIL_MAILER: 'array',
  OAUTH_CLIENT_ID: 'playwright-client',
  OAUTH_CLIENT_SECRET: 'playwright-client-secret',
  OAUTH_PROVIDER: 'playwright',
  OAUTH_PROVIDER_URL: 'http://127.0.0.1:4174',
  OAUTH_REDIRECT_URI: 'http://127.0.0.1:4173/oauth/callback',
  PHR_DICOM_DISK_DRIVER: 'local',
  PHR_DICOM_DISK_ROOT: path.join(e2eStorageRoot, 'dicom'),
  PHR_DOCUMENTS_DISK_ROOT: path.join(e2eStorageRoot, 'documents'),
  PHR_EXPORTS_DISK_ROOT: path.join(e2eStorageRoot, 'exports'),
  QUEUE_CONNECTION: 'sync',
  SESSION_DRIVER: 'file',
}
