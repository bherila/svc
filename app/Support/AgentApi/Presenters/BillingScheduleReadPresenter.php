<?php

namespace App\Support\AgentApi\Presenters;

use App\Models\ClientBillingSchedule;
use App\Support\AgentApi\AgentApiVersion;

/** The bounded billing-schedule representation already shown in operations. */
final class BillingScheduleReadPresenter
{
    /** @return array{id:string,agreement_id:string,version:string,cadence:string,next_run_on:string,is_active:bool} */
    public function present(ClientBillingSchedule $schedule): array
    {
        return [
            'id' => $schedule->public_id,
            'version' => AgentApiVersion::for($schedule),
            'agreement_id' => $schedule->agreement->public_id,
            'cadence' => $schedule->cadence,
            'next_run_on' => $schedule->next_run_on->toDateString(),
            'is_active' => $schedule->is_active,
        ];
    }
}
