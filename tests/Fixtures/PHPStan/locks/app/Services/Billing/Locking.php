<?php

namespace Tests\Fixtures\PHPStan\locks\app\Services\Billing;

use App\Models\ClientInvoice;
use App\Support\Concurrency\Locks;
use Illuminate\Support\Facades\DB;

final class Locking
{
    public function routed(int $id): ?ClientInvoice
    {
        return ClientInvoice::query()->whereKey($id)->tap(Locks::forUpdate())->first();
    }

    public function raw(int $id): ?ClientInvoice
    {
        return ClientInvoice::query()->whereKey($id)->lockForUpdate()->first();
    }

    public function rawOnAPlainTable(int $id): mixed
    {
        return DB::table('client_invoices')->where('id', $id)->lockForUpdate()->first();
    }

    public function rawStatically(int $id): ?ClientInvoice
    {
        return ClientInvoice::lockForUpdate()->whereKey($id)->first();
    }

    public function sharedIsNotThisRulesBusiness(int $id): ?ClientInvoice
    {
        return ClientInvoice::query()->whereKey($id)->sharedLock()->first();
    }
}
