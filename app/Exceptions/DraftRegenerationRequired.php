<?php

namespace App\Exceptions;

use DomainException;

/**
 * A settlement cannot be attached to a legacy cadence draft until the draft is
 * explicitly regenerated (#609).
 *
 * The draft's stored hours statement reports a deferred backlog, but it was
 * written before drafts captured an itemized deferred-work snapshot, so which
 * entries make up that backlog cannot be established. Attaching would have to
 * guess which waiting hours a settlement removes from the statement. SVC never
 * regenerates silently - regeneration rebuilds the draft's lines and may change
 * its charges - and never invents historical membership, so the caller
 * regenerates the draft, re-reads its versions, previews again and retries.
 *
 * A `DomainException`, so the website reports it as a validation message, the
 * API as a 422 carrying {@see CODE} in its `code` field, and MCP as a tool
 * error naming the same code. Nothing is written before it is thrown.
 */
final class DraftRegenerationRequired extends DomainException implements CodedRefusal
{
    public const string CODE = 'draft_regeneration_required';

    public const string MESSAGE = 'Regenerate this draft first; regeneration may change its charges.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }

    public function refusalCode(): string
    {
        return self::CODE;
    }
}
