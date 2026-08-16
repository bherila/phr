<?php

namespace App\Support\Storage;

/**
 * PHR's declaration of what references stored objects, and where they live.
 *
 * Deliberately app-local. Each app owns a disjoint storage root — PHR's is
 * `phr-laravel/storage/app/private/`, the finance app's is `bwh-php/storage/app/private/`
 * — so a shared map would let one app's pruner reason about data it cannot see. The
 * engine (BlobReferences, StoragePruner) is generic; only this file is PHR's.
 *
 * Verified against the live `bherila_phr` schema on 2026-08-02 via information_schema
 * rather than by grepping migrations. That distinction is not academic: the finance app
 * stores utility bills in `utility_bill.pdf_s3_path`, a column that a pattern anchored on
 * `s3_path%` never matches, and missing it would have condemned every utility bill.
 */
class PhrStorageMap
{
    /**
     * The disks to sweep, and the key prefixes to sweep within each.
     *
     * PHR spreads blobs across four disks, each with its own filesystem root:
     * `phr_dicom` (storage/app/private/phr-dicom), `phr_documents`, `phr_exports`, and the
     * GenAI staging disk still named `s3` (storage/app/private/s3-blobs).
     * New durable writes use a patient-first `patients/{id}/...` hierarchy. Legacy
     * prefixes remain listed throughout the migration and rollback window so the
     * pruner cannot mistake pre-migration objects for garbage. Sweeping one disk with
     * another's prefixes finds nothing — hence the disk/prefix pairing.
     *
     * There is intentionally no option to widen this at the command line. A
     * caller-supplied prefix is how the finance app's `orphans:delete --prefix=` can be
     * aimed at an entire bucket.
     *
     * @return array<string, list<string>> disk name => key prefixes
     */
    public static function disks(): array
    {
        return [
            'phr_dicom' => ['patients', 'phr/dicom', 'derived/volume-cache'],
            'phr_documents' => ['patients', 'phr/documents'],
            'phr_exports' => ['patients', 'phr/exports', 'phr/native-backups', 'phr/native-restores'],

            // GenAI import staging. Its only writer is PhrDocumentController::process,
            // which keys everything under genai-import/<userId>/. Scoped to that prefix
            // rather than the whole disk so a future disk-sharing caller is not swept up
            // by a map that never heard of it.
            's3' => ['genai-import'],
        ];
    }

    public static function references(): BlobReferences
    {
        return BlobReferences::make()
            // DICOM pixel data: one row per stored object.
            ->from('phr_dicom_files', 'r2_key')

            // An upload's whole directory. Objects can land here before the per-file rows
            // exist, so the prefix protects a pending upload as a unit. Once processing
            // finishes (successfully or not), only per-file rows remain authoritative;
            // otherwise a terminal upload row would protect partial leftovers forever.
            ->from('phr_dicom_uploads', 'r2_prefix')->asPrefix()->where('status', 'pending')

            // Clinical documents. Soft-deleted rows still count: BlobReferences queries
            // through DB::table(), so the SoftDeletes scope never applies and a trashed
            // row keeps its bytes until the row is hard-deleted.
            ->from('phr_documents', 'storage_path')

            ->from('phr_exports', 'storage_path')

            // Native archives have a shorter retention policy but share the existing
            // private export disk. A live row protects its archive from quarantine.
            ->from('phr_native_backups', 'storage_path')

            // Uploaded native restore sources are short-lived operational inputs.
            ->from('phr_native_restore_attempts', 'source_storage_path')

            // A successful canonical-key migration deliberately retains both copies
            // for rollback. These ledger references keep either side out of generic
            // quarantine until the separate cleanup phase marks the legacy copy gone.
            ->from('phr_blob_migrations', 'source_key')->where('legacy_deleted_at', null)
            ->from('phr_blob_migrations', 'destination_key')->where('legacy_deleted_at', null)

            // Patient deletion commits the database graph first, then retries exact
            // artifact cleanup. Durable work rows protect those keys in between.
            ->from('phr_patient_deletion_artifacts', 'storage_key')

            // Staging for GenAI imports, on the disk named `s3`. Empty in production today,
            // but the column exists and must be honoured rather than assumed dead.
            //
            // In bwh-php this same column also stores `inline://paste/<uuid>` sentinels for
            // pasted content, which are not storage keys. Harmless either way — a sentinel
            // matches no file, so it neither protects nor reaps anything.
            ->from('genai_import_jobs', 's3_path')

            // Columns that match a key-ish name but hold no storage key. Listed so the
            // coverage test can distinguish "considered and excluded" from "overlooked".
            // The path *inside* an upload (e.g. "VOLUME/IM0001"), preserved to reconstruct
            // the original directory tree. Not a disk key — the disk key is r2_key, which
            // is this value prefixed with the upload's r2_prefix.
            ->ignoring('phr_dicom_files', 'original_relative_path', because: 'path within an upload, not a disk key')
            ->ignoring('auth_passkeys', 'public_key', because: 'WebAuthn credential, not a storage key')
            ->ignoring('users', 'gemini_api_key', because: 'API credential')
            ->ignoring('users', 'mcp_api_key', because: 'API credential')
            ->ignoring('user_ai_configurations', 'api_key', because: 'API credential');
    }
}
