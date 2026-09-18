<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Safely switches which central server THIS device's app talks to,
 * without ever touching .env's CENTRAL_API_URL -- see config/elikas.php's
 * own docblock for the exact incident this exists to prevent (a local
 * test entry syncing straight into the live production database because
 * there was previously no dev-safe way to point this app at a local
 * server, and no visible indicator when accidentally pointed at
 * production during testing).
 */
class ApiTargetCommand extends Command
{
    protected $signature = 'elikas:api-target {target? : "local", "production", or a full URL. Omit to just show the current target.}';

    protected $description = 'Show or switch which central server this device talks to (local dev vs production) -- never edits .env';

    private function overrideFile(): string
    {
        return storage_path('app/dev-api-target.txt');
    }

    public function handle(): int
    {
        $target = $this->argument('target');

        if (! $target) {
            $this->showCurrent();

            return self::SUCCESS;
        }

        if ($target === 'production') {
            if (file_exists($this->overrideFile())) {
                unlink($this->overrideFile());
            }
            $this->info('Override cleared -- back to production ('.config('elikas.production_api_url').').');

            return self::SUCCESS;
        }

        $url = $target === 'local' ? 'http://127.0.0.1:8000/api/v1' : $target;

        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            $this->error("\"{$target}\" isn't \"local\", \"production\", or a valid URL.");

            return self::FAILURE;
        }

        file_put_contents($this->overrideFile(), $url);
        $this->warn("Now targeting {$url} -- this device's app will show a warning banner on every page until you run `php artisan elikas:api-target production`.");

        return self::SUCCESS;
    }

    private function showCurrent(): void
    {
        $current = config('elikas.central_api_url');
        $isProduction = $current === config('elikas.production_api_url');

        $this->line("Current target: {$current}");
        $this->line($isProduction ? '(production -- no override active)' : '(NOT production -- override active via '.$this->overrideFile().')');
    }
}
