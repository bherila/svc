<?php

namespace App\Support\Revenue;

use Carbon\CarbonImmutable;

/**
 * An agreement starting or ending inside the projection window, with the
 * monthly recurring amount that arrives or drops off with it. An ending is
 * never assumed to renew.
 */
final readonly class AgreementChange
{
    public const string ENDING = 'ending';

    public const string STARTING = 'starting';

    public function __construct(
        public string $kind,
        public ProjectedAgreement $agreement,
        public CarbonImmutable $date,
        public int $monthlyAmount,
    ) {}

    /** @return array{agreement_id: string, client_id: string, client_name: string, title: string, date: string, monthly_amount: int} */
    public function toArray(): array
    {
        return [
            'agreement_id' => $this->agreement->publicId,
            'client_id' => $this->agreement->clientPublicId,
            'client_name' => $this->agreement->clientName,
            'title' => $this->agreement->title,
            'date' => $this->date->toDateString(),
            'monthly_amount' => $this->monthlyAmount,
        ];
    }
}
