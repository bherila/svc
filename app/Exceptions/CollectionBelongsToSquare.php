<?php

namespace App\Exceptions;

use DomainException;

/**
 * SVC refused to collect an invoice Square collects (#438).
 *
 * A `DomainException`, so every surface that already reports a billing refusal
 * - the website as a validation message, the API as a 422 carrying this text,
 * MCP as a tool error - reports this one the same way.
 */
final class CollectionBelongsToSquare extends DomainException {}
