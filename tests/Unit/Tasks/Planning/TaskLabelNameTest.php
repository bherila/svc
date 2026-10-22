<?php

namespace Tests\Unit\Tasks\Planning;

use App\Services\Tasks\Planning\TaskLabelName;
use DomainException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(TaskLabelName::class)]
final class TaskLabelNameTest extends TestCase
{
    public function test_names_keep_display_case_but_share_an_ascii_key_across_trimmed_case_variations(): void
    {
        $name = TaskLabelName::fromInput('  Équipe Design  ');
        $this->assertSame('Équipe Design', $name->name);
        $this->assertSame(hash('sha256', 'équipe design'), $name->key);
        $this->assertSame($name->key, TaskLabelName::fromInput('équipe design')->key);
        $this->assertNotSame($name->key, TaskLabelName::fromInput('équipe development')->key);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $name->key);
    }

    public function test_the_name_limit_counts_unicode_characters(): void
    {
        $text = str_repeat('😀', 80);
        $this->assertSame($text, TaskLabelName::fromInput($text)->name);
        $this->assertSame(str_repeat('a', 80), TaskLabelName::fromInput(str_repeat('a', 80))->name);
    }

    public function test_surrounding_unicode_whitespace_normalizes_without_changing_valid_unicode_text(): void
    {
        $label = TaskLabelName::fromInput("\u{00a0}\u{2003}Équipe 😀\u{3000}\u{2028}");
        $this->assertSame('Équipe 😀', $label->name);
        $this->assertSame(TaskLabelName::fromInput('équipe 😀')->key, $label->key);
    }

    public static function invalidNames(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['   '];
        yield 'Unicode blank' => ["\u{00a0}\u{2003}\u{3000}"];
        yield 'null' => [null];
        yield 'array' => [[]];
        yield 'number' => [1];
        yield 'boolean' => [false];
        yield 'long ASCII' => [str_repeat('a', 81)];
        yield 'long Unicode' => [str_repeat('😀', 81)];
        yield 'invalid UTF8' => ["\xff"];
        yield 'multiline' => ["first\nsecond"];
        yield 'Unicode next line' => ["first\u{0085}second"];
        yield 'Unicode line separator' => ["first\u{2028}second"];
        yield 'Unicode paragraph separator' => ["first\u{2029}second"];
        yield 'control character' => ["first\x00second"];
    }

    #[DataProvider('invalidNames')]
    public function test_invalid_names_are_rejected_before_any_persistence(mixed $input): void
    {
        $this->expectException(DomainException::class);
        TaskLabelName::fromInput($input);
    }
}
