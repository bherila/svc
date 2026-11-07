<?php

namespace Tests\Feature\Api;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

/**
 * Business mutations use /api/v1 for browsers, REST clients and MCP (#385, #387).
 *
 * Only the named authentication, protocol/provider and interactive signing
 * exceptions below may remain as state-changing browser routes. Adding an API
 * equivalent is not a reason to retain a second browser mutation transport.
 * Portal proposal signing stays interactive; REST/MCP can still record a
 * confirmed offline acceptance through the canonical proposals.accept action.
 */
final class WebApiParityTest extends TestCase
{
    private const array BROWSER_ONLY = [
        'account.preferences.update' => 'web-only:a display preference of the signed-in person, not business data',
        // A credential may never mint another credential (#384).
        'bherila-auth.credentials.apps.destroy' => 'deliberate:credentials are managed only by the signed-in person',
        'bherila-auth.credentials.apps.store' => 'deliberate:credentials are issued only by the signed-in person',
        'bherila-auth.credentials.connections.destroy' => 'deliberate:credentials are managed only by the signed-in person',
        'bherila-auth.credentials.tokens.destroy' => 'deliberate:credentials are managed only by the signed-in person',
        'bherila-auth.credentials.tokens.store' => 'deliberate:credentials are issued only by the signed-in person',
        // The same kind of exception: a setting of the person's own MCP credential, a view
        // over the tools it already may call, which grants nothing and changes at its next connect.
        'mcp.credentials.connections.update' => 'deliberate:an MCP connection\'s tool-list setting is changed only by the signed-in person who owns it',
        'mcp.credentials.tokens.update' => 'deliberate:an MCP token\'s tool-list setting is changed only by the signed-in person who owns it',
        'clients.manage' => 'web-only:redirect',
        'workspaces.operations' => 'web-only:redirect',
        'workspaces.edition.full' => 'deliberate:turning agreements, invoicing and revenue on for a whole business is a signed-in owner or admin\'s choice, one way, and nothing an agent decides',
        'workspaces.reviewers.destroy' => 'deliberate:review access is taken back only by a signed-in owner or admin of the workspace that gave it',
        'workspaces.reviewers.store' => 'deliberate:letting someone outside the business vouch for its hours is given only by a signed-in owner or admin',
        'workspaces.payments.destroy' => 'deliberate:payment keys are managed only by a signed-in owner or admin',
        'workspaces.payments.reuse' => 'deliberate:payment keys are managed only by a signed-in owner or admin',
        'workspaces.payments.store' => 'deliberate:payment keys are entered only by a signed-in owner or admin',
        'logout' => 'web-only:sign-out',
        'svc.billing.brevo.webhook' => 'web-only:inbound webhook',
        'svc.billing.invoices.stripe-payment-intent' => 'web-only:payment processor hand-off',
        'svc.billing.stripe.webhook' => 'web-only:inbound webhook',
        'svc.billing.stripe.workspace-webhook' => 'web-only:inbound webhook',
        'svc.billing.square.webhook' => 'web-only:inbound webhook',
        'svc.billing.square.workspace-webhook' => 'web-only:inbound webhook',
        'approvals.approve' => 'deliberate:a person approving the hours and expenses they pay for, or were given review access to, is an interactive decision in the browser; the reviewer holds no agent credential and nothing here is an agent operation',
        'approvals.dispute' => 'deliberate:a person disputing a row, with a note, is an interactive decision in the browser; the reviewer holds no agent credential and nothing here is an agent operation',
        'portal.approvals.approve' => 'deliberate:a client contact approving the hours and expenses they pay for is an interactive decision by the payer in the portal, which holds no agent credential',
        'portal.autopay.enable' => 'deliberate:a client contact authorizing charges to their own saved payment method is their own explicit consent in the portal, with the exact wording stored; the provider\'s attested route is companies.autopay.set',
        'portal.autopay.disable' => 'deliberate:a client contact withdrawing their authorization to charge their saved payment method is their own act in the portal; the provider side is companies.autopay.set',
        'portal.approvals.dispute' => 'deliberate:a client contact disputing a row, with a note, is an interactive decision by the payer in the portal, which holds no agent credential',
        // Sharing a project crosses a workspace boundary, so only a signed-in
        // owner or admin of each side may open, accept, end or relink it (#631).
        'shared-project-invitations.accept' => 'deliberate:joining another workspace\'s project is accepted only by a signed-in owner or admin of the joining workspace',
        'shared-project-invitations.decline' => 'deliberate:an invitation to another workspace\'s project is declined only by a signed-in invitee',
        'shared-projects.invitations.destroy' => 'deliberate:cross-workspace invitations are withdrawn only by a signed-in host owner or admin',
        'shared-projects.invitations.store' => 'deliberate:cross-workspace invitations are issued only by a signed-in host owner or admin',
        'shared-projects.leave' => 'deliberate:leaving another workspace\'s project is done only by a signed-in owner or admin of the leaving workspace',
        'shared-projects.local-project.update' => 'deliberate:linking a local project to a shared one is done only by a signed-in owner or admin of the participant workspace',
        'shared-projects.participants.destroy' => 'deliberate:participants are removed only by a signed-in host owner or admin',
        'svc.engagement.agreements.sign' => 'deliberate:a signature is an interactive act by the signer',
        'svc.engagement.proposals.accept' => 'deliberate:portal acceptance is an interactive signature by the recipient; REST/MCP separately support confirmed offline acceptance',
    ];

