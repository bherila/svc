<?php

namespace App\Database\Schema\Grammars;

use Illuminate\Database\Schema\Grammars\MariaDbGrammar;
use Illuminate\Support\Fluent;

/** Preserve the existing UUID/FK storage contract across MariaDB server upgrades. */
final class CharacterUuidMariaDbGrammar extends MariaDbGrammar
{
    /** @param Fluent<string, mixed> $column */
    protected function typeUuid(Fluent $column): string
    {
        return 'char(36)';
    }
}
