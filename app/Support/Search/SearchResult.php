<?php

namespace App\Support\Search;

/**
 * One row of the command palette, already resolved to somewhere it can go.
 *
 * The href is decided here rather than in the browser because the destination
 * depends on authorization: a task has no screen of its own, so it resolves to
 * its client's Tasks tab, and an invoice resolves to the client-scoped invoice
 * screen a workspace member is allowed to open. Building those in the bundle
 * would put a second copy of the routing rules somewhere nobody checks them.
 */
final readonly class SearchResult
{
    public function __construct(
        public SearchResultKind $kind,
        /** The row's own public id, stable enough to key a list on. */
        public string $id,
        public string $title,
        /**
         * What distinguishes this row from a same-named one elsewhere - the
         * client a project belongs to, the project a task belongs to. Null
         * when the title already says everything.
         */
        public ?string $subtitle,
        public string $href,
        /**
         * Which workspace this row lives in.
         *
         * Shown only when the viewer belongs to more than one, because with a
         * single workspace it is noise on every row.
         */
        public string $workspaceName,
    ) {}

    /** @return array{kind: string, id: string, title: string, subtitle: string|null, href: string, workspace: string} */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'id' => $this->id,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'href' => $this->href,
            'workspace' => $this->workspaceName,
        ];
    }
}
