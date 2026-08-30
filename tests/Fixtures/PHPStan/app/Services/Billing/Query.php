<?php

declare(strict_types=1);

namespace Tests\Fixtures\PHPStan\app\Services\Billing;

final class Query
{
    public function where(mixed ...$arguments): self
    {
        return $this;
    }

    public function orWhere(mixed ...$arguments): self
    {
        return $this;
    }

    public function whereNotIn(mixed ...$arguments): self
    {
        return $this;
    }

    public function orWhereNotIn(mixed ...$arguments): self
    {
        return $this;
    }

    public function whereRaw(mixed ...$arguments): self
    {
        return $this;
    }

    public function orWhereRaw(mixed ...$arguments): self
    {
        return $this;
    }
}

function invalidStatusQueries(Query $query, ?Query $nullable): void
{
    $query->whereNotIn('status', ['void']);
    $query->orWhereNotIn('client_invoices.status', ['void']);
    $query->where('status', '!=', 'void');
    $query->orWhere('client_invoices.status', '<>', 'void');
    $nullable?->where('status', '!=', 'void');
    $query->where([['status', '!=', 'void']]);
    $query->where('status', '=', 'draft');
    $query->where([['client_invoices.status', '<>', 'void']]);
    $query->where('workspace_id', '!=', 123);
    $conditions = [['client_invoices.status', '!=', 'void']];
    $query->orWhere($conditions);
    $query->whereRaw('client_invoices.status <> ?', ['void']);
    $query->orWhereRaw('`status` != ?', ['void']);
    $query->whereRaw('status = ? and workspace_id != ?', ['draft', 123]);
}
