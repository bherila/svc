<?php

namespace Tests\Feature\Api;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

/**
 * Every action the website can take is an API operation, or says why not.
 *
 * The web UI, REST clients and MCP are meant to share one API (#387). This
 * ratchet makes the gap visible and stops it growing: every state-changing web
 * route must be classified below, and a new one fails until it is. The
 * `missing` and `mcp-only` entries are the backlog (#386, #383); each should
 * become an `operation:` entry, after which the web route can be retired in
 * favour of the API (#385).
 *
 * Classifications:
 * - `operation:<operationId>`: the same action exists in the OpenAPI contract.
 * - `mcp-only:<tool>`: an MCP tool exists, but no REST route yet (#383).
 * - `missing`: no API equivalent yet (#386).
 * - `web-only:<reason>`: stays a browser route by design: sign-in, inbound
 *   webhooks, redirects, or the hand-off to the payment processor.
 * - `deliberate:<reason>`: a product decision recorded on #386.
 */
final class WebApiParityTest extends TestCase
{
    private const array CLASSIFIED = [
        // Credentials are minted only by the signed-in person in the browser, so
        // no OAuth credential can create another (#384).
        'account.api-tokens.destroy' => 'deliberate:credentials are managed only by the signed-in person',
        'account.api-tokens.store' => 'deliberate:credentials are issued only by the signed-in person',
        'account.oauth-apps.destroy' => 'deliberate:credentials are managed only by the signed-in person',
        'account.oauth-apps.store' => 'deliberate:credentials are issued only by the signed-in person',
        'clients.manage' => 'web-only:redirect',
        'clients.store' => 'mcp-only:clients.create',
        'clients.update' => 'mcp-only:clients.update',
        'logout' => 'web-only:sign-out',
        'projects.access.update' => 'missing',
        'projects.store' => 'missing',
        'projects.update' => 'missing',
        'svc.billing.brevo.webhook' => 'web-only:inbound webhook',
        'svc.billing.invoices.automatic-delivery.hold' => 'missing',
        'svc.billing.invoices.automatic-delivery.release' => 'missing',
        'svc.billing.invoices.correct' => 'operation:invoices.correct',
        'svc.billing.invoices.issue' => 'operation:invoices.issue',
        'svc.billing.invoices.payments.received-on' => 'operation:payments.correct',
        'svc.billing.invoices.payments.store' => 'operation:payments.record',
        'svc.billing.invoices.send' => 'operation:invoices.send',
        'svc.billing.invoices.store' => 'operation:invoices.create_draft',
        'svc.billing.invoices.stripe-payment-intent' => 'web-only:payment processor hand-off',
        'svc.billing.invoices.time' => 'missing',
        'svc.billing.invoices.void' => 'operation:invoices.void',
        'svc.billing.schedules.generate' => 'missing',
        'svc.billing.schedules.store' => 'missing',
        'svc.billing.stripe.webhook' => 'web-only:inbound webhook',
        'svc.engagement.agreements.activate' => 'mcp-only:agreements.activate',
        'svc.engagement.agreements.sign' => 'deliberate:a signature is an interactive act by the signer',
        'svc.engagement.agreements.store' => 'mcp-only:agreements.create',
        'svc.engagement.agreements.update' => 'mcp-only:agreements.update',
        'svc.engagement.proposals.accept' => 'missing',
        'svc.engagement.proposals.send' => 'missing',
        'svc.engagement.proposals.store' => 'missing',
        'svc.engagement.time-entries.approve' => 'operation:time_entries.approve',
        'svc.engagement.time-entries.destroy' => 'operation:time_entries.delete',
        'svc.engagement.time-entries.store' => 'operation:time_entries.log',
        'svc.engagement.time-entries.unapprove' => 'missing',
        'svc.engagement.time-entries.update' => 'operation:time_entries.update',
        'svc.expense-schedules.generate' => 'missing',
        'svc.expense-schedules.store' => 'missing',
        'svc.expense-schedules.update' => 'missing',
        'svc.expenses.approve' => 'missing',
        'svc.expenses.destroy' => 'operation:expenses.delete',
        'svc.expenses.store' => 'operation:expenses.log',
        'svc.expenses.unapprove' => 'missing',
        'svc.expenses.update' => 'operation:expenses.update',
        'svc.files.destroy' => 'missing',
        'svc.files.store' => 'missing',
        'tasks.store' => 'operation:tasks.create',
        'tasks.update' => 'operation:tasks.update',
        'workspaces.store' => 'missing',
    ];

    public function test_every_state_changing_web_route_is_classified(): void
    {
        $routes = $this->webMutationRoutes();
        $unnamed = array_keys(array_filter($routes, static fn (?string $name): bool => $name === null));
        $this->assertSame([], $unnamed, 'Name these web routes so they can be classified: '.implode(', ', $unnamed));

        $names = array_values(array_filter($routes));
        sort($names);
        $classified = array_keys(self::CLASSIFIED);
        sort($classified);

        $this->assertSame(
            [],
            array_values(array_diff($names, $classified)),
            'Classify each new state-changing web route in WebApiParityTest: add the API operation first, or record why not.',
        );
        $this->assertSame(
            [],
            array_values(array_diff($classified, $names)),
            'These classified routes no longer exist; remove them from WebApiParityTest.',
        );
    }

    public function test_every_classification_is_well_formed_and_current(): void
    {
        $operations = $this->openApiOperationIds();

        foreach (self::CLASSIFIED as $route => $classification) {
            [$kind, $detail] = array_pad(explode(':', $classification, 2), 2, null);
            match ($kind) {
                'operation' => $this->assertContains($detail, $operations, "{$route}: {$detail} is not an OpenAPI operation"),
                // Once the REST route lands, the entry must say so.
                'mcp-only' => $this->assertNotContains($detail, $operations, "{$route}: {$detail} is now an OpenAPI operation; classify it as operation:{$detail}"),
                'missing' => $this->assertNull($detail, $route),
                'web-only', 'deliberate' => $this->assertNotEmpty($detail, "{$route}: give a reason"),
                default => $this->fail("{$route}: unknown classification {$classification}"),
            };
        }
    }

    /**
     * A connector that was handed an API token instead of running OAuth must be
     * able to call every operation (#384), so each declares both schemes.
     */
    public function test_every_operation_accepts_oauth_or_an_api_token(): void
    {
        $document = json_decode((string) file_get_contents(public_path('openapi/svc-agent-v1.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('bearer', $document['components']['securitySchemes']['apiToken']['scheme'] ?? null);

        foreach ($document['paths'] as $path) {
            foreach ($path as $operation) {
                $schemes = array_map(static fn (array $requirement): array => array_keys($requirement), $operation['security'] ?? []);
                $this->assertSame([['oauth2'], ['apiToken']], $schemes, $operation['operationId']);
                $this->assertSame([], $operation['security'][1]['apiToken'], $operation['operationId']);
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
            if (! in_array('web', $route->gatherMiddleware(), true) || str_starts_with($route->uri(), 'oauth/')) {
                continue;
            }
            if (array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) === []) {
                continue;
            }
            $routes[implode('|', $route->methods()).' '.$route->uri()] = $route->getName();
        }

        return $routes;
    }

    /** @return list<string> */
    private function openApiOperationIds(): array
    {
        $document = json_decode((string) file_get_contents(public_path('openapi/svc-agent-v1.json')), true, flags: JSON_THROW_ON_ERROR);
        $ids = [];
        foreach ($document['paths'] as $path) {
            foreach ($path as $operation) {
                $ids[] = $operation['operationId'];
            }
        }

        return $ids;
    }
}
