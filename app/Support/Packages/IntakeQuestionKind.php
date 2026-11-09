<?php

namespace App\Support\Packages;

/** The answer a request-form question takes. */
enum IntakeQuestionKind: string
{
    case ShortText = 'short_text';
    case Paragraph = 'paragraph';

    public function maxLength(): int
    {
        return match ($this) {
            self::ShortText => 300,
            self::Paragraph => 5000,
        };
    }
}
