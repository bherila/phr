# Where PHR keeps files

Each installation keeps every PHR file in one place, chosen by one setting. This covers
source documents, EHI and MyChart exports people upload, DICOM images and volume caches,
generated exports, native backups, upload staging and GenAI import staging.

| `PHR_STORAGE` | What happens |
|---|---|
| unset (or empty) | Nothing has been chosen. Each disk keeps its own setting (`PHR_DICOM_DISK_DRIVER`, `PHR_UPLOADS_DISK_DRIVER`, `S3_DISK_DRIVER`) and its code default. `phr_dicom` defaults to `s3` and the others to `local`. An existing installation stays exactly as it is until its operator chooses. |
| `local` | Every disk uses the local driver under `storage/app/private`. A disk's own `*_DISK_ROOT` setting can still move its directory, and must be an absolute path. |
| `s3` | Every disk uses one S3-compatible bucket, set by `PHR_STORAGE_S3_*`. The per-disk `PHR_DICOM_R2_*`, `PHR_UPLOADS_*`, `AWS_*` and `*_DISK_ROOT` settings are not read. |

An unknown value, or `s3` with no bucket, stops the application from loading, rather than
sending patient files somewhere nobody chose.

Disk names never change, so `storage_disk` and path columns need no rewrite when a move
happens. Only the driver behind each name changes.

## The layout: a move is a copy

A file's path under its local directory is its object key under the prefix of the same
name:

| Disk | Local directory | Object prefix | Holds |
|---|---|---|---|
| `phr_documents` | `storage/app/private/phr-documents` | `phr-documents/` | documents, EHI members, staged exports |
| `phr_dicom` | `storage/app/private/phr-dicom` | `phr-dicom/` | DICOM instances, volume caches |
| `phr_exports` | `storage/app/private/phr-exports` | `phr-exports/` | exports, native backups, restore sources |
| `phr_uploads` | `storage/app/private/phr-uploads` | `phr-uploads/` | signed document uploads awaiting finalize |
| `s3` | `storage/app/private/s3-blobs` | `s3-blobs/` | GenAI import staging |

So `storage/app/private/phr-documents/patients/1/documents/x/note.pdf` becomes
`s3://bucket/phr-documents/patients/1/documents/x/note.pdf`.

The prefixes also keep the disks apart. Each disk uses the same `patients/` key prefixes,
and the sweeps list those prefixes. Without separate prefixes, `phr:dicom:gc` would treat
every document as an orphan DICOM object and delete it. Don't put two disks, or another
application, under one prefix.

