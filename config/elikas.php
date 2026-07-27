<?php

// Central server connection settings. Kept in one config file (reading from
// .env) rather than hardcoded, so pointing this device at a different
// server (e.g. a staging environment vs the real CSWDO server) is a one-line
// .env change, not a code change.
return [
    'central_api_url' => env('CENTRAL_API_URL', 'http://127.0.0.1:8000/api/v1'),
];
