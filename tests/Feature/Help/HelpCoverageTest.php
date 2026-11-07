<?php

namespace Tests\Feature\Help;

use App\Http\Middleware\ResolveWorkspaceNavigation;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Every page an operator or a portal user can open has a help article.
 *
 * Articles are `docs/help/*.md`; their front matter names the route names they
 * answer for. A new page without an article fails here, which is what keeps
 * help changing in the same commit as the feature it explains.
 */
class HelpCoverageTest extends TestCase
{
    /**
     * Routes under the workspace shell that are not pages a person reads.
     *
     * @var array<string, string>
     */
    private const array EXEMPT = [
        'workspaces.enter' => 'redirects to a client',
        'clients.manage' => 'redirect to client settings',
        'workspaces.operations' => 'redirect to the dashboard, the retired Operations page',
        'portal.files.parent.download' => 'a file download',
        'portal.files.collaboration.download' => 'a file download',
        'collaboration.comments.index' => 'a fragment of a discussion page',
        'portal.collaboration.comments.index' => 'a fragment of a discussion page',
    ];

    /** @return array<string, list<string>> route name => article slugs */
    private function articleRoutes(): array
    {
        $map = [];

        foreach (glob(__DIR__.'/../../../docs/help/*.md') ?: [] as $file) {
            $this->assertSame(1, preg_match('/\A---\n(.*?)\n---\n/s', (string) file_get_contents($file), $m), basename($file).' has front matter');
            $this->assertSame(1, preg_match('/^routes:\s*\[(.*)\]\s*$/m', $m[1], $routes), basename($file).' lists routes');

            foreach (array_filter(array_map('trim', explode(',', $routes[1]))) as $name) {
                $map[$name][] = basename($file, '.md');
            }
        }

        return $map;
    }

    public function test_every_operator_and_portal_page_has_an_article(): void
    {
        $covered = $this->articleRoutes();
        $missing = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            /** @var LaravelRoute $route */
            $name = $route->getName();

            if ($name === null || ! in_array('GET', $route->methods(), true)) {
                continue;
            }

            if (! in_array(ResolveWorkspaceNavigation::class, $route->gatherMiddleware(), true)) {
                continue;
            }

            if (isset(self::EXEMPT[$name]) || isset($covered[$name])) {
                continue;
            }

            $missing[] = $name;
        }

        $this->assertSame([], $missing, 'These pages have no help article. Add the route name to the `routes` front matter of one in docs/help.');
    }

    public function test_articles_name_only_routes_that_exist(): void
    {
        $unknown = array_values(array_filter(
            array_keys($this->articleRoutes()),
            static fn (string $name): bool => ! Route::has($name),
        ));

        $this->assertSame([], $unknown);
    }
}
