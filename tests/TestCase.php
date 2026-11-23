<?php

namespace Tests;

use App\Models\EvacuationEvent;
use App\Services\SelectedEvent;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Never let a test reach a real central server (a local backend
        // may well be running on this machine).
        Http::preventStrayRequests();

        // A key of its own per test run, never a real one.
        config(['elikas.local_data_key_path' => sys_get_temp_dir().DIRECTORY_SEPARATOR.'elikas-test-'.getmypid().'.key',
            'elikas.local_backups_path' => sys_get_temp_dir().DIRECTORY_SEPARATOR.'elikas-test-backups-'.getmypid()]);
        app(\App\Security\LocalDataKey::class)->reset();
    }

    /** Works on this event, as if chosen in the top-bar event switcher. */
    protected function selectEvent(?EvacuationEvent $event): void
    {
        app(SelectedEvent::class)->choose($event);
    }
}
