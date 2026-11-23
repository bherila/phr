<?php

namespace App\Support\PHR;

use App\Services\PHR\Import\ValueCoercion;
use Illuminate\Support\Carbon;

/**
 * A visit's care team (issue #412) as one canonical list, whoever writes it: the agent
 * API, the browser, or an import.
 *
 * Each member's role is an HL7 v3 ParticipationType code: ATND attender, CON consultant,
 * ADM admitter, DIS discharger, PPRF primary performer, SPRF secondary performer, and
 * PART for anyone none of those fits (a nurse, a respiratory therapist, a technician),
 * with the source's own label beside it in `role_label`. `is_primary` is the source
 * saying the member is primary in their role; a standard source says so with a PPRF
 * code beside the member's own role, which is how exports write it too. A member whose
 * `period_end` has passed is no longer on the team, and stays listed. A period is
 * stored as UTC: a source's offset is converted, never dropped.
 *
 * The list's order is the source's order. Its digest is stored on the visit
 * (`participants_digest`), so a changed team changes the visit's version.
 */
final class PhrVisitParticipants
{
    public const string PARTICIPATION_TYPE_SYSTEM = 'http://terminology.hl7.org/CodeSystem/v3-ParticipationType';

    public const string PARTICIPATION_TYPE_OID = '2.16.840.1.113883.5.90';

    /** HL7 v3 ParticipationFunction, which C-CDA performers often state their function in. */
    public const string PARTICIPATION_FUNCTION_OID = '2.16.840.1.113883.5.88';

    public const string OTHER = 'PART';

    public const string PRIMARY = 'PPRF';

    /** @var array<string, string> v3 ParticipationType code => display */
    public const array ROLES = [
        'ATND' => 'attender',
        'CON' => 'consultant',
        'ADM' => 'admitter',
        'DIS' => 'discharger',
        'PPRF' => 'primary performer',
        'SPRF' => 'secondary performer',
        'PART' => 'Participation',
    ];

    /** ParticipationFunction codes that mean one of the roles above, and nothing more. */
    public const array FUNCTION_ROLES = [
        'ATTPHYS' => 'ATND',
        'ADMPHYS' => 'ADM',
        'DISPHYS' => 'DIS',
    ];

    /** @var array<string, int> each field a member carries, in stored order, and its column width */
    public const array FIELDS = [
        'external_id' => 255,
        'name' => 255,
        'credentials' => 100,
        'role_code' => 8,
        'role_label' => 255,
        'specialty' => 255,
        'is_primary' => 0,
        'period_start' => 0,
        'period_end' => 0,
    ];

    public const int MAX_MEMBERS = 100;

    /**
     * Each member a source names, in order, with every field present. A member with no
     * name is not a member; a role outside the vocabulary is PART, its words kept as the
     * label when the source gave none.
     *
     * @return list<array{external_id: string|null, name: string, credentials: string|null, role_code: string, role_label: string|null, specialty: string|null, is_primary: bool, period_start: string|null, period_end: string|null}>
     */
    public static function normalize(mixed $members): array
    {
        $normalized = [];
        foreach (is_array($members) ? $members : [] as $member) {
            if (! is_array($member)) {
                continue;
            }
            $name = self::text($member['name'] ?? null, self::FIELDS['name']);
            if ($name === null) {
                continue;
            }
            $code = is_string($member['role_code'] ?? null) ? strtoupper(trim($member['role_code'])) : null;
            $label = self::text($member['role_label'] ?? null, self::FIELDS['role_label']);
            if ($code !== null && $code !== '' && ! isset(self::ROLES[$code])) {
                $label ??= self::text($member['role_code'], self::FIELDS['role_label']);
                $code = null;
            }
            $normalized[] = [
                'external_id' => self::text($member['external_id'] ?? null, self::FIELDS['external_id']),
                'name' => $name,
                'credentials' => self::text($member['credentials'] ?? null, self::FIELDS['credentials']),
                'role_code' => $code === null || $code === '' ? self::OTHER : $code,
                'role_label' => $label,
                'specialty' => self::text($member['specialty'] ?? null, self::FIELDS['specialty']),
                'is_primary' => ValueCoercion::boolean($member['is_primary'] ?? null) ?? false,
                'period_start' => self::moment($member['period_start'] ?? null),
                'period_end' => self::moment($member['period_end'] ?? null),
            ];
            if (count($normalized) === self::MAX_MEMBERS) {
                break;
            }
        }

        return $normalized;
    }

