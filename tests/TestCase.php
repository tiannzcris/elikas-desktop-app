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
    }

    /** Works on this event, as if chosen in the top-bar event switcher. */
    protected function selectEvent(?EvacuationEvent $event): void
    {
        app(SelectedEvent::class)->choose($event);
    }
}
