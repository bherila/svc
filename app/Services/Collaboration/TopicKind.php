<?php

namespace App\Services\Collaboration;

enum TopicKind: string
{
    case Note = 'note';
    case Discussion = 'discussion';

    public function label(): string
    {
        return match ($this) {
            self::Note => 'Note',
            self::Discussion => 'Discussion',
        };
    }
}
