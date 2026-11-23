<?php

namespace App\Services;

/**
 * Turns a self-contained HTML page into a PDF with the desktop app's own
 * browser engine (Electron's printToPDF through NativePHP), so no PDF
 * library ships with the app. Outside the desktop app (php artisan serve)
 * there is no engine: render() returns null and the caller shows the page
 * to print instead.
 */
class PdfRenderer
{
    public function available(): bool
    {
        return (bool) config('nativephp-internal.running');
    }

    public function render(string $html): ?string
    {
        if (! $this->available()) {
            return null;
        }

        $result = \Native\Laravel\Facades\System::printToPDF($html, [
            'pageSize' => 'A4',
            'printBackground' => true,
            'margins' => ['marginType' => 'custom', 'top' => 0.4, 'bottom' => 0.4, 'left' => 0.4, 'right' => 0.4],
        ]);
        if (! is_string($result) || $result === '') {
            return null;
        }

        // NativePHP answers with the PDF's bytes in base64.
        $bytes = base64_decode($result, true);

        return $bytes !== false && str_starts_with($bytes, '%PDF') ? $bytes : (str_starts_with($result, '%PDF') ? $result : null);
    }
}
