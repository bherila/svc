<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_a_successful_response()
    {
        $response = $this->get(route('home'));

        $response->assertOk()
            ->assertSee('<link rel="icon" href="/favicon.svg" type="image/svg+xml">', false);

        $favicon = file_get_contents(public_path('favicon.svg'));

        $this->assertIsString($favicon);
        $this->assertStringContainsString('viewBox="0 0 64 64"', $favicon);
        $this->assertStringContainsString('fill="#18181B"', $favicon);
        $this->assertStringContainsString('fill="#FAFAFA"', $favicon);
        $this->assertStringNotContainsString('#FF2D20', $favicon);
    }
}
