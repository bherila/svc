<?php

namespace App\Support\Billing;

enum SubcontractorBillingMode: string
{
    /** Billed as its own line at the snapshotted subcontractor rate. */
    case FlatHourly = 'flat_hourly';

    /** Draws on the agreement retainer like consultant time. */
    case Retainer = 'retainer';

    /** Tracked here, but billed directly by the subcontractor. */
    case Direct = 'direct';
}
