<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example — index يوجّه إلى واجهة React على 5173.
     */
    public function test_the_application_redirects_to_the_react_app(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('http://127.0.0.1:5173');
    }
}
