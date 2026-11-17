<?php

namespace App\Support;

/**
 * "1 family" / "2 families": a count with the right word (the same as the
 * backend's App\Support\Plural), never "(s)".
 */
final class Plural
{
    public static function of(int|float|string|null $count, string $one, string $many): string
    {
        $n = (int) $count;

        return number_format($n).' '.($n === 1 ? $one : $many);
    }
}
