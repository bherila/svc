<?php

namespace App\Support\AgentApi;

use Illuminate\Contracts\Session\Session;

final readonly class OAuthAuthorizationStateStore
{
    public function __construct(private Session $session) {}

    public function current(): ?string
    {
        $token = $this->session->get('authToken');

        return is_string($token) ? $token : null;
    }

    public function remember(string $token, string $resource): void
    {
        $this->session->put('oauth-resource:'.hash('sha256', $token), $resource);
    }

    public function get(string $token): ?string
    {
        $v = $this->session->get('oauth-resource:'.hash('sha256', $token));

        return is_string($v) ? $v : null;
    }

    public function forget(string $token): void
    {
        $this->session->forget('oauth-resource:'.hash('sha256', $token));
    }
}
