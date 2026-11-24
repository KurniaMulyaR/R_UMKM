<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_application_redirects_to_setup_without_an_owner(): void
    {
        $this->get('/')->assertRedirect(route('setup'));
    }

    public function test_the_setup_page_is_available_before_initial_setup(): void
    {
        $this->get('/setup')->assertOk()->assertSee('Kenalkan usaha kamu');
    }
}

