<?php

namespace App\Services\Authorization;

use App\Models\ClientAgreement;
use App\Models\ClientInvoice;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Whether one person may see one invoice or agreement.
 *
 * This exists because reachability had one meaning too few. #157 answers "which
 * clients does this member reach", by going through the projects they hold a
 * membership on - which is right for deciding whether a client's shell appears
 * in a directory. It is not right for the money inside that shell: a member
 * granted one project of a client was reading every invoice, line, payment and
 * agreement belonging to it, because those queries established company
 * reachability and then filtered on the company alone.
 *
 * So the company answer decides whether you can see the client. This decides
 * whether you can see a particular financial record of theirs.
 *
 * ## The rule
 *
 * An invoice is attributed to the projects its lines name, plus the project its
 * agreement names. A scoped viewer may see it when that set is non-empty and
 * every member of it is a project they can view.
 *
 * Both halves matter. Requiring *every* attributed project means a mixed
 * invoice - lines from a project you hold and one you do not - is refused
 * rather than partially rendered: totals, payments and the PDF describe the
 * whole document, so showing some of its lines beside all of its money would
 * be a worse answer than showing none of it.
 *
 * Requiring the set to be *non-empty* is the fail-closed half. An invoice with
 * no project lineage at all - a company-level fee - is manager scope only,
 * because "belongs to no project" is not evidence that it belongs to yours.
 * Widening that later is safe; discovering it was too wide is not.
 *
 * An owner or admin reaches everything, and skips all of it.
 */
final class BillingRecordAccess
{
    public function __construct(private readonly ProjectAccess $projects) {}

    public function canViewInvoice(User $user, Workspace $workspace, ClientInvoice $invoice): bool
    {
        $viewable = $this->projects->viewableProjectIds($user, $workspace);

        if ($viewable === null) {
            return true;
        }

        $attributed = $this->attributedProjectIds($invoice);

        return $attributed !== [] && array_diff($attributed, $viewable) === [];
    }

    public function canViewAgreement(User $user, Workspace $workspace, ClientAgreement $agreement): bool
    {
        $viewable = $this->projects->viewableProjectIds($user, $workspace);

        if ($viewable === null) {
            return true;
        }

        // A company-wide agreement names no project, and its terms cover work
        // this viewer cannot see, so it is refused on the same reasoning as an
        // invoice with no lineage.
        return $agreement->client_project_id !== null
            && in_array((int) $agreement->client_project_id, $viewable, true);
    }

    /**
     * Narrow a query to the invoices this viewer may see.
     *
     * Set-based rather than a filter over loaded rows, because the screens that
     * need it list invoices - asking per row would cost a query per row, which
     * is how the directory's first scoping attempt turned a 13-query page into
     * a 53-query one.
     *
     * @param  Builder<ClientInvoice>  $invoices
     * @return Builder<ClientInvoice>
     */
    public function constrainInvoices(Builder $invoices, User $user, Workspace $workspace): Builder
    {
        $viewable = $this->projects->viewableProjectIds($user, $workspace);

        if ($viewable === null) {
            return $invoices;
        }

        if ($viewable === []) {
            return $invoices->whereRaw('1 = 0');
        }

        return $invoices
            // Nothing attributed outside what they hold.
            ->whereNotExists(fn (QueryBuilder $line): QueryBuilder => $line
                ->select(DB::raw('1'))
                ->from('client_invoice_lines')
                ->whereColumn('client_invoice_lines.client_invoice_id', 'client_invoices.id')
                ->whereColumn('client_invoice_lines.workspace_id', 'client_invoices.workspace_id')
                ->whereNotNull('client_invoice_lines.client_project_id')
                ->whereNotIn('client_invoice_lines.client_project_id', $viewable))
            ->whereNotExists(fn (QueryBuilder $agreement): QueryBuilder => $agreement
                ->select(DB::raw('1'))
                ->from('client_agreements')
                ->whereColumn('client_agreements.id', 'client_invoices.client_agreement_id')
                ->whereColumn('client_agreements.workspace_id', 'client_invoices.workspace_id')
                ->whereNotNull('client_agreements.client_project_id')
                ->whereNotIn('client_agreements.client_project_id', $viewable))
            // And something attributed inside it, so an invoice with no lineage
            // at all does not pass by having nothing to disqualify it.
            ->where(fn (Builder $attributed): Builder => $attributed
                ->whereExists(fn (QueryBuilder $line): QueryBuilder => $line
                    ->select(DB::raw('1'))
                    ->from('client_invoice_lines')
                    ->whereColumn('client_invoice_lines.client_invoice_id', 'client_invoices.id')
                    ->whereColumn('client_invoice_lines.workspace_id', 'client_invoices.workspace_id')
                    ->whereIn('client_invoice_lines.client_project_id', $viewable))
                ->orWhereExists(fn (QueryBuilder $agreement): QueryBuilder => $agreement
                    ->select(DB::raw('1'))
                    ->from('client_agreements')
                    ->whereColumn('client_agreements.id', 'client_invoices.client_agreement_id')
                    ->whereColumn('client_agreements.workspace_id', 'client_invoices.workspace_id')
                    ->whereIn('client_agreements.client_project_id', $viewable)));
    }

    /**
     * Narrow a query to the agreements this viewer may see.
     *
     * @param  Builder<ClientAgreement>  $agreements
     * @return Builder<ClientAgreement>
     */
    public function constrainAgreements(Builder $agreements, User $user, Workspace $workspace): Builder
    {
        $viewable = $this->projects->viewableProjectIds($user, $workspace);

        if ($viewable === null) {
            return $agreements;
        }

        return $viewable === []
            ? $agreements->whereRaw('1 = 0')
            : $agreements->whereIn('client_project_id', $viewable);
    }

    /**
     * The projects an invoice is attributed to, from its lines and agreement.
     *
     * @return list<int>
     */
    private function attributedProjectIds(ClientInvoice $invoice): array
    {
        $ids = $invoice->lines()
            ->where('workspace_id', $invoice->workspace_id)
            ->whereNotNull('client_project_id')
            ->pluck('client_project_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $agreementProject = ClientAgreement::query()
            ->where('workspace_id', $invoice->workspace_id)
            ->whereKey($invoice->client_agreement_id)
            ->value('client_project_id');

        if ($agreementProject !== null) {
            $ids[] = (int) $agreementProject;
        }

        return array_values(array_unique($ids));
    }
}
