<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

final class BillingMaintenanceBusy extends HttpException
{
    public function __construct(bool $maintenance)
    {
        parent::__construct(409, $maintenance
            ? 'Billing writers are in progress; maintenance could not start within 2 seconds. Nothing changed.'
            : 'Billing maintenance in progress, try again.', headers: ['Retry-After' => '2']);
    }
}
