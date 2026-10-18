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

    public function test_frontend_does_not_reference_retired_mutation_routes(): void
    {
        $violations = [];
        foreach ((new Finder)->files()->in([resource_path('js/pages'), resource_path('js/components')])->name(['*.ts', '*.tsx'])->notName(['*.test.ts', '*.test.tsx']) as $file) {
            $source = $file->getContents();
            // API URLs include /api/v1 immediately before /workspaces. Match
            // the retired web paths even when their identifiers are templates.
            preg_match_all('~(?<!/api/v1)/workspaces/[^\s"\'`]+/(?:time-entries|invoices/[^/\s"\'`]+/time)(?=[/\s"\'`]|$)~', $source, $matches);
            foreach ($matches[0] as $match) {
                $violations[] = $file->getRelativePathname().': '.$match;
            }
            if (preg_match('/svc\.(?:engagement\.time-entries\.(?:store|update|destroy|approve|unapprove)|billing\.invoices\.time)\b/', $source)) {
                $violations[] = $file->getRelativePathname().': retired named route';
            }
        }
        $this->assertSame([], $violations, 'Frontend references to retired web mutations: '.implode(', ', $violations));
    }
}
