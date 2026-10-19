<?php

namespace App\Services\PHR\Import\Ehi;

use App\Models\PhrEhiPackage;
use App\Models\PhrEhiSource;
use App\Models\PhrPatient;
use App\Services\PHR\Access\PhrPatientAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Binds a preserved, accounted package to a declared source installation. The
 * source is always an explicit choice: a matching source patient key and
 * dictionary fingerprint only rank suggestions, they never bind on their own.
 */
final readonly class EhiBindingService
{
    private const int MAX_LABEL_CHARS = 120;

    public function __construct(private PhrPatientAccessService $access) {}

    /**
     * Sources of the same patient declaring the package's source patient key,
     * dictionary-fingerprint matches first.
     *
     * @return Collection<int, PhrEhiSource>
     */
    public function suggestions(PhrEhiPackage $package): Collection
    {
        $key = $package->detected_source_patient_key;
        if ($key === null) {
            return new Collection;
        }

        $fingerprint = $package->dictionary_fingerprint;

        // Compared again in PHP: a case-insensitive collation must not widen the match.
        return PhrEhiSource::query()
            ->where('patient_id', $package->patient_id)
            ->where('source_patient_key', $key)
            ->orderBy('id')
            ->get()
            ->filter(static fn (PhrEhiSource $source): bool => hash_equals($source->source_patient_key, $key))
            ->sortBy(static fn (PhrEhiSource $source): array => [
                $fingerprint !== null && $source->dictionary_fingerprint === $fingerprint ? 0 : 1,
                $source->id,
            ])
            ->values();
    }

    /**
     * @throws EhiBindingRefused
     * @throws AuthorizationException the actor does not own the patient
     */
    public function bind(PhrEhiPackage $package, PhrPatient $patient, int $actorUserId, ?int $existingSourceId, ?string $newSourceLabel): PhrEhiPackage
    {
        $this->access->ensureOwner($patient, $actorUserId);
        if (($existingSourceId === null) === ($newSourceLabel === null)) {
            throw new EhiBindingRefused(EhiBindingRefused::CHOICE_REQUIRED, 'choose exactly one of an existing source or a new source label');
        }
        $label = $newSourceLabel === null ? null : $this->label($newSourceLabel);

        return DB::transaction(function () use ($package, $patient, $actorUserId, $existingSourceId, $label): PhrEhiPackage {
            // Patient first, then package (the intake guard's order): two
            // bindings of one patient's packages serialize on the label check.
            PhrPatient::query()->whereKey($patient->id)->lockForUpdate()->firstOrFail();
            $locked = PhrEhiPackage::query()
                ->whereKey($package->id)
                ->where('patient_id', $patient->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->preservation_status !== PhrEhiPackage::PRESERVATION_PRESERVED
                || $locked->accounting_status !== PhrEhiPackage::ACCOUNTING_ACCOUNTED) {
                throw new EhiBindingRefused(EhiBindingRefused::NOT_READY, 'the package is not preserved and accounted');
            }
            $key = $locked->detected_source_patient_key;
            if ($key === null || $key === '') {
                throw new EhiBindingRefused(EhiBindingRefused::NO_SOURCE_PATIENT_KEY, 'the package has no source patient key');
            }

            if ($locked->binding_status === PhrEhiPackage::BINDING_BOUND && $locked->source_id !== null) {
                $bound = PhrEhiSource::query()->whereKey($locked->source_id)->first();
                $same = $existingSourceId !== null
                    ? (int) $locked->source_id === $existingSourceId
                    : $bound !== null && $bound->label === $label;
                if (! $same) {
                    throw new EhiBindingRefused(EhiBindingRefused::ALREADY_BOUND, 'the package is already bound to another source');
                }

                return $locked;
            }

            if ($existingSourceId !== null) {
                $source = PhrEhiSource::query()
                    ->whereKey($existingSourceId)
                    ->where('patient_id', $patient->id)
                    ->lockForUpdate()
                    ->first();
                if ($source === null) {
                    throw new EhiBindingRefused(EhiBindingRefused::SOURCE_NOT_FOUND, 'the source does not exist for this patient');
                }
                if (! hash_equals($source->source_patient_key, $key)) {
                    throw new EhiBindingRefused(EhiBindingRefused::SOURCE_KEY_MISMATCH, 'the source declares a different source patient key');
                }
            } else {
                if (PhrEhiSource::query()->where('patient_id', $patient->id)->where('label', $label)->exists()) {
                    throw new EhiBindingRefused(EhiBindingRefused::LABEL_TAKEN, 'a source with that label already exists; bind to it instead');
                }
                try {
                    $source = PhrEhiSource::query()->create([
                        'patient_id' => $patient->id,
                        'created_by_user_id' => $actorUserId,
                        'label' => $label,
                        'source_patient_key' => $key,
                        'dictionary_fingerprint' => $locked->dictionary_fingerprint,
                    ]);
                } catch (UniqueConstraintViolationException) {
                    // A collation may equate labels the check above did not.
                    throw new EhiBindingRefused(EhiBindingRefused::LABEL_TAKEN, 'a source with that label already exists; bind to it instead');
                }
            }

            $locked->forceFill([
                'source_id' => $source->id,
                'bound_by_user_id' => $actorUserId,
                'bound_at' => now(),
                'binding_status' => PhrEhiPackage::BINDING_BOUND,
            ])->save();

            return $locked;
        });
    }

    private function label(string $label): string
    {
        $label = trim($label);
        if ($label === ''
            || mb_strlen($label) > self::MAX_LABEL_CHARS
            || ! mb_check_encoding($label, 'UTF-8')
            || preg_match('/[\x00-\x1F\x7F]/', $label) === 1) {
            throw new EhiBindingRefused(EhiBindingRefused::LABEL_INVALID, sprintf('a source label must be 1-%d printable characters', self::MAX_LABEL_CHARS));
        }

        return $label;
    }
}
