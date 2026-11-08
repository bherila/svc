<?php

namespace App\Support\Search;

/**
 * One command the palette offers, already resolved to where it goes.
 *
 * The server only ever builds an action the viewer may carry out, with its
 * finished URL; there is no disabled action and no flag for the browser to
 * interpret. The browser filters this short list by `label` and `keywords` as
 * the viewer types, which is safe precisely because the list holds nothing they
 * could not already do.
 */
final readonly class PaletteAction
{
    /** @param list<string> $keywords Extra words the action answers to ("income" for Revenue). */
    public function __construct(
        public string $id,
        public string $label,
        public string $href,
        public array $keywords = [],
        /** Said beside the label when the action lands on a picker rather than doing the thing. */
        public ?string $hint = null,
    ) {}

    /** @return array{id: string, label: string, href: string, keywords: list<string>, hint: string|null} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'label' => $this->label, 'href' => $this->href, 'keywords' => $this->keywords, 'hint' => $this->hint];
    }
}
