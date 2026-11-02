import { generateKeyPairSync } from 'node:crypto'
import path from 'node:path'

const projectRoot = process.cwd()

export const e2eDatabasePath = path.join(projectRoot, 'storage/framework/testing/playwright.sqlite')
export const e2eStorageRoot = path.join(projectRoot, 'storage/framework/testing/playwright-storage')

// /up fails unless Passport can load its signing keys. An ephemeral pair passed in
// env leaves any local key files untouched.
const oauthKeys = generateKeyPairSync('rsa', {
  modulusLength: 2048,
  publicKeyEncoding: { type: 'spki', format: 'pem' },
  privateKeyEncoding: { type: 'pkcs8', format: 'pem' },
})

// A run against a real store: PHR_STORAGE=s3 with the browser's real CORS and ETag. Off this
// machine it is refused, like the PHPUnit live suite, because these tests write and delete.
const liveStore = process.env.PHR_E2E_S3_ENDPOINT
if (liveStore && !['127.0.0.1', '[::1]', 'localhost'].includes(new URL(liveStore).hostname)) {
  throw new Error('SAFETY ERROR: Playwright must use a local store.')
}
export const e2eLiveStore = liveStore
  ? {
      endpoint: liveStore,
      bucket: process.env.PHR_E2E_S3_BUCKET ?? '',
      region: process.env.PHR_E2E_S3_REGION ?? 'garage',
      key: process.env.PHR_E2E_S3_KEY ?? '',
      secret: process.env.PHR_E2E_S3_SECRET ?? '',
    }
  : null

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
  PASSPORT_PRIVATE_KEY: oauthKeys.privateKey,
  PASSPORT_PUBLIC_KEY: oauthKeys.publicKey,
  PHR_DICOM_DISK_DRIVER: 'local',
  PHR_DICOM_DISK_ROOT: path.join(e2eStorageRoot, 'dicom'),
  PHR_DOCUMENTS_DISK_ROOT: path.join(e2eStorageRoot, 'documents'),
  PHR_EXPORTS_DISK_ROOT: path.join(e2eStorageRoot, 'exports'),
  QUEUE_CONNECTION: 'sync',
  SESSION_DRIVER: 'file',
  ...(e2eLiveStore
    ? {
        PHR_STORAGE: 's3',
        PHR_STORAGE_S3_ACCESS_KEY_ID: e2eLiveStore.key,
        PHR_STORAGE_S3_SECRET_ACCESS_KEY: e2eLiveStore.secret,
        PHR_STORAGE_S3_REGION: e2eLiveStore.region,
        PHR_STORAGE_S3_BUCKET: e2eLiveStore.bucket,
        PHR_STORAGE_S3_ENDPOINT: e2eLiveStore.endpoint,
        PHR_STORAGE_S3_USE_PATH_STYLE_ENDPOINT: 'true',
      }
    : {}),
}
