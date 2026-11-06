<?php

namespace Tests\Support\Billing;

use App\Models\ClientInvoice;
use App\Services\Billing\InvoiceDocumentService;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * {@see InvoiceDocumentFacts} read from today's `invoices.show` Blade HTML.
 *
 * It reads the same HTML the PDF is rendered from, by structure (the class
 * names the template already carries), so a stylesheet change moves nothing
 * here and a figure change moves exactly the fact it belongs to.
 *
 * @phpstan-import-type Facts from InvoiceDocumentFacts
 */
final class BladeInvoiceDocumentFacts implements InvoiceDocumentFacts
{
    public function __construct(private readonly InvoiceDocumentService $documents) {}

    public function extract(ClientInvoice $invoice, string $audience): array
    {
        $html = $this->documents->html($invoice, $audience)->render();

        $document = new DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        $xpath = new DOMXPath($document);

        $masthead = $this->first($xpath, '//table[contains(@class,"masthead")]');
        $muted = $this->texts($xpath, '//body/p[contains(@class,"muted")]');
        $issueLine = $this->matching($muted, '/^Issue date:/');
        $periodLine = $this->matching($muted, '/^Service period:/');
        $reissue = $this->matching($muted, '/^Reissued/');
        preg_match('/^Issue date: (.*?) Due: (.*)$/', $issueLine, $dates);

        return [
            'invoice_number' => $this->text($xpath, './/div[contains(@class,"muted")]', $masthead),
            'status' => $this->text($xpath, './/strong', $masthead),
            'currency' => $this->currency($masthead),
            'bill_to' => (string) preg_replace('/^Bill to:\s*/', '', $this->text($xpath, '//body/p[contains(@class,"wrap")]')),
            'issue_date' => $dates[1] ?? '',
            'due_date' => $dates[2] ?? '',
            'service_period' => (string) preg_replace('/^Service period:\s*/', '', $periodLine),
            'audience_banner' => $this->text($xpath, '//div[contains(@class,"running-header")]//td[contains(@class,"audience")]'),
            'reissue_notice' => $reissue === '' ? null : $reissue,
            'lines' => $this->lines($xpath),
            'totals' => $this->totals($xpath),
            'hours_statement' => $this->hoursStatement($xpath),
            'still_deferred' => $this->stillDeferred($xpath),
            'appendix' => $this->appendix($xpath),
        ];
    }

    /** The currency sits after the status, in the right-hand cell of the masthead. */
    private function currency(?DOMNode $masthead): string
    {
        if (! $masthead instanceof DOMElement) {
            return '';
        }
        $cell = $masthead->getElementsByTagName('td')->item(1);
        $text = $cell instanceof DOMNode ? $this->normalise($cell->textContent) : '';
        $status = $masthead->getElementsByTagName('strong')->item(0);

        return trim(substr($text, strlen($status instanceof DOMNode ? $this->normalise($status->textContent) : '')));
    }

    /** @return list<array{description: string, type: string, quantity: string, unit: string, tax: string, total: string}> */
    private function lines(DOMXPath $xpath): array
    {
        $lines = [];
        foreach ($xpath->query('//table[thead/tr/th[1][normalize-space()="Description"]]/tbody/tr') ?: [] as $row) {
            $cells = $this->cells($row);
            $lines[] = [
                'description' => $cells[0], 'type' => $cells[1], 'quantity' => $cells[2],
                'unit' => $cells[3], 'tax' => $cells[4], 'total' => $cells[5],
            ];
        }

        return $lines;
    }

    /** @return array<string, string> */
    private function totals(DOMXPath $xpath): array
    {
        $totals = [];
        foreach ($xpath->query('//table[contains(@class,"summary")]//tr') ?: [] as $row) {
            $cells = $this->cells($row);
            $totals[$cells[0]] = $cells[1];
        }

        return $totals;
    }

