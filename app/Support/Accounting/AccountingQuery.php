<?php

namespace App\Support\Accounting;

use App\Support\AgentApi\ChangeWindow;
use Illuminate\Support\Facades\Validator;

final readonly class AccountingQuery
{
    private function __construct(public int $limit, public ?string $cursor, public ?string $updatedSince, public ?string $from, public ?string $to) {}

    /** @param array<string, mixed> $input */
    public static function from(array $input): self
    {
        $data = Validator::make($input, [
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor' => ['sometimes', 'string', 'max:2048'],
            'updated_since' => ['sometimes', 'string', 'max:2048'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
        ])->validate();
        // Cash payment dates can be corrected. Never silently lose a row that moved out of a filter.
        if (isset($data['updated_since'])) {
            ChangeWindow::refuseMutableFilters(['from' => isset($data['from']), 'to' => isset($data['to'])], []);
        }

        return new self((int) ($data['limit'] ?? 50), $data['cursor'] ?? null, $data['updated_since'] ?? null, $data['from'] ?? null, $data['to'] ?? null);
    }
}
