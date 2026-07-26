<?php

namespace App\Models;

use App\Traits\SerializesDatesAsLocal;
use Database\Factories\PhrSinusSettingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Sinus Sentinel detection settings for one patient, synced last-write-wins
 * across that user's devices.
 *
 * @property int $id
 * @property int $phr_patient_id
 * @property array<string, string> $settings
 * @property Carbon $settings_updated_at
 * @property Carbon $received_at
 * @property string|null $updated_by_device
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PhrSinusSetting extends Model
{
    /** @use HasFactory<PhrSinusSettingFactory> */
    use HasFactory;

    use SerializesDatesAsLocal;

    /**
     * Keys the device is allowed to sync. Everything else in the document is
     * dropped on write: settings are shared across a user's machines, so
     * device-local concerns (server URL, patient id, device id, model path) and
     * per-machine network policy (sync mode — a metered laptop and a desktop
     * legitimately differ) must never travel.
     *
     * @var list<string>
     */
    public const SYNCED_KEYS = [
        'sensitivity',
        'quiet_start',
        'quiet_end',
    ];

    /**
     * How far ahead of the server's clock a client timestamp may be before it is
     * rejected. Last-write-wins on client clocks means a device with a fast
     * clock would otherwise win every race permanently and silently.
     */
    public const MAX_CLOCK_SKEW_MINUTES = 5;

    protected $table = 'phr_sinus_settings';

    protected $fillable = [
        'phr_patient_id',
        'settings',
        'settings_updated_at',
        'received_at',
        'updated_by_device',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'phr_patient_id' => 'integer',
            'settings' => 'array',
            'settings_updated_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    /**
     * Drop any key the device is not allowed to sync, and normalise values to
     * strings.
     *
     * Validation accepts `numeric`/`integer`, which a JSON number satisfies —
     * but the device deserialises this document as a string map, so storing
     * `0.7` rather than `"0.7"` would make every one of its flushes fail to
     * parse. Normalising here keeps the stored document to one shape regardless
     * of what a client sends. Nulls are dropped rather than stringified.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, string>
     */
    public static function filterSyncedKeys(array $settings): array
    {
        $allowed = array_intersect_key($settings, array_flip(self::SYNCED_KEYS));

        $normalised = [];

        foreach ($allowed as $key => $value) {
            if ($value === null) {
                continue;
            }

            $normalised[$key] = match (true) {
                is_bool($value) => $value ? '1' : '0',
                is_scalar($value) => (string) $value,
                default => throw new InvalidArgumentException(
                    "Setting [{$key}] must be a scalar value.",
                ),
            };
        }

        return $normalised;
    }

    /** @return BelongsTo<PhrPatient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(PhrPatient::class, 'phr_patient_id');
    }

    /**
     * @param  Builder<PhrSinusSetting>  $query
     * @return Builder<PhrSinusSetting>
     */
    public function scopeForPatient(Builder $query, int $patientId): Builder
    {
        return $query->where('phr_patient_id', $patientId);
    }
}
