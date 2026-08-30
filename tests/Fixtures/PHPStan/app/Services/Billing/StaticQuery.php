<?php

declare(strict_types=1);

namespace Tests\Fixtures\PHPStan\app\Services\Billing;

final class StaticQuery
{
    public static function where(mixed ...$arguments): void {}
}

StaticQuery::where('client_invoices.status', '<>', 'void');
