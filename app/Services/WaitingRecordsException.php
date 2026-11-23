<?php

namespace App\Services;

/** An export or import of waiting records that cannot go ahead; the message says why, for the user. */
class WaitingRecordsException extends \RuntimeException {}
