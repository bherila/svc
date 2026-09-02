<?php

namespace App\Support\Navigation;

/**
 * The chrome every page inside an entered workspace renders from.
 *
 * One shared payload rather than a prop each page supplies: the switcher and
 * the module tabs are the same on every screen, and a page free to supply them
 * is a page free to supply a different set - which is how two views of the same
 * navigation drift apart.
 *
 * The workspace's *name* is deliberately absent. The navbar names the client,
 * not the tenant: a workspace label beside the company switcher reads as a
 * second, competing context, and the only action that changes workspace is the
 * SVC wordmark. Leaving the name out of the payload is what makes "the
 * workspace name never appears in the navbar" a property the serializer
 * enforces rather than a rule the markup has to keep remembering.
 */
final class WorkspaceNavigation
{
    /**
     * @param  list<ClientNavigationOption>  $clients  every company this viewer may enter, by name
     */
    public function __construct(
        public readonly string $workspaceId,
        public readonly ?string $currentClientId,
        public readonly array $clients,
        public readonly WorkspaceNavigationPermissions $permissions,
        public readonly ?string $workspaceSettingsHref,
    ) {}

    /** The option the viewer is standing in, or null outside one. */
    public function currentClient(): ?ClientNavigationOption
    {
        foreach ($this->clients as $client) {
            if ($client->id === $this->currentClientId) {
                return $client;
            }
        }

        return null;
    }

    /**
     * @return array{
     *     workspace_id: string,
     *     current_client_id: string|null,
     *     clients: list<array{id: string, name: string, destinations: array{home: string, invoices: string|null, time: string|null, expenses: string|null, tasks: string|null}}>,
     *     permissions: array{manage_workspace: bool, create_client: bool, manage_current_client: bool, search: bool},
     *     workspace_settings_href: string|null,
     * }
     */
    public function toArray(): array
    {
        return [
            'workspace_id' => $this->workspaceId,
            'current_client_id' => $this->currentClientId,
            'clients' => array_map(
                static fn (ClientNavigationOption $client): array => $client->toArray(),
                $this->clients,
            ),
            'permissions' => $this->permissions->toArray(),
            'workspace_settings_href' => $this->workspaceSettingsHref,
        ];
    }
}
