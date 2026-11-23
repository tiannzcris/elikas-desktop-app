<?php

namespace App\Console\Commands;

use App\Security\LocalDataEncryption;
use App\Security\LocalDataKey;
use Illuminate\Console\Command;

/**
 * php artisan elikas:local-data status
 * php artisan elikas:local-data encrypt
 * php artisan elikas:local-data restore <backup.sqlite.enc> [--to=<file.sqlite>]
 *
 * See docs/local-data-protection.md ("Recovery").
 */
class LocalDataCommand extends Command
{
    protected $signature = 'elikas:local-data {action : status, encrypt or restore} {file? : the backup to restore} {--to= : where to write the restored database}';

    protected $description = 'Status of the encrypted local data, encrypt what is left in plain text, or open a backup';

    public function handle(LocalDataKey $key, LocalDataEncryption $encryption): int
    {
        return match ($this->argument('action')) {
            'status' => $this->status($key, $encryption),
            'encrypt' => $this->encrypt($encryption),
            'restore' => $this->restore($encryption),
            default => $this->fail('Unknown action. Use status, encrypt or restore.'),
        };
    }

    private function status(LocalDataKey $key, LocalDataEncryption $encryption): int
    {
        $this->line('Key file: '.$key->path().($key->exists() ? '' : ' (not made yet)'));
        $this->line('Key protection: '.($key->protection() ?? '-'));
        $this->line('Database: '.($encryption->databasePath() ?? '(in memory)'));
        $this->line('Personal values still in plain text: '.$encryption->plainCount());
        $this->line('Backups: '.$encryption->backupsPath());

        return self::SUCCESS;
    }

    private function encrypt(LocalDataEncryption $encryption): int
    {
        $backup = $encryption->backup('before-encrypt');
        $counts = $encryption->encryptExisting();
        $this->info('Backup: '.($backup ?? 'none (no database file)'));
        $this->info('Values encrypted: '.array_sum($counts));

        return self::SUCCESS;
    }

    private function restore(LocalDataEncryption $encryption): int
    {
        $file = (string) $this->argument('file');
        if ($file === '' || ! is_file($file)) {
            return $this->fail('Give the backup file (.sqlite.enc) to restore.');
        }
        $to = $this->option('to') ?: preg_replace('/\.enc$/', '', $file);
        $encryption->restoreBackup($file, $to);
        $this->info("Restored database written to {$to}.");
        $this->line('Close the app, keep a copy of the current database, then put this file in its place (same name).');

        return self::SUCCESS;
    }
}
