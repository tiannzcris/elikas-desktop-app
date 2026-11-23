<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * How dates, durations and numbers read everywhere in the app (the same
 * as the web): "Nov 19, 1:44 AM" in Philippine time (the year only when
 * it is not this year), "3 days", "1,234".
 */
final class Format
{
    public const TIMEZONE = 'Asia/Manila';

    /** "Nov 19, 1:44 AM"; "Dec 30, 2025, 9:05 PM" for another year; '' for nothing. */
    public static function when(CarbonInterface|string|null $at): string
    {
        if ($at === null || $at === '') {
            return '';
        }
        $local = ($at instanceof CarbonInterface ? Carbon::instance($at) : Carbon::parse($at))->timezone(self::TIMEZONE);

        return $local->year === now(self::TIMEZONE)->year ? $local->format('M j, g:i A') : $local->format('M j, Y, g:i A');
    }

    /** "Nov 19" (a date without a time), with the year when it is not this year. */
    public static function day(CarbonInterface|string|null $at): string
    {
        if ($at === null || $at === '') {
            return '';
        }
        $local = ($at instanceof CarbonInterface ? Carbon::instance($at) : Carbon::parse($at))->timezone(self::TIMEZONE);

        return $local->year === now(self::TIMEZONE)->year ? $local->format('M j') : $local->format('M j, Y');
    }

    /**
     * A length of time in words: "less than a minute", "45 minutes",
     * "1 hour 20 minutes", "3 hours", "3 days".
     */
    public static function duration(int|float|null $minutes): string
    {
        $minutes = max(0, (int) round((float) $minutes));

        if ($minutes < 1) {
            return 'less than a minute';
        }
        if ($minutes < 60) {
            return Plural::of($minutes, 'minute', 'minutes');
        }
        if ($minutes < 24 * 60) {
            $hours = intdiv($minutes, 60);
            $rest = $minutes % 60;

            // Minutes only matter in the first few hours.
            return $hours < 3 && $rest > 0
                ? Plural::of($hours, 'hour', 'hours').' '.Plural::of($rest, 'minute', 'minutes')
                : Plural::of($hours, 'hour', 'hours');
        }

        return Plural::of(intdiv($minutes, 24 * 60), 'day', 'days');
    }

    /** "1,234" (whole numbers with thousands separators). */
    public static function number(int|float|string|null $value): string
    {
        return number_format((int) $value);
    }
}
