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
        /**
         * The workspace's public id. Palette-only: it is how a recent item is
         * filed under the workspace it was opened in, and it is deliberately
         * absent from {@see toArray()} so the agent API's row is unchanged.
         */
        public string $workspaceId = '',
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

    /**
     * The row as the palette receives it.
     *
     * The heading and whether the row can become a recent item are decided
     * here, so the browser carries neither a list of headings nor a list of
     * which kinds are reopenable - a kind added on the server arrives complete.
     *
     * @return array{kind: string, id: string, title: string, subtitle: string|null, href: string, workspace: string, workspace_id: string, heading: string, recent: bool}
     */
    public function toPaletteArray(): array
    {
        return [
            ...$this->toArray(),
            'workspace_id' => $this->workspaceId,
            'heading' => $this->kind->heading(),
            'recent' => in_array($this->kind, SearchResultKind::recentable(), true),
        ];
    }
}
