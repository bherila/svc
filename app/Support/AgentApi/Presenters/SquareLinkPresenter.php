<?php

namespace App\Support\AgentApi\Presenters;

use App\Models\ClientInvoice;
use App\Models\CompanySquareCustomer;
use App\Models\InvoiceSquareLink;
use App\Models\User;
use App\Services\Square\SquareCollectionGuard;
use App\Services\Square\SquareLinkRepository;
use App\Support\AgentApi\AgentApiVersion;

/**
 * How SVC's Square links read, for operators and agents (#438).
 *
 * Square identifiers only, as they were confirmed: no Square URL is ever built
 * here. The hosted page arrives later from Square's own data, never from an
 * identifier assembled into an address.
 */
final class SquareLinkPresenter
{
    /** @var array<int, string|null> */
    private array $names = [];

    public function __construct(
        private readonly SquareLinkRepository $links,
        private readonly SquareCollectionGuard $guard,
    ) {}

    /** @return array<string, mixed> */
    public function invoice(ClientInvoice $invoice): array
    {
        $link = $this->links->activeLink($invoice);
        $refusal = $this->guard->refusal($invoice);

        return [
            'invoice_id' => $invoice->public_id,
            'invoice_version' => AgentApiVersion::for($invoice),
            'link' => $link === null ? null : $this->link($link),
            'agreement_series_key' => $this->links->agreementSeriesKey($invoice),
            'square_collects' => $refusal !== null,
            'collection_refusal' => $refusal,
        ];
    }

    /** @return array<string, mixed> */
    public function link(InvoiceSquareLink $link): array
    {
        return [
            'id' => $link->public_id,
            'square_invoice_id' => $link->square_invoice_id,
            'square_invoice_number' => $link->square_invoice_number,
            'series_key' => $link->series_key,
            'currency' => $link->currency,
            'match_method' => $link->match_method->value,
            'score' => $link->score,
            'reasons' => $link->reasons,
            'linked_at' => $link->linked_at->toISOString(),
            'linked_by' => $this->name($link->linked_by_user_id),
        ];
    }

    /** @return array<string, mixed>|null */
    public function customer(?CompanySquareCustomer $mapping): ?array
    {
        return $mapping === null ? null : [
            'square_customer_id' => $mapping->square_customer_id,
            'confirmed_at' => $mapping->confirmed_at->toISOString(),
            'confirmed_by' => $this->name($mapping->confirmed_by_user_id),
        ];
    }

    /**
     * Resolve the names a page of mappings or links will show in one query,
     * so presenting them reads none.
     *
     * @param  iterable<int|null>  $userIds
     */
    public function primeNames(iterable $userIds): void
    {
        $missing = [];
        foreach ($userIds as $id) {
            if ($id !== null && ! array_key_exists($id, $this->names)) {
                $missing[$id] = $id;
            }
        }
        if ($missing === []) {
            return;
        }
        $found = User::query()->whereKey(array_values($missing))->pluck('name', 'id');
        foreach ($missing as $id) {
            $name = $found->get($id);
            $this->names[$id] = is_string($name) ? $name : null;
        }
    }

    private function name(?int $userId): ?string
    {
        if ($userId === null) {
            return null;
        }
        $this->primeNames([$userId]);

        return $this->names[$userId];
    }
}
