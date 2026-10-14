<?php

namespace App\Services\Square;

use App\Models\ClientInvoice;
use App\Models\Workspace;
use App\Support\Square\SquareDashboardPage;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Derived flags change immediately when a payment, active link or mirror changes. */
final class SquarePaymentMismatchAudit
{
    /** @return array{code: string, dashboard_url: string}|null */
    public function invoice(Workspace $workspace, ClientInvoice $invoice): ?array
    {
        if ($invoice->workspace_id !== $workspace->id || ! SquareConfiguration::fromConfig()->servesWorkspace($workspace)) {
            return null;
        }
        $row = $this->query($workspace)->where('i.id', $invoice->id)->first(['l.square_invoice_id']);
        if ($row === null || ! is_string($row->square_invoice_id)) {
            return null;
        }
        $url = SquareDashboardPage::invoice(SquareConfiguration::fromConfig()->environment, $row->square_invoice_id);

        return $url === null ? null : ['code' => 'paid_outside_square', 'dashboard_url' => $url];
    }

    /** @return array{count: int, invoices: list<array<string, string>>, truncated: bool} */
    public function report(Workspace $workspace): array
    {
        if (! SquareConfiguration::fromConfig()->servesWorkspace($workspace)) {
            return ['count' => 0, 'invoices' => [], 'truncated' => false];
        }
        $query = $this->query($workspace);
        $count = (clone $query)->count();
        $rows = (clone $query)->orderBy('i.id')->limit(100)->get(['i.public_id', 'c.public_id as company_public_id', 'l.square_invoice_id']);
        $items = [];
        foreach ($rows as $row) {
            $url = SquareDashboardPage::invoice(SquareConfiguration::fromConfig()->environment, $row->square_invoice_id);
            if ($url !== null) {
                $items[] = ['invoice_id' => $row->public_id, 'url' => route('clients.invoice', [$workspace, $row->company_public_id, $row->public_id]), 'code' => 'paid_outside_square', 'dashboard_url' => $url];
            }
        }

        return ['count' => $count, 'invoices' => $items, 'truncated' => $count > count($items)];
    }

    private function query(Workspace $workspace): Builder
    {
        return DB::table('client_invoices as i')->where('i.workspace_id', $workspace->id)->where('i.status', 'paid')
            ->join('client_companies as c', fn ($join) => $join->on('c.id', '=', 'i.client_company_id')->where('c.workspace_id', $workspace->id))
            ->join('invoice_square_links as l', fn ($join) => $join->on('l.active_invoice_slot', '=', 'i.id')->where('l.workspace_id', $workspace->id))
            ->join('square_invoice_snapshots as s', fn ($join) => $join->on('s.square_invoice_id', '=', 'l.square_invoice_id')->where('s.workspace_id', $workspace->id)->whereNull('s.deleted_at'))
            ->whereIn('s.status', ['UNPAID', 'PARTIALLY_PAID', 'PAYMENT_PENDING', 'SCHEDULED'])
            ->whereExists(fn ($payments) => $payments->selectRaw('1')->from('client_invoice_payments as p')
                ->where('p.workspace_id', $workspace->id)->whereColumn('p.client_invoice_id', 'i.id')->where('p.status', 'succeeded')
                ->whereColumn('p.amount', '>', 'p.refunded_amount')->where(fn ($outside) => $outside->whereNull('p.provider')->orWhere('p.provider', '!=', 'square')));
    }
}
