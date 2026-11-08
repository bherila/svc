<?php

namespace App\Services\Search;

use App\Models\ClientCompany;
use App\Models\ClientProjectMembership;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\PortalInvoiceQuery;
use App\Services\Collaboration\CollaborationAudience;
use App\Services\Navigation\WorkspaceNavigationFactory;
use App\Support\AgentApi\ProjectRole;
use App\Support\Billing\InvoiceStatus;
use App\Support\Navigation\ClientNavigationOption;
use App\Support\Navigation\WorkspaceNavigation;
use App\Support\Search\PaletteAction;

/**
 * The commands the palette offers one person, as finished URLs.
 *
 * ## Built from the navigation, not beside it
 *
 * Every destination comes from {@see WorkspaceNavigationFactory}, which already
 * decides - per role, per edition, per client - which modules a viewer may
 * reach and hands back finished URLs or null. The palette asks it rather than
 * restating the rules, so "Go to Payments" appears exactly when the navbar's
 * Payments link would, and the two cannot drift. An action the viewer cannot
 * carry out is absent from the list, not disabled in it: the browser filters
 * this list as they type, and a list that contained a revenue link for staff
 * would be a link published to whoever opened dev tools.
 *
 * Revenue is the case the rule exists for. It lives on the Dashboard, which
 * every member can open for their own queue, so the Dashboard URL alone proves
 * nothing; "Go to Revenue" is offered only when the navigation says the viewer
 * manages the workspace.
 */
final class PaletteActions
{
    public function __construct(
        private readonly WorkspaceNavigationFactory $navigation,
        private readonly PortalInvoiceQuery $portalInvoices,
    ) {}

    /** @return list<PaletteAction> */
    public function for(User $user, Workspace $workspace, CollaborationAudience $audience, ?ClientCompany $company): array
    {
        $navigation = $this->navigation->for($workspace, $user, $company);

        $actions = $audience === CollaborationAudience::Portal
            ? $this->portal($user, $workspace, $navigation)
            : $this->workspace($user, $workspace, $navigation);

        return [...$actions, ...self::accountLevel()];
    }

    /**
     * The actions that need no workspace at all, offered last everywhere and
     * alone to a viewer on no workspace's page.
     *
     * @return list<PaletteAction>
     */
    public static function accountLevel(): array
    {
        return [new PaletteAction('switch_workspace', 'Switch workspace…', route('workspaces.index', absolute: false), ['change', 'workspaces', 'tenant', 'account'])];
    }

