<?php

namespace App\Support\Navigation;

/**
 * The workspace tier of the navbar, as finished URLs for one viewer.
 *
 * Chosen on the server per role. A null is a module this viewer may not reach
 * and its tab is not rendered: the browser never decides who sees Finance. A
 * hidden link is not an authorization check - every endpoint behind one
 * authorizes again.
 */
final class WorkspaceDestinations
{
    public function __construct(
        public readonly ?string $dashboard,
        public readonly ?string $clients,
        public readonly ?string $invoices,
        public readonly ?string $time,
        public readonly ?string $finance,
        public readonly ?string $myWork = null,
    ) {}

    /**
     * @return array{dashboard: string|null, clients: string|null, invoices: string|null, time: string|null, finance: string|null, my_work: string|null}
     */
    public function toArray(): array
    {
        return [
            'dashboard' => $this->dashboard,
            'clients' => $this->clients,
            'invoices' => $this->invoices,
            'time' => $this->time,
            'finance' => $this->finance,
            'my_work' => $this->myWork,
        ];
    }
}
