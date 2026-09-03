<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Workspace;
use App\Queries\AccessibleWorkspacesQuery;
use App\Services\Navigation\WorkspaceNavigationFactory;
use App\Services\Navigation\WorkspaceReturnPoint;
use App\Support\Navigation\ClientNavigationOption;
use App\Support\Navigation\WorkspaceNavigation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Opening a workspace: work out which client the reader is going to.
 *
 * Every row on the selector points here rather than at a client, because which
 * client to open is a question about this viewer at this moment - the one they
 * were last in, or the only one they can reach - and the selector has no
 * business knowing the answer.
 *
 * It refuses to guess. Where several clients are reachable and none has been
 * chosen, this renders the shell and asks; picking the alphabetically first one
 * would put an operator inside a client they never selected, on a screen full
 * of that client's money. Being asked once is cheaper than reading the wrong
 * client's invoices and not noticing.
 */
class WorkspaceEntryController extends Controller
{
    /**
     * Where the last chosen client is remembered, per workspace.
     *
     * Server session rather than local storage: this decides where a person
     * lands, and a value the browser can edit is not a thing to route on. It is
     * written only after the client has passed authorization, and re-checked
     * against the current options on the way back out - access is revoked, and
     * a remembered id must not outlive it.
     */
    public static function rememberedClientKey(Workspace $workspace): string
    {
        return "svc.workspace.{$workspace->public_id}.client";
    }

    public function __invoke(
        Request $request,
        Workspace $workspace,
        AccessibleWorkspacesQuery $accessible,
        WorkspaceNavigationFactory $navigation,
        WorkspaceReturnPoint $returnPoint,
    ): Response|RedirectResponse {
        $user = $request->user();
        abort_if(! $user instanceof User, 401);

        // 404 rather than 403 for a workspace this person cannot enter, for the
        // same reason every other miss here does: a tenant learns nothing about
        // one it cannot reach, including that it exists.
        abort_unless(
            $accessible->for($user)->contains(fn (Workspace $option): bool => $option->id === $workspace->id),
            404,
        );

        $context = $navigation->for($workspace, $user, null);

        $destination = $this->rememberedClient($request, $workspace, $context)
            ?? $this->clientOnRecord($returnPoint, $user, $workspace, $context)
            ?? $this->onlyClient($context);

        if ($destination !== null) {
            // A workspace-wide URL that became a client module says which one
            // it meant, so the reader lands where they were going rather than
            // on the client's home to click again. Anything unrecognised - or a
            // module this client's route family does not serve - falls back to
            // home rather than being trusted into a URL.
            $module = $request->query('module');
            $href = is_string($module)
                ? $destination->destinations->toArray()[$module] ?? null
                : null;

            return redirect()->to(is_string($href) ? $href : $destination->destinations->home);
        }

        return Inertia::render('workspaces/enter', [
            // Three different sentences, and which one is right depends on
            // both the data and this viewer's authority: choose one, create the
            // first one, or wait for someone to grant you access. Decided here
            // rather than inferred in the browser from an empty switcher, which
            // cannot tell "none yet" from "none for you".
            'has_clients' => $context->clients !== [],
            'can_create_client' => $context->permissions->createClient,
        ]);
    }

    /** The client this person was last inside, if they may still open it. */
    private function rememberedClient(
        Request $request,
        Workspace $workspace,
        WorkspaceNavigation $context,
    ): ?ClientNavigationOption {
        $remembered = $request->session()->get(self::rememberedClientKey($workspace));

        if (! is_string($remembered)) {
            return null;
        }

        // Revalidated against this viewer's current options rather than trusted.
        // A remembered id survives the grant that produced it: a project scope
        // narrowed, a portal membership removed, or a session carried over from
        // another tenant entirely.
        foreach ($context->clients as $client) {
            if ($client->id === $remembered) {
                return $client;
            }
        }

        return null;
    }

    /**
     * The client this person was last inside, from before this session.
     *
     * The session is asked first, because it is the more specific answer: it
     * remembers a client per workspace for as long as the session lives. This
     * is the fallback underneath it - a new device, or a cookie that expired
     * over a weekend - and it is revalidated exactly as hard, against the
     * options this viewer has right now rather than the ones they had when it
     * was written.
     */
    private function clientOnRecord(
        WorkspaceReturnPoint $returnPoint,
        User $user,
        Workspace $workspace,
        WorkspaceNavigation $context,
    ): ?ClientNavigationOption {
        $remembered = $returnPoint->rememberedClientId($user, $workspace);

        if ($remembered === null) {
            return null;
        }

        foreach ($context->clients as $client) {
            if ($client->id === $remembered) {
                return $client;
            }
        }

        return null;
    }

    /** With exactly one reachable client there is nothing to choose. */
    private function onlyClient(WorkspaceNavigation $context): ?ClientNavigationOption
    {
        return count($context->clients) === 1 ? $context->clients[0] : null;
    }
}