    /** @return list<PaletteAction> */
    private function workspace(User $user, Workspace $workspace, WorkspaceNavigation $navigation): array
    {
        $destinations = $navigation->workspaceDestinations;
        $manages = $navigation->permissions->manageWorkspace;
        $billing = $destinations !== null && $destinations->invoices !== null;
        $picker = $destinations?->clients;

        // A client the viewer operates. A portal-family option (a person who is
        // staff in this workspace and a client contact of another company) has
        // no Expenses tab, which is what tells the two apart.
        $client = $navigation->currentClient();
        $client = $client instanceof ClientNavigationOption && $client->destinations->expenses !== null ? $client : null;

        $actions = [];

        $logTime = $client?->destinations->time;
        $logTimeHint = null;
        if ($logTime === null && $manages) {
            $logTime = $picker;
            $logTimeHint = 'Choose a client';
        } elseif ($logTime === null && $this->canLogAnywhere($user, $workspace)) {
            $logTime = $destinations?->myWork ?? $destinations?->timesheet ?? route('workspaces.my-work', $workspace, absolute: false);
        }
        if ($logTime !== null) {
            $actions[] = new PaletteAction('log_time', 'Log time', $logTime, ['hours', 'timer', 'track', 'worked'], $logTimeHint);
        }

        if ($manages && $billing) {
            $invoices = $client?->destinations->invoices;
            if ($invoices !== null || $picker !== null) {
                $actions[] = new PaletteAction('new_invoice', 'New invoice draft', $invoices ?? (string) $picker, ['bill', 'create', 'invoice'], $invoices === null ? 'Choose a client' : null);
            }
        }

        if ($manages) {
            $expenses = $client?->destinations->expenses;
            if ($expenses !== null || $picker !== null) {
                $actions[] = new PaletteAction('new_expense', 'New expense', $expenses ?? (string) $picker, ['receipt', 'cost', 'spend', 'reimburse'], $expenses === null ? 'Choose a client' : null);
            }
        }

        if ($navigation->paymentsHref !== null) {
            $actions[] = new PaletteAction('payments', 'Go to Payments', $navigation->paymentsHref, ['stripe', 'square', 'processor', 'connect', 'keys']);
        }

        // Revenue is a manager's: the Dashboard it sits on is open to every
        // member, so the manager check is made here rather than inferred from
        // the URL being present.
        if ($manages && $destinations !== null && $destinations->dashboard !== null) {
            $actions[] = new PaletteAction('revenue', 'Go to Revenue', $destinations->dashboard, ['income', 'forecast', 'projection', 'dashboard', 'money']);
        }

        if ($navigation->approvalsHref !== null) {
            $actions[] = new PaletteAction('approvals', 'Approvals', $navigation->approvalsHref, ['approve', 'review', 'waiting', 'queue']);
        }

        $myWork = $destinations?->myWork ?? ($destinations?->clients !== null ? route('workspaces.my-work', $workspace, absolute: false) : null);
        if ($myWork !== null) {
            $actions[] = new PaletteAction('my_work', 'My work', $myWork, ['tasks', 'assigned', 'todo', 'queue']);
        }

        $settings = $navigation->permissions->manageCurrentClient ? $client?->destinations->settings : null;
        if ($settings !== null) {
            $actions[] = new PaletteAction('settings', 'Settings', $settings, ['client settings', 'configure', 'preferences', 'portal access']);
        }

        return $actions;
    }

    /** @return list<PaletteAction> */
    private function portal(User $user, Workspace $workspace, WorkspaceNavigation $navigation): array
    {
        $option = $navigation->currentClient() ?? (count($navigation->clients) === 1 ? $navigation->clients[0] : null);
        $actions = [];

        if ($option instanceof ClientNavigationOption) {
            $destinations = $option->destinations;

            if ($destinations->invoices !== null && $this->hasInvoiceToPay($user, $workspace, $option)) {
                $actions[] = new PaletteAction('pay', 'Pay an invoice', $destinations->invoices, ['pay', 'balance', 'owed', 'due', 'card']);
            }
            if ($destinations->billing !== null) {
                $actions[] = new PaletteAction('billing', 'Billing', $destinations->billing, ['card', 'autopay', 'payment methods', 'saved']);
            }
            if ($destinations->invoices !== null) {
                $actions[] = new PaletteAction('invoices', 'Invoices', $destinations->invoices, ['statements', 'bills']);
            }
        }

        if ($navigation->approvalsHref !== null) {
            $actions[] = new PaletteAction('approvals', 'Approvals', $navigation->approvalsHref, ['approve', 'review', 'waiting']);
        }

        return $actions;
    }

    private function hasInvoiceToPay(User $user, Workspace $workspace, ClientNavigationOption $option): bool
    {
        $company = ClientCompany::query()->where('workspace_id', $workspace->id)->where('public_id', $option->id)->first();

        return $company instanceof ClientCompany
            && $this->portalInvoices->visibleTo($company, $user)->whereIn('status', InvoiceStatus::collectible())->exists();
    }

    /** Whether a member who is not an owner or admin holds a project they may log time on. */
    private function canLogAnywhere(User $user, Workspace $workspace): bool
    {
        $roles = array_map(
            static fn (ProjectRole $role): string => $role->value,
            array_values(array_filter(ProjectRole::cases(), static fn (ProjectRole $role): bool => $role->canLogTime())),
        );

        return ClientProjectMembership::query()->where('workspace_id', $workspace->id)->where('user_id', $user->id)
            ->whereIn('role', $roles)->exists();
    }
}
