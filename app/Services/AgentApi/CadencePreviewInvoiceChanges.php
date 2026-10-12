<?php

namespace App\Services\AgentApi;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Which of a company's other invoices a previewed generation created, rewrote
 * or removed, read inside the preview's transaction before its rollback.
 *
 * Generation is not confined to the period's own draft: on a non-monthly
 * agreement with interim overage billing it creates missing interim drafts and
 * strips the claims of existing unissued ones before building the cadence
 * draft. A preview that reported only new rows would hide those rewrites.
 * Only drafts are compared, because nothing the engine does may change an
 * issued invoice - the rehearsal command proves that against the database.
 *
 * A draft counts as rewritten when its stored facts, its lines or the time
 * those lines link differ afterwards. Row ids and timestamps are left out, so
 * a draft rebuilt to identical content is not reported.
 */
final class CadencePreviewInvoiceChanges
{
    private const array VOLATILE = ['id', 'updated_at', 'created_at'];

    /** @return array{max_id: int, drafts: array<int, array{invoice: ClientInvoice, state: string}>} */
    public function before(Workspace $workspace, ClientCompany $company): array
    {
        $drafts = $this->states($workspace, $this->companyInvoices($workspace, $company)->where('status', 'draft')->get());

        return [
            'max_id' => (int) ClientInvoice::query()->where('workspace_id', $workspace->id)->max('id'),
            'drafts' => $drafts,
        ];
    }

    /**
     * @param  array{max_id: int, drafts: array<int, array{invoice: ClientInvoice, state: string}>}  $before
     * @return list<array{change: 'created'|'updated'|'deleted', invoice: ClientInvoice}>
     */
    public function after(Workspace $workspace, ClientCompany $company, array $before, int $primaryId): array
    {
        $known = array_keys($before['drafts']);
        $after = $this->states($workspace, $this->companyInvoices($workspace, $company)
            ->where(fn ($candidates) => $candidates->whereIn('id', $known)->orWhere('id', '>', $before['max_id']))
            ->whereKeyNot($primaryId)->get());

        $changes = [];
        foreach ($after as $id => $current) {
            $previous = $before['drafts'][$id] ?? null;
            if ($previous === null) {
                $changes[] = ['change' => 'created', 'invoice' => $current['invoice']];
            } elseif ($previous['state'] !== $current['state']) {
                $changes[] = ['change' => 'updated', 'invoice' => $current['invoice']];
            }
        }
        foreach ($before['drafts'] as $id => $previous) {
            if ($id !== $primaryId && ! isset($after[$id])) {
                $changes[] = ['change' => 'deleted', 'invoice' => $previous['invoice']];
            }
        }

        return $changes;
    }

    /** @return Builder<ClientInvoice> */
    private function companyInvoices(Workspace $workspace, ClientCompany $company): Builder
    {
        return ClientInvoice::query()->where('workspace_id', $workspace->id)->where('client_company_id', $company->id)->orderBy('id');
    }

    /**
     * Three queries however many invoices: the invoices, their lines, and the
     * time those lines link.
     *
     * @param  Collection<int, ClientInvoice>  $invoices
     * @return array<int, array{invoice: ClientInvoice, state: string}>
     */
    private function states(Workspace $workspace, Collection $invoices): array
    {
        $lines = DB::table('client_invoice_lines')->where('workspace_id', $workspace->id)
            ->whereIn('client_invoice_id', $invoices->modelKeys())->orderBy('sort_order')->orderBy('id')->get();
        $linked = DB::table('client_invoice_line_time_entries')->where('workspace_id', $workspace->id)
            ->whereIn('client_invoice_line_id', $lines->pluck('id'))->orderBy('client_time_entry_id')->get()
            ->groupBy(static fn (stdClass $pivot): int => (int) $pivot->client_invoice_line_id);

        $byInvoice = [];
        foreach ($lines as $line) {
            $row = array_diff_key((array) $line, array_flip([...self::VOLATILE, 'public_id']));
            $row['time'] = $linked->get((int) $line->id, collect())->map(static fn (stdClass $pivot): array => array_diff_key((array) $pivot, array_flip([...self::VOLATILE, 'client_invoice_line_id'])))->values()->all();
            $byInvoice[(int) $line->client_invoice_id][] = $row;
        }

        $states = [];
        foreach ($invoices as $invoice) {
            $facts = array_diff_key($invoice->getAttributes(), array_flip(self::VOLATILE));
            ksort($facts);
            $states[(int) $invoice->id] = [
                'invoice' => $invoice,
                'state' => hash('sha256', json_encode([$facts, $byInvoice[(int) $invoice->id] ?? []], JSON_THROW_ON_ERROR)),
            ];
        }

        return $states;
    }
}