    public function test_every_state_changing_web_route_is_a_named_browser_exception(): void
    {
        $routes = $this->webMutationRoutes();
        $unnamed = array_keys(array_filter($routes, static fn (?string $name): bool => $name === null));
        $this->assertSame([], $unnamed, 'Name these web routes so they can be classified: '.implode(', ', $unnamed));

        $names = array_values(array_filter($routes));
        sort($names);
        $classified = array_keys(self::BROWSER_ONLY);
        sort($classified);

        $this->assertSame(
            [],
            array_values(array_diff($names, $classified)),
            'Business mutations must use /api/v1. Only explicitly documented browser exceptions belong in WebApiParityTest.',
        );
        $this->assertSame(
            [],
            array_values(array_diff($classified, $names)),
            'These classified routes no longer exist; remove them from WebApiParityTest.',
        );
    }

    public function test_every_classification_is_well_formed_and_current(): void
    {
        foreach (self::BROWSER_ONLY as $route => $classification) {
            [$kind, $detail] = array_pad(explode(':', $classification, 2), 2, null);
            match ($kind) {
                'web-only', 'deliberate' => $this->assertNotEmpty($detail, "{$route}: give a reason"),
                default => $this->fail("{$route}: unknown classification {$classification}"),
            };
        }
    }

    /**
     * A connector that was handed an API token instead of running OAuth must be
     * able to call every REST operation (#384), so each declares both schemes.
     * The one exception is an operation that needs `mcp:use`: an API token can
     * never carry it, so offering the alternative would only ever earn a 403.
     */
    public function test_every_operation_accepts_oauth_or_an_api_token(): void
    {
        $document = json_decode((string) file_get_contents(public_path('openapi/svc-agent-v1.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('bearer', $document['components']['securitySchemes']['apiToken']['scheme'] ?? null);

        foreach ($document['paths'] as $path) {
            foreach ($path as $operation) {
                $schemes = array_map(static fn (array $requirement): array => array_keys($requirement), $operation['security'] ?? []);
                $expected = in_array('mcp:use', $operation['security'][0]['oauth2'] ?? [], true)
                    ? [['oauth2']]
                    : [['oauth2'], ['apiToken']];
                $this->assertSame($expected, $schemes, $operation['operationId']);
            }
        }
    }

    /** @return array<string, string|null> uri+method => route name */
    private function webMutationRoutes(): array
    {
        $routes = [];
        foreach (RouteFacade::getRoutes()->getRoutes() as $route) {
            /** @var Route $route */
            // The OAuth authorization server's own endpoints come from the
            // shared auth package and are protocol plumbing, not user actions.
            // Machine webhooks intentionally omit the session-bearing web group.
            if ((! in_array('web', $route->gatherMiddleware(), true) && ! str_starts_with($route->uri(), 'api/webhooks/')) || str_starts_with($route->uri(), 'oauth/')) {
                continue;
            }
            if (array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) === []) {
                continue;
            }
            $routes[implode('|', $route->methods()).' '.$route->uri()] = $route->getName();
        }

        return $routes;
    }
}
