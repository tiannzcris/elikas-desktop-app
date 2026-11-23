<?php

namespace App\Security;

/** The key of this computer's data cannot be read (see docs/local-data-protection.md, "Recovery"). */
class LocalDataKeyUnavailable extends \RuntimeException {}
