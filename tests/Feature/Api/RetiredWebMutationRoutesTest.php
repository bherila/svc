<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Route;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

final class RetiredWebMutationRoutesTest extends TestCase
{
    public function test_retired_time_mutation_routes_are_absent(): void
    {
        foreach (['store', 'update', 'destroy', 'approve', 'unapprove'] as $action) {
            $this->assertFalse(Route::has('svc.engagement.time-entries.'.$action));
        }
        $this->assertFalse(Route::has('svc.billing.invoices.time'));
    }

    public function test_invoice_bookkeeping_mutations_use_only_canonical_api_routes(): void
    {
        foreach (['store', 'issue', 'payments.store', 'payments.received-on', 'send', 'correct',
            'automatic-delivery.hold', 'automatic-delivery.release', 'void'] as $action) {
            $this->assertFalse(Route::has('svc.billing.invoices.'.$action), $action);
        }
        foreach (['invoices.store', 'invoices.issue', 'payments.store', 'payments.update', 'invoices.send',
            'invoices.correct', 'invoices.hold_delivery', 'invoices.release_delivery', 'invoices.void'] as $action) {
            $this->assertTrue(Route::has('agent-api.v1.'.$action), $action);
        }
        foreach (['index', 'show', 'pdf', 'corrections.show', 'stripe-payment-intent'] as $action) {
            $this->assertTrue(Route::has('svc.billing.invoices.'.$action), $action);
        }
        foreach (Route::getRoutes() as $route) {
            if ($route->uri() === 'workspaces/{workspace}/clients/{clientCompany}/invoices') {
                $this->assertNotContains('POST', $route->methods());
            }
            if (str_starts_with($route->uri(), 'workspaces/{workspace}/invoices/{clientInvoice}')) {
                $this->assertSame($route->uri() === 'workspaces/{workspace}/invoices/{clientInvoice}/stripe-payment-intent',
                    in_array('POST', $route->methods(), true), $route->uri());
                $this->assertSame([], array_intersect(['PUT', 'PATCH', 'DELETE'], $route->methods()), $route->uri());
            }
        }
    }

    /**
     * A browser route answers the page that called it and has no JSON shape
     * of its own (#454): the bare-model JSON branches were a third shape
     * beside the Inertia props and the API presenters. A program uses /api/v1.
     */
    public function test_browser_controllers_have_no_json_branch(): void
    {
        $violations = [];
        foreach ((new Finder)->files()->in(app_path('Http/Controllers'))->exclude('Api')->name('*.php') as $file) {
            if (preg_match('/->\s*(?:expectsJson|wantsJson)\s*\(/', $file->getContents()) === 1) {
                $violations[] = $file->getRelativePathname();
            }
        }
        $this->assertSame([], $violations, 'Browser controllers branching on JSON: '.implode(', ', $violations));
    }

    public function test_workspace_creation_uses_the_api_route(): void
    {
        $this->assertFalse(Route::has('workspaces.store'));
        $this->assertTrue(Route::has('agent-api.v1.workspaces.store'));
    }

    public function test_retired_portal_access_mutation_routes_are_absent(): void
    {
        foreach (['clients.portal-access.store', 'clients.portal-access.update', 'clients.portal-access.destroy',
            'clients.portal-invitations.store', 'clients.portal-invitations.resend', 'clients.portal-invitations.destroy'] as $name) {
            $this->assertFalse(Route::has($name), $name);
        }
        // The settings page sends them to the API instead (#385).
        foreach (['store', 'update', 'destroy'] as $action) {
            $this->assertTrue(Route::has('agent-api.v1.clients.portal-access.'.$action));
        }
        foreach (['store', 'resend', 'destroy'] as $action) {
            $this->assertTrue(Route::has('agent-api.v1.clients.portal-invitations.'.$action));
        }
    }

    public function test_frontend_does_not_reference_retired_mutation_routes(): void
    {
        $violations = [];
        foreach ((new Finder)->files()->in([resource_path('js/pages'), resource_path('js/components')])->name(['*.ts', '*.tsx'])->notName(['*.test.ts', '*.test.tsx']) as $file) {
            $source = $file->getContents();
            // API URLs include /api/v1 immediately before /workspaces. Match
            // the retired web paths even when their identifiers are templates.
            preg_match_all('~(?<!/api/v1)/workspaces/[^\s"\'`]+/(?:time-entries|invoices/[^/\s"\'`]+/(?:time|issue|send|correct|void|payments|automatic-delivery)|portal-access|portal-invitations)(?=[/\s"\'`]|$)~', $source, $matches);
            foreach ($matches[0] as $match) {
                $violations[] = $file->getRelativePathname().': '.$match;
            }
            if (preg_match('/svc\.(?:engagement\.time-entries\.(?:store|update|destroy|approve|unapprove)|billing\.invoices\.(?:time|store|issue|send|correct|void|payments\.(?:store|received-on)|automatic-delivery\.(?:hold|release)))\b|(?<![\w.-])clients\.portal-(?:access|invitations)\.(?:store|update|destroy|resend)\b/', $source)) {
                $violations[] = $file->getRelativePathname().': retired named route';
            }
        }
        $this->assertSame([], $violations, 'Frontend references to retired web mutations: '.implode(', ', $violations));
    }
}