`phr_upload_spool` (`storage/app/private/phr-upload-spool`) stays local under every choice.
See [Uploads](#uploads).

## Before switching to S3-compatible storage

**Switching gives up host backups of these files unless something replaces them.** Blobs
were moved off object storage to local disk on purpose: an object store has durability but
no point-in-time recovery, so an application bug or an errant delete cannot be undone, and
local disk puts the files inside host backups. Before cutover, put in place a scheduled
copy to somewhere restorable, and test a restore from it. Keep in mind that `storage:prune`'s
quarantine holds only what the pruner itself removed, and that the database backup does
not contain the files.

Check these as well:

- **Credentials** need ListBucket, GetObject/HeadObject, PutObject, DeleteObject and
  CopyObject, plus multipart upload for large objects.
- **Temporary space.** ZIP intake needs a seekable file, so it copies the object to the
  system temporary directory first. That directory needs room for the largest package
  (`PHR_EHI_MAX_PACKAGE_BYTES`, 10 GB by default) and for native restore sources.
- **Incomplete multipart uploads.** A write that dies partway can leave parts the bucket
  still bills for and no listing shows. A bucket lifecycle rule that aborts them after a
  few days is worth adding.
- **Tested stores.** The live suite has run against Garage only. AWS S3 and Cloudflare R2
  use the same API, but this code has not been run against them.
- **Bucket CORS.** Browser uploads go straight to the bucket, so they fail until
  [its CORS rule](#bucket-cors-for-direct-uploads) is in place. Set it before the switch.

## Moving, in either direction

Whether any installation moves is its operator's decision. Nothing in the application
moves files, and no migration touches `storage_disk` or path columns.

The examples use [rclone](https://rclone.org), with a remote called `store` configured for
the bucket, but any tool that copies keys unchanged will do. `phr-store` below stands for
the bucket.

### 1. Stop new work and let in-flight work finish

Put the application in maintenance mode and let the queue drain. Workers pause in
maintenance mode unless they run with `--force`. Then stop them and the scheduler, which is
what runs the sweeps. The `in flight` column of `php artisan phr:storage:verify` should be 0
and `storage/app/private/phr-upload-spool` should be empty.

A signed direct upload URL stays valid for up to 15 minutes after it is issued. Rows left
over clear when `phr:document-uploads:gc`, `phr:ehi-uploads:purge` and
`phr:native-restores:purge` run, which you can do by hand, but an abandoned chunked upload
counts until it expires (48 hours by default). An upload still open at the switch fails and
has to be started again. That's safe, because the appender refuses a chunk that doesn't
continue what is stored, so you can decide not to wait for those.

### 2. Record the source

```sh
php artisan phr:storage:verify | tee storage-before.txt
```

The command only reads. It checks every object against the database (existence, size, and
hash wherever a row records one) and fails if anything is wrong. Fix what it reports first.
It reports table and row id, never a key, because a key can hold an original filename.

### 3. Copy each disk

Local to S3-compatible:

```sh
for d in phr-documents phr-dicom phr-exports phr-uploads s3-blobs; do
  rclone copy --checksum --s3-no-check-bucket "storage/app/private/$d" "store:phr-store/$d"
done
```

S3-compatible to local:

```sh
for d in phr-documents phr-dicom phr-exports phr-uploads s3-blobs; do
  rclone copy --checksum --s3-no-check-bucket "store:phr-store/$d" "storage/app/private/$d"
done
```

**Copy the quarantine too.** It sits at `_quarantine/` under each disk's root, so the loops
above include it. It is the only undo for a sweep that was wrong, and its holding period
is read from the batch name, so it carries on correctly in the new place.

If DICOM is already on a bucket with no prefix (`PHR_DICOM_DISK_DRIVER=s3` and no root),
copy from that bucket's top level to `phr-dicom/`. It contains only that disk's objects.

### 4. Verify the copy before changing anything

Add the new store's settings to `.env` without setting `PHR_STORAGE`. Then check the target
by setting the choice for one command only. `APP_CONFIG_CACHE` points at a file that doesn't
exist, so that a cached configuration can't hide the choice:

```sh
APP_CONFIG_CACHE=/tmp/phr-unused-config.php PHR_STORAGE=s3 php artisan phr:storage:verify | tee storage-after.txt
diff storage-before.txt storage-after.txt
```

Use `PHR_STORAGE=local` when moving back. The only differences you should see are the first
line and the `driver` column. If rows, bytes, objects, unreferenced objects or quarantined
objects differ, the copy is not whole.

### 5. Change the setting

Set `PHR_STORAGE` in `.env`, run `php artisan config:cache`, restart the workers and leave
maintenance mode.

### 6. Keep the old copy

Keep it until the new store has proven itself, and until the backups from
[Before switching](#before-switching-to-s3-compatible-storage) have been restored from at
least once. To go back, re-copy anything written since and repeat the steps from step 1.

Don't run `storage:prune --apply` until at least 24 hours after the copy. The copy resets
an object's age, so the pruner's age guard treats everything as too new until then. The
verify report's `unreferenced` column shows what it would consider.

## Pruning and sweeps

`storage:prune` moves objects no row references into `_quarantine/<date>/`, and a later
`--purge-quarantine=<days> --apply` deletes batches older than that. It stays a manual step
on both storage kinds. The hourly and daily collectors (`phr:dicom:gc`,
`phr:document-uploads:gc`, `phr:exports:purge`, `phr:native-backups:purge`,
`phr:native-restores:purge`, `phr:ehi-uploads:purge`) run from the schedule as before.

## Uploads

| Upload | Unset or `local` | `s3` |
|---|---|---|
| DICOM file | POST through PHP | one signed, length-bound PUT per file, straight to the bucket |
| EHI or MyChart ZIP | 8 MB chunks through PHP | multipart upload, straight to the bucket |
| Native restore source | chunks through PHP, in place | chunks through PHP, spooled |
| Agent document (`documents_upload_access_create`) | signed application endpoint | signed PUT, straight to the bucket |
| Agent DICOM file | POST through PHP | POST through PHP |

The transport follows `PHR_STORAGE` alone. An installation with it unset never sends a browser
to a bucket, even if its DICOM disk is on one.

**Every direct URL is signed with its exact length**, so the store refuses any other size.
A part's length comes from the file's declared size, and each part but the last is at
least 5 MiB, in at most 10,000 parts. The part size grows past the usual 8 MiB only for a file
too large for that to fit in 10,000 parts.

**DICOM.** A file's bytes go to a staging key under `imaging/dicom/incoming/`, beside the upload's
own prefix. No relative path can name that, and a signed URL stays usable until it expires, so it
must never be able to reach a durable key. To record the file, the application copies it to a key no URL has ever been signed for, and
everything comes from that copy: the size check, the metadata and SHA-256, and the object moved to
the key a POST would have used. So bytes no longer come *up*
through PHP, but each file is still read back once. A late PUT lands in staging, where
`phr:dicom:gc` removes it.

**EHI and MyChart.** Completing checks every part against the store's own list, and the finished
object against the declared size, before intake is queued. Intake then reads the object through
a local temporary copy.

**Patient deletion waits for signed URLs.** A store honours a URL until it expires, whatever the
application decided since, so a deletion is only complete once no signed write URL can still land
that patient's bytes. Each upload records how long its latest URL lives, and deletion is refused
until then, for at most 15 minutes after the last one. The final cleanup then removes what a late
PUT left. A PUT already running when its URL expires can still finish; `phr:dicom:gc` removes
anything like that within the hour.

**Incomplete uploads** hold bytes that no listing of objects shows. `phr:ehi-uploads:purge` and
`phr:dicom:gc` abort them, and patient deletion aborts everything under the patient's prefix, so
a lifecycle rule is optional.

**The spool.** Native restore sources still reach PHP in chunks. An object cannot be appended to,
so on an object store they wait in the local spool at the same key, and the queued job publishes
them as one object before reading them. The spool is not storage: it holds bytes on their way
somewhere, only until the job runs. The purge removes it, and it isn't part of a move. A spooled
upload keeps those bytes on local disk until it is published or expires.

### Bucket CORS for direct uploads

The bucket must allow PUT from the application's origin and expose `ETag`. The browser reads each
part's ETag to complete an upload. **The application cannot check this.** Without the rule, a
browser reports only a network error and PHR says to check the bucket's CORS rules. If `ETag`
alone is missing, the PUT succeeds and PHR says that CORS must expose it.

Set the origin to your own, with its scheme and no trailing slash:

```json
{
  "CORSRules": [
    {
      "AllowedOrigins": ["https://phr.example.test"],
      "AllowedMethods": ["PUT"],
      "AllowedHeaders": ["content-type"],
      "ExposeHeaders": ["ETag"],
      "MaxAgeSeconds": 3600
    }
  ]
}
```

Add `GET` to `AllowedMethods` only if `PHR_DICOM_VIEWER_DIRECT_SIGNED_URLS=true`.

- **Garage:** S3 API only. With the JSON above saved as `cors.json`:
  `aws s3api put-bucket-cors --endpoint-url https://garage.example.test --bucket phr-store --cors-configuration file://cors.json`.
  The key needs the owner permission on the bucket. This exact rule is what the live tests set.
- **AWS S3:** the same command with no `--endpoint-url`, or paste the contents of `CORSRules`
  into **Permissions → Cross-origin resource sharing (CORS)** in the console.
- **Cloudflare R2:** the same command with the account's R2 endpoint, or use the bucket's
  **Settings → CORS Policy** in the dashboard, which takes the contents of `CORSRules`.

Only Garage has been run. The rule is the S3 API's own, but check a real upload after setting it
on another store.

## Code rules

- Stored objects go through `Storage` and its streams. `FilesystemAdapter::path()` doesn't
  fail on an object store: it returns the key, which then reads as a relative path that
  doesn't exist. `LocalDiskAssumptionsTest` fails when a new local-file call appears.
- A reader that needs a seekable file uses `LocalObjectFile`. On a local disk that's the file
  itself. Otherwise it's a temporary copy.
- A read stream from an object store cannot seek and can return short reads. Use
  `stream_get_contents` or `stream_copy_to_stream`, not one `fread`.

## Testing against a real store

The default suite needs no server: `tests/Feature/Storage` runs on `NoLocalPathDisk`, which
throws on `path()`, cannot seek and returns short reads. Those same tests run against a real
store when `PHR_TEST_S3_ENDPOINT` selects one, and the signed-upload round trip runs only
then. Tests refuse any endpoint that isn't on this machine.

```sh
# A throwaway Garage node. See its quick-start for the bucket and key.
export PHR_TEST_S3_ENDPOINT=http://127.0.0.1:3900
export PHR_TEST_S3_BUCKET=phr-test PHR_TEST_S3_REGION=garage
export PHR_TEST_S3_KEY=<key id> PHR_TEST_S3_SECRET=<secret>
# Optional: make ManualStorageMoveTest copy with the rclone commands above.
export PHR_TEST_RCLONE="$(command -v rclone)"
php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Storage
```

The `storage-live` workflow runs this against Garage, along with a real browser's direct uploads
(`PHR_E2E_S3_ENDPOINT`, in `tests/e2e/phr-direct-uploads.spec.ts`). It doesn't gate deploys.
