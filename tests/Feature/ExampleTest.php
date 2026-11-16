<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The root URL sends a signed-out user to the login page.
     */
    public function test_the_root_url_redirects(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }
}
