<?php

namespace App\Services\PHR\Import\Ehi\Mapping\Immunization;

/**
 * Strict, timezone-free parsing of EHI date/time text for the immunization mapping.
 *
 * Nothing here consults the application or server timezone: the text is split
 * into its calendar date and wall-clock time exactly as written, and the
 * precision is what the text itself carries. Anything outside the accepted
 * shapes is unparseable rather than guessed at.
 *
 * Accepted date-time shapes (no offset or zone suffix, which the documented
 * column types never carry):
 *   YYYY-MM-DD                      -> date
 *   YYYY-MM-DD[ T]HH:MM             -> minute (24-hour)
 *   YYYY-MM-DD[ T]HH:MM:SS          -> second (24-hour)
 *   M/D/YYYY                        -> date
 *   M/D/YYYY H:MM[:SS]              -> minute|second (24-hour)
 *   M/D/YYYY h:MM[:SS] AM|PM        -> minute|second (12-hour)
 *
 * Accepted time-only shapes: HH:MM[:SS] (24-hour) and h:MM[:SS] AM|PM.
 */
final class EhiImmunizationDateText
{
    public const string PRECISION_DATE = 'date';

    public const string PRECISION_MINUTE = 'minute';

    public const string PRECISION_SECOND = 'second';

    private const string TIME_PATTERN = '(?<hour>\d{1,2}):(?<minute>\d{2})(?::(?<second>\d{2}))?(?:\s?(?<meridiem>AM|PM|am|pm))?';

    /**
     * The calendar date (Y-m-d), the wall-clock time if written (H:i or H:i:s),
     * and the precision the text carries; null when the text is unparseable.
     *
     * @return array{date: string, time: string|null, precision: string}|null
     */
    public static function parseDateTime(string $text): ?array
    {
        $text = trim($text);
        $matched = preg_match('/^(?<y>\d{4})-(?<m>\d{2})-(?<d>\d{2})(?:[ T](?<time>.+))?$/', $text, $parts) === 1
            || preg_match('/^(?<m>\d{1,2})\/(?<d>\d{1,2})\/(?<y>\d{4})(?: (?<time>.+))?$/', $text, $parts) === 1;
        if (! $matched) {
            return null;
        }

        $year = (int) $parts['y'];
        $month = (int) $parts['m'];
        $day = (int) $parts['d'];
        if ($year < 1 || ! checkdate($month, $day, $year)) {
            return null;
        }
        $date = sprintf('%04d-%02d-%02d', $year, $month, $day);

        $timeText = $parts['time'] ?? '';
        if ($timeText === '') {
            return ['date' => $date, 'time' => null, 'precision' => self::PRECISION_DATE];
        }

        $time = self::parseTime($timeText);
        if ($time === null) {
            return null;
        }

        return ['date' => $date, 'time' => $time['time'], 'precision' => $time['precision']];
    }

    /**
     * @return array{time: string, precision: string}|null
     */
    public static function parseTime(string $text): ?array
    {
        if (preg_match('/^'.self::TIME_PATTERN.'$/', trim($text), $parts) !== 1) {
            return null;
        }

        $hour = (int) $parts['hour'];
        $minute = (int) $parts['minute'];
        $hasSecond = isset($parts['second']) && $parts['second'] !== '';
        $second = $hasSecond ? (int) $parts['second'] : 0;
        $meridiem = strtoupper($parts['meridiem'] ?? '');

        if ($meridiem !== '') {
            if ($hour < 1 || $hour > 12) {
                return null;
            }
            $hour = ($hour % 12) + ($meridiem === 'PM' ? 12 : 0);
        } elseif ($hour > 23) {
            return null;
        }
        if ($minute > 59 || $second > 59) {
            return null;
        }

        return $hasSecond
            ? ['time' => sprintf('%02d:%02d:%02d', $hour, $minute, $second), 'precision' => self::PRECISION_SECOND]
            : ['time' => sprintf('%02d:%02d', $hour, $minute), 'precision' => self::PRECISION_MINUTE];
    }
}
