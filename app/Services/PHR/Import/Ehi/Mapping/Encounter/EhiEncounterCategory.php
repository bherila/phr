<?php

namespace App\Services\PHR\Import\Ehi\Mapping\Encounter;

/**
 * Which kind of event a PAT_ENC row records, from what the row states, folded.
 *
 * APPT_STATUS_C_NAME has documented categories (Scheduled, Completed, Canceled, No
 * Show, Left without seen, Arrived) and organizations may add values. The public
 * PAT_ENC page documents no encounter-type column, so ENC_TYPE_C_NAME is read when a
 * package supplies it and never assumed. Its values vary by organization: this lists
 * the ones whose meaning is plain, and anything else is `unknown`, never guessed at.
 * With no type, a completed or arrived appointment or a hospital admission time says
 * the event happened; a row stating none of these is `unknown`.
 *
 * A telephone or video visit is a visit. A plain telephone encounter may be one, or a
 * staff note about a call, so `telephone` is offered as a visit for a person to choose.
 * Its status counts the same way: a call still scheduled or marked invalid is not one.
 */
final class EhiEncounterCategory
{
    public const string CLINICAL = 'clinical';

    public const string TELEPHONE = 'telephone';

    public const string PORTAL = 'portal';

    public const string CANCELLED = 'cancelled';

    public const string ADMINISTRATIVE = 'administrative';

    public const string UNKNOWN = 'unknown';

    private const array TYPES = [
        'office visit' => self::CLINICAL,
        'hospital encounter' => self::CLINICAL,
        'inpatient' => self::CLINICAL,
        'emergency' => self::CLINICAL,
        'urgent care' => self::CLINICAL,
        'procedure visit' => self::CLINICAL,
        'procedure' => self::CLINICAL,
        'surgery' => self::CLINICAL,
        'consult' => self::CLINICAL,
        'telemedicine' => self::CLINICAL,
        'video visit' => self::CLINICAL,
        'telephone visit' => self::CLINICAL,
        'phone visit' => self::CLINICAL,
        'telephone' => self::TELEPHONE,
        'telephone encounter' => self::TELEPHONE,
        'patient message' => self::PORTAL,
        'portal message' => self::PORTAL,
        'mychart encounter' => self::PORTAL,
        'mychart message' => self::PORTAL,
        'orders only' => self::ADMINISTRATIVE,
        'refill' => self::ADMINISTRATIVE,
        'scanned document' => self::ADMINISTRATIVE,
        'letter' => self::ADMINISTRATIVE,
        'documentation' => self::ADMINISTRATIVE,
        'registration' => self::ADMINISTRATIVE,
        'billing' => self::ADMINISTRATIVE,
        'charge' => self::ADMINISTRATIVE,
        'claim' => self::ADMINISTRATIVE,
        'wait list' => self::ADMINISTRATIVE,
        'history' => self::ADMINISTRATIVE,
    ];

    /** A type that says nothing beyond its status. */
    private const array NEUTRAL_TYPES = ['appointment'];

    private const array CANCELLED_STATUSES = ['canceled', 'cancelled', 'no show', 'left without seen'];

    private const array HAPPENED_STATUSES = ['completed', 'arrived'];

    private const array NOT_YET_STATUSES = ['scheduled'];

    /** One of the category constants above. */
    public static function of(?string $type, ?string $appointmentStatus, ?string $calculatedStatus, bool $hasAdmissionTime): string
    {
        $type = self::fold($type);
        $status = self::fold($appointmentStatus);
        if ($status !== null && in_array($status, self::CANCELLED_STATUSES, true)) {
            return self::CANCELLED;
        }

        $typed = $type === null ? null : (self::TYPES[$type] ?? null);
        if ($type !== null && $typed === null && ! in_array($type, self::NEUTRAL_TYPES, true)) {
            return self::UNKNOWN;
        }
        if ($typed !== null && ! self::canBeAVisit($typed)) {
            return $typed;
        }
        if (self::fold($calculatedStatus) === 'invalid' || ($status !== null && in_array($status, self::NOT_YET_STATUSES, true))) {
            return self::ADMINISTRATIVE;
        }
        if ($status !== null && ! in_array($status, self::HAPPENED_STATUSES, true)) {
            return self::UNKNOWN;
        }
        if ($typed === self::TELEPHONE) {
            return self::TELEPHONE;
        }

        return $typed === self::CLINICAL || $status !== null || $hasAdmissionTime ? self::CLINICAL : self::UNKNOWN;
    }

    /** Whether a person can be offered this category as a visit. */
    public static function canBeAVisit(string $category): bool
    {
        return $category === self::CLINICAL || $category === self::TELEPHONE;
    }

    private static function fold(?string $text): ?string
    {
        $text = $text === null ? '' : mb_strtolower(trim($text));

        return $text === '' ? null : $text;
    }
}
