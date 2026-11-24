<?php

namespace App\Security;

use Illuminate\Encryption\Encrypter;

/**
 * The key that encrypts personal data in this computer's database
 * (docs/local-data-protection.md).
 *
 * One random 256-bit key per installation, made the first time it is
 * needed and kept in its own file next to the database, never inside it
 * (storage/app/local-data.key). In the desktop app the key is itself
 * protected with the signed-in Windows account (Electron safeStorage,
 * which uses Windows DPAPI): the database and the key file copied to
 * another computer or another Windows account cannot be read. Outside the
 * desktop app (development, tests) the key is stored unprotected.
 */
class LocalDataKey
{
    public const CIPHER = 'aes-256-gcm';

    private ?Encrypter $encrypter = null;

    public function path(): string
    {
        return config('elikas.local_data_key_path') ?: storage_path('app/local-data.key');
    }

    public function exists(): bool
    {
        return is_file($this->path());
    }

    /** "windows-account" when protected with the Windows account, "none" otherwise, null without a key yet. */
    public function protection(): ?string
    {
        return $this->exists() ? (json_decode((string) file_get_contents($this->path()), true)['protected'] ?? 'none') : null;
    }

    public function encrypter(): Encrypter
    {
        return $this->encrypter ??= new Encrypter($this->key(), self::CIPHER);
    }

    /** @throws LocalDataKeyUnavailable */
    private function key(): string
    {
        if (! $this->exists()) {
            return $this->create();
        }

        $stored = json_decode((string) file_get_contents($this->path()), true);
        if (! is_array($stored) || empty($stored['key'])) {
            throw new LocalDataKeyUnavailable('The key file of this computer\'s data is damaged.');
        }

        if (($stored['protected'] ?? 'none') === 'windows-account') {
            if (! $this->native()) {
                throw new LocalDataKeyUnavailable(app()->runningInConsole()
                    ? 'The key of this computer\'s data is protected by Windows and opens only inside the running app, not from artisan.'
                    : 'The key of this computer\'s data could not be opened with this Windows account.');
            }
            $plain = \Native\Laravel\Facades\System::decrypt($stored['key']);
            $key = is_string($plain) ? base64_decode($plain, true) : false;
            if ($key === false || strlen($key) !== 32) {
                throw new LocalDataKeyUnavailable('The key of this computer\'s data could not be opened with this Windows account.');
            }

            return $key;
        }

        $key = base64_decode($stored['key'], true);
        if ($key === false || strlen($key) !== 32) {
            throw new LocalDataKeyUnavailable('The key file of this computer\'s data is damaged.');
        }
        // Made outside the desktop app (or before it could protect keys): protect it now.
        if ($this->native() && $this->canProtect()) {
            $this->store($key);
        }

        return $key;
    }

    private function create(): string
    {
        $key = random_bytes(32);
        $this->store($key);

        return $key;
    }

    private function store(string $key): void
    {
        $protected = $this->native() && $this->canProtect();
        $value = $protected ? \Native\Laravel\Facades\System::encrypt(base64_encode($key)) : base64_encode($key);

        if (! is_dir(dirname($this->path()))) {
            mkdir(dirname($this->path()), 0700, true);
        }
        // Written beside, then moved: a half-written key file would lose the data.
        $tmp = $this->path().'.tmp';
        file_put_contents($tmp, json_encode(['v' => 1, 'cipher' => self::CIPHER, 'protected' => $protected ? 'windows-account' : 'none',
            'created_at' => now()->toIso8601String(), 'key' => $value]));
        rename($tmp, $this->path());
        @chmod($this->path(), 0600);
    }

    /**
     * Whether Electron can protect the key now. Never from an artisan
     * process: NativePHP runs `artisan migrate` at startup with spawnSync,
     * which blocks Electron's main process -- the one that answers its API --
     * so a call to System::encrypt() from a migration waits for an answer
     * that cannot come (2.1.0 hung there on every first start). A key made
     * by artisan is stored unprotected and protected at the app's first
     * request, which runs while Electron is answering.
     */
    private function native(): bool
    {
        return (bool) config('nativephp-internal.running') && ! app()->runningInConsole();
    }

    /** Whether this key can be opened here (an artisan process cannot open a key protected by Windows). */
    public function usableHere(): bool
    {
        return ! $this->exists() || $this->protection() !== 'windows-account' || $this->native();
    }

    private function canProtect(): bool
    {
        try {
            return \Native\Laravel\Facades\System::canEncrypt();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Forgets the key in memory (tests, after a restore). */
    public function reset(): void
    {
        $this->encrypter = null;
    }
}
