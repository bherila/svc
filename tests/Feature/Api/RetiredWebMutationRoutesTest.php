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
        $this->assertTrue(Route::has('svc.billing.invoices.store'));
    }

    public function test_retired_engagement_mutations_are_absent_and_canonical_routes_remain(): void
    {
        foreach (['svc.engagement.proposals.store', 'svc.engagement.proposals.send',
            'svc.engagement.agreements.store', 'svc.engagement.agreements.update', 'svc.engagement.agreements.activate',
            'svc.billing.schedules.store', 'svc.billing.schedules.generate'] as $name) {
            $this->assertFalse(Route::has($name), $name);
        }
        foreach (['proposals.store', 'proposals.send', 'agreements.store', 'agreements.update',
            'agreements.activate', 'billing-schedules.store', 'billing-schedules.generate'] as $name) {
            $this->assertTrue(Route::has('agent-api.v1.'.$name), $name);
        }
        $this->assertTrue(Route::has('svc.engagement.agreements.sign'));
        $this->assertTrue(Route::has('svc.engagement.proposals.accept'));
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

    public function test_retired_project_mutation_routes_are_absent(): void
    {
        foreach (['projects.store', 'projects.update', 'projects.access.update'] as $name) {
            $this->assertFalse(Route::has($name), $name);
        }
        foreach (['projects.store', 'projects.update', 'projects.members.update'] as $name) {
            $this->assertTrue(Route::has('agent-api.v1.'.$name), $name);
        }
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

    public function test_interactive_proposal_acceptance_is_the_only_remaining_inertia_mutation(): void
    {
        $writers = [];
        foreach ((new Finder)->files()->in([resource_path('js/pages'), resource_path('js/components')])->name(['*.ts', '*.tsx'])->notName(['*.test.ts', '*.test.tsx']) as $file) {
            preg_match_all('/\b(?:router|form)\s*\.\s*(?:post|put|patch|delete)\s*\(/', $file->getContents(), $matches);
            foreach ($matches[0] as $call) {
                $writers[] = $file->getRelativePathname().':'.preg_replace('/\s+/', '', $call);
            }
        }
        sort($writers);
        $this->assertSame(['clients/proposal.tsx:form.post('], $writers,
            'Use the canonical session API for business writes. Only the recipient signing a proposal keeps an Inertia mutation.');
    }

    public function test_frontend_does_not_reference_retired_mutation_routes(): void
    {
        $violations = [];
        foreach ((new Finder)->files()->in([resource_path('js/pages'), resource_path('js/components')])->name(['*.ts', '*.tsx'])->notName(['*.test.ts', '*.test.tsx']) as $file) {
            $source = $file->getContents();
            // API URLs include /api/v1 immediately before /workspaces. Match
            // the retired web paths even when their identifiers are templates.
            preg_match_all('~(?<!/api/v1)/workspaces/[^\s"\'`]+/(?:time-entries|invoices/[^/\s"\'`]+/time|portal-access|portal-invitations)(?=[/\s"\'`]|$)~', $source, $matches);
            foreach ($matches[0] as $match) {
                $violations[] = $file->getRelativePathname().': '.$match;
            }
            if (preg_match('/svc\.(?:engagement\.time-entries\.(?:store|update|destroy|approve|unapprove)|billing\.invoices\.time)\b|(?<![\w.-])clients\.portal-(?:access|invitations)\.(?:store|update|destroy|resend)\b|(?<![\w.-])projects\.(?:store|update|access\.update)\b/', $source)) {
                $violations[] = $file->getRelativePathname().': retired named route';
            }
        }
        $this->assertSame([], $violations, 'Frontend references to retired web mutations: '.implode(', ', $violations));
    }
}
