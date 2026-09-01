<?php

namespace App\Models;

use App\Contracts\WorkspaceOwned;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['workspace_id', 'next_number'])]
final class WorkspaceInvoiceCounter extends Model implements WorkspaceOwned
{
    use BelongsToWorkspace;

    protected $primaryKey = 'workspace_id';

    public $incrementing = false;

    protected function casts(): array
    {
        return ['next_number' => 'integer'];
    }
}
