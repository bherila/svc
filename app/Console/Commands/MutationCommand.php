<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\GuardsWrites;

/** --apply is the only write gate; operations still own the rules. */
abstract class MutationCommand extends SvcCommand
{
    use GuardsWrites;
}