    /** @return array{intro: string, sections: list<array{title: string, rows: list<array{label: string, value: string, kind: string}>}>}|null */
    private function hoursStatement(DOMXPath $xpath): ?array
    {
        $block = $this->first($xpath, '//div[contains(@class,"statement")]');
        if (! $block instanceof DOMElement) {
            return null;
        }
        $sections = [];
        $title = null;
        foreach ($block->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }
            if ($child->tagName === 'h3') {
                $title = $this->normalise($child->textContent);
            } elseif ($child->tagName === 'table' && $title !== null) {
                $rows = [];
                foreach ($child->getElementsByTagName('tr') as $row) {
                    $cells = $this->cells($row);
                    $rows[] = ['label' => $cells[0], 'value' => $cells[1], 'kind' => $this->normalise($row->getAttribute('class'))];
                }
                $sections[] = ['title' => $title, 'rows' => $rows];
            }
        }

        return ['intro' => $this->text($xpath, './p', $block), 'sections' => $sections];
    }

    /** @return array{summary: string, entries: list<array{date: string, work: string, hours: string}>}|null */
    private function stillDeferred(DOMXPath $xpath): ?array
    {
        $block = $this->first($xpath, '//div[contains(@class,"appendix")][h2[normalize-space()="Still deferred"]]');
        if (! $block instanceof DOMElement) {
            return null;
        }
        $entries = [];
        foreach ($xpath->query('.//tbody/tr', $block) ?: [] as $row) {
            $cells = $this->cells($row);
            $entries[] = ['date' => $cells[0], 'work' => $cells[1], 'hours' => $cells[2]];
        }

        return ['summary' => $this->text($xpath, './p', $block), 'entries' => $entries];
    }

    /** @return array{notice: string, lines: list<array{description: string, entries: list<array{date: string, project: string, work: string, hours: string}>, subtotal: array{label: string, hours: string}}>}|null */
    private function appendix(DOMXPath $xpath): ?array
    {
        $block = $this->first($xpath, '//div[contains(@class,"appendix")][h2[starts-with(normalize-space(),"Appendix")]]');
        if (! $block instanceof DOMElement) {
            return null;
        }
        $lines = [];
        $description = null;
        foreach ($block->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }
            if ($child->tagName === 'h3') {
                $description = $this->normalise($child->textContent);
            } elseif ($child->tagName === 'table' && $description !== null) {
                $entries = [];
                $subtotal = ['label' => '', 'hours' => ''];
                foreach ($child->getElementsByTagName('tbody')->item(0)?->getElementsByTagName('tr') ?? [] as $row) {
                    $cells = $this->cells($row);
                    if (str_contains($row->getAttribute('class'), 'subtotal')) {
                        $subtotal = ['label' => $cells[0], 'hours' => $cells[1]];
                    } else {
                        $entries[] = ['date' => $cells[0], 'project' => $cells[1], 'work' => $cells[2], 'hours' => $cells[3]];
                    }
                }
                $lines[] = ['description' => $description, 'entries' => $entries, 'subtotal' => $subtotal];
            }
        }

        return ['notice' => $this->text($xpath, './p', $block), 'lines' => $lines];
    }

    /** @return list<string> */
    private function cells(DOMNode $row): array
    {
        $cells = [];
        foreach ($row->childNodes as $cell) {
            if ($cell instanceof DOMElement && in_array($cell->tagName, ['td', 'th'], true)) {
                $cells[] = $this->normalise($cell->textContent);
            }
        }

        return $cells;
    }

    /** @return list<string> */
    private function texts(DOMXPath $xpath, string $query): array
    {
        $texts = [];
        foreach ($xpath->query($query) ?: [] as $node) {
            $texts[] = $this->normalise($node->textContent);
        }

        return $texts;
    }

    /** @param list<string> $texts */
    private function matching(array $texts, string $pattern): string
    {
        foreach ($texts as $text) {
            if (preg_match($pattern, $text) === 1) {
                return $text;
            }
        }

        return '';
    }

    private function first(DOMXPath $xpath, string $query, ?DOMNode $context = null): ?DOMNode
    {
        $found = $xpath->query($query, $context);

        return $found === false ? null : $found->item(0);
    }

    private function text(DOMXPath $xpath, string $query, ?DOMNode $context = null): string
    {
        $node = $this->first($xpath, $query, $context);

        return $node instanceof DOMNode ? $this->normalise($node->textContent) : '';
    }

    private function normalise(string $text): string
    {
        return trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $text));
    }
}
