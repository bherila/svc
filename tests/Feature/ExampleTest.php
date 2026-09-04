<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_a_successful_response(): void
    {
        $response = $this->get(route('home'));

        $response->assertOk()
            ->assertSee('<link rel="icon" href="/favicon.ico" sizes="any">', false)
            ->assertSee('<link rel="apple-touch-icon" href="/apple-touch-icon.png">', false)
            ->assertSee('<link rel="icon" href="/favicon.svg" type="image/svg+xml">', false);

        $favicon = file_get_contents(public_path('favicon.svg'));

        $this->assertIsString($favicon);
        $this->assertStringContainsString('viewBox="0 0 64 64"', $favicon);
        $this->assertStringContainsString('fill="#18181B"', $favicon);
        $this->assertStringContainsString('fill="#FAFAFA"', $favicon);
        $this->assertStringNotContainsString('#FF2D20', $favicon);

        // The two fallbacks are generated from the same gear artwork. Pinning
        // their dimensions and bytes prevents an inherited framework icon from
        // silently returning on platforms that do not select the SVG.
        $this->assertSame(
            '84b4a1788e31726a7db8c5da7c02ecabc35188f0bb71fb21c7691bf9f68d15c6',
            hash_file('sha256', public_path('favicon.ico')),
        );
        $this->assertSame(
            '31c01578d8a97a4fa7ab2fee80f258952ca12401e11bb6b7bea6a1c25d55f983',
            hash_file('sha256', public_path('apple-touch-icon.png')),
        );

        $touchIcon = getimagesize(public_path('apple-touch-icon.png'));
        $this->assertIsArray($touchIcon);
        $this->assertSame(180, $touchIcon[0]);
        $this->assertSame(180, $touchIcon[1]);
    }
}
