<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The client address behind Cloudflare, and only behind Cloudflare.
 *
 * Every per-client limit - registration, token exchange, the API throttle -
 * keys on `Request::ip()`. Untrusted, every caller looked like a Cloudflare
 * edge and shared one budget. Trusted too broadly, the origin (which answers
 * direct connections) would let anyone forge their address and walk past those
 * limits. These pin the line between the two.
 */
final class TrustedProxiesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Route::get('/whoami', fn (Request $request) => response()->json(['ip' => $request->ip(), 'secure' => $request->isSecure(), 'host' => $request->getHost()]));
    }

    public function test_a_cloudflare_edge_forwards_the_client_address(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '104.16.1.2', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7', 'HTTP_X_FORWARDED_PROTO' => 'https'])
            ->getJson('/whoami')->assertOk()->assertJsonPath('ip', '198.51.100.7')->assertJsonPath('secure', true);
        $this->withServerVariables(['REMOTE_ADDR' => '2606:4700::1234', 'HTTP_X_FORWARDED_FOR' => '2001:db8::9'])
            ->getJson('/whoami')->assertOk()->assertJsonPath('ip', '2001:db8::9');
    }

    /**
     * A client can send its own X-Forwarded-For through Cloudflare; Cloudflare
     * appends the address it actually saw. That appended, rightmost value is
     * the client - never the first, which the client wrote.
     */
    public function test_a_client_supplied_chain_through_cloudflare_yields_the_address_cloudflare_appended(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '172.64.9.9', 'HTTP_X_FORWARDED_FOR' => '192.0.2.1, 192.0.2.2, 198.51.100.7'])
            ->getJson('/whoami')->assertOk()->assertJsonPath('ip', '198.51.100.7');
    }

    /** The origin is reachable without Cloudflare; a forwarded header from anywhere else is ignored. */
    public function test_a_direct_connection_cannot_spoof_its_address(): void
    {
        $this->withServerVariables([
            'REMOTE_ADDR' => '203.0.113.5',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.7',
            'HTTP_CF_CONNECTING_IP' => '198.51.100.7',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ])->getJson('/whoami')->assertOk()->assertJsonPath('ip', '203.0.113.5')->assertJsonPath('secure', false);
    }

    /** Even from a Cloudflare edge, the forwarded host is never honoured. */
    public function test_the_forwarded_host_is_never_trusted(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '104.16.1.2', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7', 'HTTP_X_FORWARDED_HOST' => 'evil.example.test'])
            ->getJson('/whoami')->assertOk()->assertJsonPath('host', 'localhost');
    }

    public function test_rate_limits_key_on_the_client_behind_cloudflare_and_on_the_peer_otherwise(): void
    {
        Route::post('/limited', fn () => response()->json(['ok' => true]))->middleware('throttle:2,1');

        // Two clients behind the same edge each get their own budget.
        foreach (['198.51.100.7', '198.51.100.7', '198.51.100.8'] as $client) {
            $this->withServerVariables(['REMOTE_ADDR' => '172.64.9.9', 'HTTP_X_FORWARDED_FOR' => $client])->postJson('/limited')->assertOk();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '172.64.9.9', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7'])->postJson('/limited')->assertTooManyRequests();
        $this->withServerVariables(['REMOTE_ADDR' => '172.64.9.9', 'HTTP_X_FORWARDED_FOR' => '198.51.100.8'])->postJson('/limited')->assertOk();

        // A direct caller cannot escape its budget by rotating a forged header.
        foreach (['192.0.2.10', '192.0.2.11'] as $forged) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.5', 'HTTP_X_FORWARDED_FOR' => $forged])->postJson('/limited')->assertOk();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.5', 'HTTP_X_FORWARDED_FOR' => '192.0.2.12'])->postJson('/limited')->assertTooManyRequests();
    }
}
