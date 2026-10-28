<?php

namespace App\Database;

use App\Database\Schema\Grammars\CharacterUuidMariaDbGrammar;
use Illuminate\Database\MariaDbConnection;

final class CharacterUuidMariaDbConnection extends MariaDbConnection
{
    protected function getDefaultSchemaGrammar(): CharacterUuidMariaDbGrammar
    {
        return new CharacterUuidMariaDbGrammar($this);
    }
}
