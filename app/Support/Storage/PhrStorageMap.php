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
     * PHR spreads blobs across three disks, each with its own filesystem root:
     * `phr_dicom` (storage/app/private/phr-dicom), `phr_documents`, and `phr_exports`.
     * Keys additionally carry a `phr/<area>/` prefix inside their disk, inherited from the
     * R2 key shape and retained through the migration so no stored path had to be rewritten.
     * Sweeping one disk with another's prefixes finds nothing — hence the pairing.
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
            // `derived/volume-cache` sits outside the per-upload prefix, so it needs
            // listing explicitly or reclaimed volumes accumulate forever.
            'phr_dicom' => ['phr/dicom', 'derived/volume-cache'],
            'phr_documents' => ['phr/documents'],
            'phr_exports' => ['phr/exports'],
        ];
    }

    public static function references(): BlobReferences
    {
        return BlobReferences::make()
            // DICOM pixel data: one row per stored object.
            ->from('phr_dicom_files', 'r2_key')

            // An upload's whole directory. Objects can land here before the per-file rows
            // exist, so the prefix protects an in-flight upload as a unit.
            ->from('phr_dicom_uploads', 'r2_prefix')->asPrefix()

            // Clinical documents. Soft-deleted rows still count: BlobReferences queries
            // through DB::table(), so the SoftDeletes scope never applies and a trashed
            // row keeps its bytes until the row is hard-deleted.
            ->from('phr_documents', 'storage_path')

            ->from('phr_exports', 'storage_path')

            // Staging for GenAI imports. Empty in production today, but the column exists
            // and must be honoured rather than assumed dead.
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
