<?php

namespace App\Exceptions;

/**
 * A refusal that carries a stable, machine-readable code beside its sentence.
 *
 * The API renders the code as the error body's `code`, and MCP repeats it in
 * the tool error, so an agent can branch on the code while a person reads the
 * message. Codes are snake_case and never change once published.
 */
interface CodedRefusal
{
    public function refusalCode(): string;
}
