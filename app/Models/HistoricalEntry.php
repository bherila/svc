<?php

namespace App\Models;

use App\Contracts\WorkspaceOwned;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\HasPublicId;
use App\Support\History\HistoryKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property HistoryKind $kind
 * @property array{fields: list<array{label: string, value: string}>, tables: list<array{label: string, columns: list<string>, rows: list<list<string>>}>} $details
 */
#[Fillable(['workspace_id', 'historical_record_id', 'parent_record_id', 'client_company_id', 'kind', 'title', 'body', 'search_text', 'author', 'occurred_on', 'details', 'document_fingerprint'])]
#[Hidden(['id', 'workspace_id', 'historical_record_id', 'parent_record_id', 'client_company_id', 'document_fingerprint', 'details', 'body', 'search_text'])]
class HistoricalEntry extends Model implements WorkspaceOwned
{
    use BelongsToWorkspace, HasPublicId;

    protected function casts(): array
    {
        return ['kind' => HistoryKind::class, 'details' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Historical entries are read-only.');
        });
    }

    protected function workspaceOwnershipIsImmutable(): bool
    {
        return true;
    }
}
