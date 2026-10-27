<?php

declare(strict_types=1);

final class Folio
{
    public static function make(string $prefix): string
    {
        $prefix = strtoupper((string) preg_replace('/[^A-Za-z0-9-]/', '', $prefix));
        $number = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);

        return $prefix . '-' . $number;
    }
}
