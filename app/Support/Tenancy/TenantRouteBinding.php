<?php

namespace App\Support\Tenancy;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Implicit HTTP bindings only: never an ambient scope on domain queries. */
final class TenantRouteBinding
{
    public function __construct(private readonly Request $request) {}

    public function resolve(Model $model, mixed $value, ?string $field, bool $withTrashed = false): ?Model
    {
        $workspace = $this->request->route('workspace');
        $company = $this->request->route('clientCompany');
        $query = $model->newQuery()->where($model->qualifyColumn($field ?? $model->getRouteKeyName()), $value);

        if ($withTrashed) {
            $query->withoutGlobalScope(SoftDeletingScope::class);
        }

        if ($workspace instanceof Workspace) {
            if ($model instanceof ClientInvoice && $this->request->routeIs(
                'svc.billing.invoices.show', 'svc.billing.invoices.pdf', 'svc.billing.invoices.stripe-payment-intent',
            ) && ! Gate::allows('view', $workspace)) {
                $user = $this->request->user();
                abort_unless($user instanceof User, 403);
                $query->whereExists(function (Builder $members) use ($workspace, $user): void {
                    $members->selectRaw('1')->from('client_company_memberships')
                        ->where('client_company_memberships.workspace_id', $workspace->id)
                        ->whereColumn('client_company_memberships.client_company_id', 'client_invoices.client_company_id')
                        ->where('client_company_memberships.user_id', $user->id);
                });
            } else {
                Gate::authorize('view', $workspace);
            }
            $query->where($model->qualifyColumn('workspace_id'), $workspace->id);
        } elseif ($company instanceof ClientCompany) {
            $query->where($model->qualifyColumn('workspace_id'), $company->workspace_id);
        } elseif ($model instanceof ClientCompany && $this->request->routeIs('portal.*', 'svc.engagement.proposals.accept')) {
            $user = $this->request->user();
            abort_unless($user instanceof User, 403);
            // Portal URLs have no workspace segment. Derive the allowed tenant
            // inside SQL, without first loading the company to discover it.
            $query->whereIn('client_companies.workspace_id', function (Builder $tenants) use ($user): void {
                $tenants->select('workspaces.id')->from('workspaces')
                    ->where(function (Builder $access) use ($user): void {
                        $access->whereExists(function (Builder $members) use ($user): void {
                            $members->selectRaw('1')->from('workspace_memberships')
                                ->whereColumn('workspace_memberships.workspace_id', 'workspaces.id')
                                ->where('workspace_memberships.user_id', $user->id)
                                ->whereIn('workspace_memberships.role', ['owner', 'admin']);
                        })->orWhereExists(function (Builder $members) use ($user): void {
                            $members->selectRaw('1')->from('client_company_memberships')
                                ->whereColumn('client_company_memberships.workspace_id', 'workspaces.id')
                                ->whereColumn('client_company_memberships.client_company_id', 'client_companies.id')
                                ->where('client_company_memberships.user_id', $user->id);
                        });
                    });
            });
        } else {
            // A new tenant-owned binding must declare how its tenant is known.
            abort(404);
        }

        if ($company instanceof ClientCompany && ! $model instanceof ClientCompany) {
            $query->where($model->qualifyColumn('client_company_id'), $company->id);
        }

        $invoice = $this->request->route('clientInvoice');
        if ($invoice instanceof ClientInvoice && $model instanceof ClientInvoicePayment) {
            $query->where('client_invoice_id', $invoice->id);
        }

        return $query->first();
    }
}
