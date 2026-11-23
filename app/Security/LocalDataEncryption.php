<?php

namespace App\Security;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Personal data at rest (docs/local-data-protection.md): which columns are
 * encrypted with the local data key, and the one-time move of a 2.0.x
 * database to encrypted columns.
 *
 * The move is safe by construction:
 *  1. a backup of the whole database is written first (encrypted with the
 *     same key, so the backup holds no readable personal data either);
 *  2. every value is encrypted inside one transaction;
 *  3. every value is read back and decrypted and must equal the original,
 *     or the transaction is rolled back and nothing changes;
 *  4. a value that is already encrypted is left alone, so running it again
 *     changes nothing.
 */
class LocalDataEncryption
{
    /** table => [column => string|json|date]. The models cast the same columns. */
    public const COLUMNS = [
        'local_auth' => ['api_token' => 'string', 'password_hash' => 'string'],
        'families' => ['name' => 'string', 'home_address' => 'string'],
        'evacuees' => ['first_name' => 'string', 'middle_name' => 'string', 'last_name' => 'string', 'suffix' => 'string',
            'contact_number' => 'string', 'date_of_birth' => 'date'],
        'ec_board_entries' => ['new_household_head_name' => 'string'],
        'center_family_snapshots' => ['families' => 'json'],
        'returning_family_snapshots' => ['families_by_event' => 'json'],
        'pending_re_enrollments' => ['family_name' => 'string', 'members' => 'json', 'possible_duplicates' => 'json'],
        'pending_check_outs' => ['family_label' => 'string'],
        'pending_private_house_families' => ['head' => 'json', 'members' => 'json', 'host_name' => 'string', 'host_address' => 'string'],
        'pending_list_check_ins' => ['family_label' => 'string', 'host_name' => 'string', 'host_address' => 'string'],
        'pending_handouts' => ['family_label' => 'string'],
        'pending_list_additions' => ['head_first_name' => 'string', 'head_last_name' => 'string', 'contact_number' => 'string', 'note' => 'string', 'label' => 'string'],
        'server_snapshots' => ['payload' => 'json'],
    ];

    public function __construct(private LocalDataKey $key) {}

    public function backupsPath(): string
    {
        return config('elikas.local_backups_path') ?: storage_path('app/backups');
    }

    /** The database file, or null for an in-memory database (tests). */
    public function databasePath(): ?string
    {
        $path = config('database.connections.'.config('database.default').'.database');

        return is_string($path) && $path !== ':memory:' && is_file($path) ? $path : null;
    }

    /**
     * A consistent copy of the whole database (VACUUM INTO, so a write-ahead
     * log is included), encrypted with the local data key. Returns its path,
     * or null when there is no database file.
     */
    public function backup(string $label): ?string
    {
        if (! $this->databasePath()) {
            return null;
        }
        if (! is_dir($this->backupsPath())) {
            mkdir($this->backupsPath(), 0700, true);
        }

        $copy = $this->backupsPath().DIRECTORY_SEPARATOR.'copy-'.uniqid().'.sqlite';
        DB::statement('VACUUM INTO ?', [$copy]);
        $bytes = file_get_contents($copy);
        @unlink($copy);
        if ($bytes === false || $bytes === '') {
            throw new \RuntimeException('The backup could not be made. Nothing was changed.');
        }

        $file = $this->backupsPath().DIRECTORY_SEPARATOR.$label.'-'.now()->format('Ymd-His').'.sqlite.enc';
        file_put_contents($file, $this->key->encrypter()->encryptString(base64_encode($bytes)));

        // The backup must open before anything is changed.
        if ($this->openBackup($file) !== $bytes) {
            @unlink($file);
            throw new \RuntimeException('The backup could not be read back. Nothing was changed.');
        }

        return $file;
    }

    /** The database bytes inside a backup made by backup(). */
    public function openBackup(string $file): string
    {
        $bytes = base64_decode($this->key->encrypter()->decryptString((string) file_get_contents($file)), true);
        if ($bytes === false) {
            throw new \RuntimeException('This backup is damaged.');
        }

        return $bytes;
    }

    /** Writes a backup's database to $to (a .sqlite file to put in place while the app is closed). */
    public function restoreBackup(string $file, string $to): void
    {
        file_put_contents($to, $this->openBackup($file));
    }

    public function isEncrypted(?string $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }
        try {
            $this->key->encrypter()->decryptString($value);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Encrypts every personal value still stored in plain text, and checks
     * each one decrypts back to the original before committing.
     *
     * @return array<string, int> values encrypted per table
     */
    public function encryptExisting(): array
    {
        $encrypter = $this->key->encrypter();
        $counts = [];
        $originals = [];

        DB::transaction(function () use ($encrypter, &$counts, &$originals) {
            foreach (self::COLUMNS as $table => $columns) {
                if (! Schema::hasTable($table)) {
                    continue;
                }
                $columns = array_filter($columns, fn ($kind, $column) => Schema::hasColumn($table, $column), ARRAY_FILTER_USE_BOTH);
                $counts[$table] = 0;

                foreach (DB::table($table)->select(array_merge(['id'], array_keys($columns)))->orderBy('id')->cursor() as $row) {
                    $updates = [];
                    foreach ($columns as $column => $kind) {
                        $value = $row->{$column};
                        if ($value === null || $value === '' || $this->isEncrypted((string) $value)) {
                            continue;
                        }
                        $plain = $kind === 'date' ? substr((string) $value, 0, 10) : (string) $value;
                        $updates[$column] = $encrypter->encryptString($plain);
                        $originals[$table][$row->id][$column] = $plain;
                    }
                    if ($updates) {
                        DB::table($table)->where('id', $row->id)->update($updates);
                        $counts[$table] += count($updates);
                    }
                }
            }

            // Every value must read back exactly as it was, or nothing is kept.
            foreach ($originals as $table => $rows) {
                foreach ($rows as $id => $values) {
                    $stored = DB::table($table)->where('id', $id)->first(array_keys($values));
                    foreach ($values as $column => $plain) {
                        if ($encrypter->decryptString($stored->{$column}) !== $plain) {
                            throw new \RuntimeException("Encrypting {$table}.{$column} #{$id} did not read back the same. Nothing was changed.");
                        }
                    }
                }
            }
        });

        return array_filter($counts);
    }

    /** Personal values still in plain text (0 once everything is encrypted). */
    public function plainCount(): int
    {
        $plain = 0;
        foreach (self::COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach (array_keys($columns) as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }
                foreach (DB::table($table)->whereNotNull($column)->where($column, '!=', '')->pluck($column) as $value) {
                    $plain += $this->isEncrypted((string) $value) ? 0 : 1;
                }
            }
        }

        return $plain;
    }
}
