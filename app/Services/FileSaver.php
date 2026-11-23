<?php

namespace App\Services;

use Native\Laravel\Dialog;

/**
 * Where a generated file goes: in the desktop app, the user chooses the
 * folder and name in Windows' own Save dialog (a USB drive works too);
 * outside it (php artisan serve) the browser downloads the file.
 */
class FileSaver
{
    public function native(): bool
    {
        return (bool) config('nativephp-internal.running');
    }

    /** The path the user chose, or null when they cancelled. */
    public function askPath(string $title, string $fileName, string $format): ?string
    {
        $path = Dialog::new()
            ->title($title)
            ->defaultPath($fileName)
            ->filter($format === 'pdf' ? 'PDF' : ($format === 'xlsx' ? 'Excel workbook' : 'E-LIKAS file'), [$format])
            ->button('Save')
            ->save();

        return is_string($path) && $path !== '' ? $path : null;
    }

    /** Writes the bytes; false when the place can't be written to. */
    public function write(string $path, string $bytes): bool
    {
        return @file_put_contents($path, $bytes) !== false;
    }
}