    /**
     * What identifies a team's content. A visit with no members has none, which is what
     * every visit stored before the table existed holds.
     *
     * @param  list<array<string, mixed>>  $members  normalized
     */
    public static function digest(array $members): ?string
    {
        return $members === [] ? null : hash('sha256', json_encode($members, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * A member as a person is named on the visit: "Alex Attending, MD". A name that
     * already ends with its credentials is not given them twice.
     *
     * @param  array<string, mixed>  $member
     */
    public static function display(array $member): string
    {
        $name = trim((string) ($member['name'] ?? ''));
        $credentials = is_string($member['credentials'] ?? null) ? trim($member['credentials']) : '';
        if ($credentials === '' || preg_match('/[\s,]'.preg_quote($credentials, '/').'$/i', $name) === 1) {
            return $name;
        }

        return $name.', '.$credentials;
    }

    /**
     * The visit's provider: the primary attending, else the first attending, else the
     * first primary performer. A team with none of them (only nurses, say) names no
     * provider, and the visit keeps the one it has.
     *
     * @param  list<array<string, mixed>>  $members  normalized
     */
    public static function providerName(array $members): ?string
    {
        $pick = null;
        foreach ($members as $member) {
            if ($member['role_code'] === 'ATND' && $member['is_primary'] === true) {
                $pick = $member;
                break;
            }
        }
        foreach ($pick === null ? $members : [] as $member) {
            if ($member['role_code'] === 'ATND') {
                $pick = $member;
                break;
            }
        }
        foreach ($pick === null ? $members : [] as $member) {
            if ($member['role_code'] === self::PRIMARY) {
                $pick = $member;
                break;
            }
        }

        return $pick === null ? null : self::display($pick);
    }

    /**
     * A member's role from the codes a source gives it: the first role code other than
     * PPRF, which with another code says the member is primary in that role. PPRF alone
     * is the role. `$words` names the role when no code does: an attending, a consultant,
     * an admitting or a discharging clinician. Anything else is PART.
     *
     * @param  list<string>  $codes
     * @return array{role_code: string, is_primary: bool}
     */
    public static function role(array $codes, ?string $words = null): array
    {
        $codes = array_values(array_filter(array_map(static fn (string $code): string => strtoupper(trim($code)), $codes), static fn (string $code): bool => isset(self::ROLES[$code])));
        // "Primary nurse" is primary in their role; a primary care physician is a kind of role.
        $primary = in_array(self::PRIMARY, $codes, true) || ($words !== null && preg_match('/\bprimary\b(?!\s+care\b)/i', $words) === 1);
        foreach ($codes as $code) {
            if ($code !== self::PRIMARY) {
                return ['role_code' => $code, 'is_primary' => $primary];
            }
        }
        if ($codes !== []) {
            return ['role_code' => self::PRIMARY, 'is_primary' => true];
        }

        return ['role_code' => self::roleFromWords($words) ?? self::OTHER, 'is_primary' => $primary];
    }

    /** A role the words name unambiguously, or null. */
    public static function roleFromWords(?string $words): ?string
    {
        if ($words === null) {
            return null;
        }

        return match (true) {
            preg_match('/\battend(ing|er)\b/i', $words) === 1 => 'ATND',
            preg_match('/\bconsult(ant|ing)?\b/i', $words) === 1 => 'CON',
            preg_match('/\badmitt(ing|er)\b/i', $words) === 1 => 'ADM',
            preg_match('/\bdischarg(ing|er)\b/i', $words) === 1 => 'DIS',
            default => null,
        };
    }

    /**
     * Two sources' lists of one team as one: a member of `$more` already in `$members`
     * (the same name in the same role) fills what that entry leaves unsaid, and any
     * other is added after them.
     *
     * @param  list<array<string, mixed>>  $members  normalized
     * @param  list<array<string, mixed>>  $more  normalized
     * @return list<array<string, mixed>>
     */
    public static function merge(array $members, array $more): array
    {
        foreach ($more as $member) {
            $key = mb_strtolower($member['name']).'|'.$member['role_code'];
            foreach ($members as $index => $existing) {
                if (mb_strtolower($existing['name']).'|'.$existing['role_code'] !== $key) {
                    continue;
                }
                foreach ($member as $field => $value) {
                    if ($field === 'is_primary') {
                        $members[$index][$field] = $existing[$field] || $value;
                    } elseif ($existing[$field] === null) {
                        $members[$index][$field] = $value;
                    }
                }

                continue 2;
            }
            if (count($members) < self::MAX_MEMBERS) {
                $members[] = $member;
            }
        }

        return $members;
    }

    /**
     * A member's start or end as the UTC instant it names. Members have no precision or
     * zone columns, and every export states a stored time as UTC, so a time with an
     * offset ("2026-02-09T14:20:00-05:00") is converted rather than kept as its wall
     * clock. A time with no offset, and a date, stay as written.
     */
    private static function moment(mixed $value): ?string
    {
        $text = ValueCoercion::string($value);
        if ($text === null) {
            return null;
        }

        try {
            return Carbon::parse($text, 'UTC')->utc()->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function text(mixed $value, int $width): ?string
    {
        $text = ValueCoercion::string($value);

        return $text === null ? null : mb_substr($text, 0, $width);
    }
}
