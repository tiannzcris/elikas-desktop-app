<?php

namespace App\Security;

use Illuminate\Contracts\Encryption\Encrypter as EncrypterContract;
use Illuminate\Contracts\Encryption\StringEncrypter;

/**
 * What the models' "encrypted" casts use (Model::encryptUsing): the local
 * data key, opened only when a personal value is actually read or written.
 */
class LazyLocalEncrypter implements EncrypterContract, StringEncrypter
{
    private function inner(): \Illuminate\Encryption\Encrypter
    {
        return app(LocalDataKey::class)->encrypter();
    }

    public function encrypt(#[\SensitiveParameter] $value, $serialize = true)
    {
        return $this->inner()->encrypt($value, $serialize);
    }

    public function decrypt($payload, $unserialize = true)
    {
        return $this->inner()->decrypt($payload, $unserialize);
    }

    public function encryptString(#[\SensitiveParameter] $value)
    {
        return $this->inner()->encryptString($value);
    }

    public function decryptString($payload)
    {
        return $this->inner()->decryptString($payload);
    }

    public function getKey()
    {
        return $this->inner()->getKey();
    }

    public function getAllKeys()
    {
        return $this->inner()->getAllKeys();
    }

    public function getPreviousKeys()
    {
        return $this->inner()->getPreviousKeys();
    }
}
