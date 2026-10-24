<?php

namespace App\Services\PHR\Access;

use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Js;
use JsonException;

final readonly class PhrPatientListService
{
    public const int BOOTSTRAP_MAX_PATIENTS = 100;

    public const int BOOTSTRAP_MAX_BYTES = 128 * 1024;

    public function __construct(private PhrPatientPresenter $presenter) {}

    /** @return array{patients: list<array<string, mixed>>} */
    public function payload(int $userId): array
    {
        return $this->present($this->query($userId), $userId);
    }

    /**
     * An omitted bootstrap falls back to the existing API, never a partial list.
     * Count and raw-size checks avoid hydrating a large profile/share graph just
     * to discover that it cannot fit in the HTML response.
     *
     * @return array{patients: list<array<string, mixed>>}|null
     */
    public function bootstrapPayload(int $userId): ?array
    {
        $ids = $this->query($userId)->limit(self::BOOTSTRAP_MAX_PATIENTS + 1)->toBase()->pluck('id')->all();
        if (count($ids) > self::BOOTSTRAP_MAX_PATIENTS) {
            return null;
        }
        if ($ids === []) {
            return ['patients' => []];
        }

        $profileLengths = array_map($this->byteLength(...), ['display_name', 'relationship', 'sex_at_birth', 'notes']);
        $profileSize = PhrPatient::query()->whereIn('id', $ids)->toBase()
            ->selectRaw('COALESCE(SUM('.implode(' + ', $profileLengths).'), 0) AS payload_size')
            ->value('payload_size');
        $grantLengths = array_map($this->byteLength(...), ['users.name', 'users.email', 'access_level']);
        $grantSize = PhrPatientUserAccess::query()->whereIn('patient_id', $ids)
            ->leftJoin('users', 'users.id', '=', 'phr_patient_user_access.user_id')
            ->toBase()
            // A conservative hydration budget includes hidden grants too;
            // shared readers still receive the unchanged access_grants: [] DTO.
            // The fixed allowance bounds share fanout before model hydration.
            ->selectRaw('COALESCE(SUM(80 + '.implode(' + ', $grantLengths).'), 0) AS payload_size')
            ->value('payload_size');
        if ((int) $profileSize + (int) $grantSize > self::BOOTSTRAP_MAX_BYTES) {
            return null;
        }

        $payload = $this->present($this->query($userId)->whereIn('id', $ids), $userId);
        try {
            // Js::encode replaces invalid UTF-8, which would change profile text.
            // Reject that bootstrap instead and retain the ordinary API fallback.
            json_encode($payload, JSON_THROW_ON_ERROR);
            $encoded = Js::encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            return null;
        }

        return strlen($encoded) <= self::BOOTSTRAP_MAX_BYTES ? $payload : null;
    }

    private function byteLength(string $column): string
    {
        $value = "COALESCE({$column}, '')";

        return match ((new PhrPatient)->getConnection()->getDriverName()) {
            'sqlite' => "LENGTH(CAST({$value} AS BLOB))",
            'sqlsrv' => "DATALENGTH({$value})",
            default => "OCTET_LENGTH({$value})",
        };
    }

    /** @return Builder<PhrPatient> */
    private function query(int $userId): Builder
    {
        return PhrPatient::query()->accessibleBy($userId)
            ->orderBy('owner_user_id')
            ->orderBy('display_name');
    }

    /**
     * @param  Builder<PhrPatient>  $query
     * @return array{patients: list<array<string, mixed>>}
     */
    private function present(Builder $query, int $userId): array
    {
        return ['patients' => $query->with(['accessGrants.user:id,name,email'])->get()
            ->map(fn (PhrPatient $patient): array => $this->presenter->payload($patient, $userId))
            ->values()->all()];
    }
}
