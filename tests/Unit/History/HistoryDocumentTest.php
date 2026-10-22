<?php

namespace Tests\Unit\History;

use App\Support\History\HistoryDocument;
use App\Support\History\HistoryKind;
use PHPUnit\Framework\TestCase;

class HistoryDocumentTest extends TestCase
{
    public function test_presentation_keeps_original_decimal_strings_and_indexes_all_readable_content(): void
    {
        $fields = [['label' => 'Original discount', 'value' => '0.00400']];
        $tables = [['label' => 'Items', 'columns' => ['Description', 'Amount'], 'rows' => [['Historical example', '2.99'], ['Second example', '0.00']]]];
        $document = new HistoryDocument(HistoryKind::Invoice, 'SYN-OLD', 'Original note', 'Casey Example', '2020-03-04', $fields, $tables);
        $this->assertSame(['kind' => 'invoice', 'title' => 'SYN-OLD', 'body' => 'Original note', 'author' => 'Casey Example', 'occurred_on' => '2020-03-04',
            'search_text' => "Original note\nCasey Example\nOriginal discount 0.00400\nItems Description Amount\nHistorical example 2.99\nSecond example 0.00",
            'details' => ['fields' => $fields, 'tables' => $tables]], $document->attributes());
    }

    public function test_an_undated_record_with_no_actor_or_details_remains_explicitly_empty(): void
    {
        $this->assertSame(['kind' => 'note', 'title' => 'Original', 'body' => '', 'author' => null, 'occurred_on' => null,
            'search_text' => "\n", 'details' => ['fields' => [], 'tables' => []]], (new HistoryDocument(HistoryKind::Note, 'Original', ''))->attributes());
    }

    public function test_each_stored_kind_has_a_human_label(): void
    {
        $this->assertSame(['Company', 'Person', 'Project', 'Task', 'Task list', 'Subtask', 'Comment', 'Note', 'Discussion', 'Invoice', 'Invoice item', 'Payment', 'Time', 'Expense', 'File', 'Activity', 'Email delivery', 'Change', 'Recurring invoice', 'Reference'], array_map(static fn (HistoryKind $kind): string => $kind->label(), HistoryKind::cases()));
    }
}
