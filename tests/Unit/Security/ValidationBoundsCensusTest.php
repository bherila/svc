<?php

namespace Tests\Unit\Security;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Validation bounds census: unbounded input is the most common finding of the
 * cross-repository security sweep, so every validation rule set under app/ is read
 * statically and held to two rules.
 *
 * 1. **An `array` (or `list`) rule must have a `max:`, `size:` or `between:` on the
 *    same key.** Without one a client can send a million elements and the app will
 *    validate, loop over and often query for each of them. `array:a,b` (allowed
 *    keys) is bounded by its key list and passes.
 * 2. **A date must have an absolute or relative bound, and a year or month a
 *    range.** A `date`/`date_format` rule needs `after`, `after_or_equal`,
 *    `before`, `before_or_equal` or `date_equals`; a key whose last segment is
 *    `year` or `month` (`year`, `tax_year`, `items.*.month`) needs `between:`,
 *    `in:`, or both `min:` and `max:`. Open-ended windows let one request ask for
 *    every year since 0001. This check is syntactic: it does not prove the *span*
 *    of a from/to window is capped (`to => after_or_equal:from` only orders the
 *    two). Cap the span in code where it drives a loop or a query.
 *
 * Rule sets are found by parsing, not by booting: the `rules()` method of any class
 * (Form Requests, Livewire components, MCP tools), and the rules argument of
 * `->validate()`, `->validateWithBag()`, `Validator::make()` and `validator()`.
 * String rules (`'required|array|max:50'`), arrays of strings, and concatenations
 * starting with a literal (`'max:'.self::LIMIT`) are understood; rule objects and
 * `Rule::...` calls are opaque and neither satisfy nor violate a check. Keys built
 * at runtime are invisible to it.
 *
 * Ported from x-template. Findings are settled three ways: fix the rule; add a
 * reasoned entry to an allowlist when the value is harmless or bounded elsewhere;
 * or, for the open-ended dates found when the census arrived, the frozen baseline
 * in DATE_BASELINE. The baseline is a ratchet: a finding missing from it fails, and
 * so does an entry no finding matches any more, so it can only shrink.
 */
final class ValidationBoundsCensusTest extends TestCase
{
    /** Directories, relative to the repository root, whose PHP files are scanned. */
    private const SCAN_ROOTS = ['app'];

    /**
     * `array` rules that may stay unbounded, keyed "path/to/File.php::key". Give a
     * reason; an entry that matches nothing fails the census.
     *
     * @var array<string, string>
     */
    private const ARRAY_ALLOWLIST = [];

    /**
     * Date, year and month rules that may stay unbounded, keyed like ARRAY_ALLOWLIST.
     *
     * @var array<string, string>
     */
    private const DATE_ALLOWLIST = [
        'app/Support/AgentApi/InvoiceListFilters.php::issue_date_from' => self::QUERY_FILTER,
        'app/Support/AgentApi/InvoiceListFilters.php::due_date_from' => self::QUERY_FILTER,
        'app/Support/AgentApi/InvoiceListFilters.php::service_period_overlaps.from' => self::QUERY_FILTER,
        'app/Support/Accounting/AccountingQuery.php::from' => self::QUERY_FILTER,
        'app/Support/Finance/FinancePayoutQuery.php::arrival_from' => self::QUERY_FILTER,
        'app/Support/Finance/FinancePaymentQuery.php::received_from' => self::QUERY_FILTER,
        'app/Support/AgentApi/ChangeWindow.php::updated_since' => 'A change-feed lower bound: a `>=` on updated_at '
            .'inside a watermark that is itself capped at now (watermark is before_or_equal:latest), read a page at a '
            .'time. Omitting it already means "from the beginning", so an early value asks for nothing more.',
        'app/Services/AgentApi/AgentInvoiceOperations.php::period_start' => 'invoices.generate_period and '
            .'invoices.preview_period: CadencePeriodGuard::lock() refuses any period_start that is not the start of a '
            .'cadence period between the agreement\'s starts_on and today, before anything is generated.',
        'app/Http/Controllers/Packages/PortalCancellationController.php::end_date' => 'AgreementCancellations::'
            .'cancelByContact() accepts only an end date from the set the agreement\'s terms offer (assertOffered), '
            .'computed on the server.',
    ];

    private const QUERY_FILTER = 'A list filter\'s lower bound: one indexed `>=` in a paginated query (at most 100 '
        .'rows a page), and its `_to` partner is ordered after it. No loop or span is driven by it.';

    /**
     * Open-ended dates found when the census was ported, frozen as "file::key" entries
     * (a key repeated in a file appears once per rule set). Each is a stored business
     * date - an agreement's start or end, a payment's received_on, an expense's spent_on -
     * whose bound is a domain decision not yet taken. Bound one and delete its entry.
     */
    private const DATE_BASELINE = 'tests/Fixtures/Security/validation-date-baseline.json';

    private const DATE_BOUNDS = ['after', 'after_or_equal', 'before', 'before_or_equal', 'date_equals'];

    /** @var array{array: list<string>, date: list<string>}|null */
    private static ?array $repositoryScan = null;

    public function test_array_rules_declare_a_maximum_size(): void
    {
        $findings = $this->filterAllowlisted($this->scanRepository()['array'], self::ARRAY_ALLOWLIST);

        self::assertSame([], $findings, $this->fixMessage(
            'These validation keys accept an array of any length:',
            $findings,
            "Fix: add a maximum on the same key, sized to the largest legitimate request:\n"
            ."    'items' => ['required', 'array', 'max:100'],\n"
            ."If the keys are fixed, list them instead: 'array:name,email'. If the size is bounded elsewhere\n"
            .'and cannot be expressed as a rule, add "<file>::<key>" to ARRAY_ALLOWLIST in '.self::class
            .' with the reason.',
        ));
    }

    public function test_date_year_and_month_rules_are_bounded(): void
    {
        $findings = $this->withoutBaselined($this->filterAllowlisted($this->scanRepository()['date'], self::DATE_ALLOWLIST));

        self::assertSame([], $findings, $this->fixMessage(
            'These validation keys accept an open-ended date, year or month:',
            $findings,
            "Fix: bound it to what the app can actually serve:\n"
            ."    'from' => ['required', 'date', 'after_or_equal:2000-01-01', 'before_or_equal:today'],\n"
            ."    'year' => ['required', 'integer', 'between:2000,2100'],\n"
            ."    'month' => ['required', 'integer', 'between:1,12'],\n"
            ."and cap the span of a from/to window in code before it drives a loop or query. If the value is\n"
            .'genuinely unbounded (a birth date stored verbatim), add "<file>::<key>" to DATE_ALLOWLIST in '
            .self::class.' with the reason. Never add a new finding to '.self::DATE_BASELINE.'.',
        ));
    }

    public function test_allowlist_entries_match_a_finding(): void
    {
        $scan = $this->scanRepository();
        $stale = [
            ...$this->staleEntries(self::ARRAY_ALLOWLIST, $scan['array']),
            ...$this->staleEntries(self::DATE_ALLOWLIST, $scan['date']),
        ];

        self::assertSame([], $stale, 'Allowlist entries in '.self::class.' match no unbounded rule any more. '
            .'Remove them: '.implode(', ', $stale));
    }

    public function test_date_baseline_entries_match_a_finding(): void
    {
        $remaining = $this->keysOf($this->filterAllowlisted($this->scanRepository()['date'], self::DATE_ALLOWLIST));
        $stale = [];
        foreach ($this->dateBaseline() as $entry) {
            $index = array_search($entry, $remaining, true);
            if ($index === false) {
                $stale[] = $entry;
            } else {
                unset($remaining[$index]);
            }
        }

        self::assertSame([], $stale, 'These entries in '.self::DATE_BASELINE.' match no open-ended date any more, '
            .'so the rule was bounded, renamed or removed. Delete them from the baseline: '.implode(', ', $stale));
    }

    public function test_date_baseline_is_sorted_and_entries_are_not_also_allowlisted(): void
    {
        $baseline = $this->dateBaseline();
        $sorted = $baseline;
        sort($sorted);

        self::assertSame($sorted, $baseline, self::DATE_BASELINE.' must stay sorted so a change to it reads as a diff.');
        self::assertSame([], array_values(array_intersect($baseline, array_keys(self::DATE_ALLOWLIST))), 'An entry is in '
            .'both the date baseline and DATE_ALLOWLIST; keep it in one.');
    }

    /**
     * Prove the census on fixture source, so a parser change cannot make it blind.
     */
    public function test_census_detects_unbounded_rules_in_fixture_source(): void
    {
        $source = <<<'PHP'
        <?php
        class StoreThingRequest extends FormRequest
        {
            public function rules(): array
            {
                $rules = [
                    'tags' => 'required|array',
                    'ids' => ['required', 'list'],
                    'ok_items' => ['required', 'array', 'max:50'],
                    'ok_items.*' => ['integer'],
                    'ok_concat' => ['array', 'max:'.self::LIMIT],
                    'ok_between' => 'array|between:1,5',
                    'ok_keys' => 'array:name,email',
                    'opaque' => [new SomeRule, Rule::in(['a', 'b'])],
                    'items.*.labels' => ['array'],
                    'from' => ['required', 'date'],
                    'to' => ['required', 'date', 'after_or_equal:from'],
                    'issued_on' => 'date_format:Y-m-d',
                    'tax_year' => ['required', 'integer', 'min:2000'],
                    'ok_year' => ['required', 'integer', 'between:2000,2100'],
                    'items.*.month' => 'integer',
                    'ok_month' => 'integer|min:1|max:12',
                ];
                $rules['late'] = 'array';

                return $rules;
            }
        }

        class ThingController
        {
            public function store(Request $request)
            {
                $request->validate(['payload' => 'array', 'name' => 'string']);
                $this->validate($request, ['rows' => ['array', 'size:3']]);
                Validator::make(['ignored' => 'array'], ['bulk' => 'array']);
                validator($request->all(), ['period_year' => 'integer']);
            }
        }
        PHP;

        $findings = $this->scanSource($source, 'Fixture.php');

        self::assertSame([
            'Fixture.php::tags',
            'Fixture.php::ids',
            'Fixture.php::items.*.labels',
            'Fixture.php::late',
            'Fixture.php::payload',
            'Fixture.php::bulk',
        ], $this->keysOf($findings['array']));
        self::assertSame([
            'Fixture.php::from',
            'Fixture.php::issued_on',
            'Fixture.php::tax_year',
            'Fixture.php::items.*.month',
            'Fixture.php::period_year',
        ], $this->keysOf($findings['date']));
    }

    /**
     * @return array{array: list<string>, date: list<string>}
     */
    private function scanRepository(): array
    {
        if (self::$repositoryScan !== null) {
            return self::$repositoryScan;
        }

        $root = dirname(__DIR__, 3);
        $findings = ['array' => [], 'date' => []];

        foreach (self::SCAN_ROOTS as $directory) {
            if (! is_dir($root.'/'.$directory)) {
                continue;
            }

            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $source = file_get_contents($file->getPathname());
                if ($source === false || preg_match('/function\s+rules\b|validat/i', $source) !== 1) {
                    continue;
                }

                $relative = substr($file->getPathname(), strlen($root) + 1);
                $scan = $this->scanSource($source, $relative);
                $findings['array'] = [...$findings['array'], ...$scan['array']];
                $findings['date'] = [...$findings['date'], ...$scan['date']];
            }
        }

        return self::$repositoryScan = $findings;
    }

    /**
     * @return array{array: list<string>, date: list<string>}
     */
    private function scanSource(string $source, string $label): array
    {
        $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse($source) ?? [];
        $finder = new NodeFinder;
        $findings = ['array' => [], 'date' => []];

        foreach ($this->ruleSetsIn($ast, $finder) as $ruleSet) {
            foreach ($ruleSet as $key => [$line, $rules]) {
                $location = $label.'::'.$key.'  (line '.$line.')';
                if ($this->isUnboundedArray($rules)) {
                    $findings['array'][] = $location;
                }
                if ($this->isUnboundedDate((string) $key, $rules)) {
                    $findings['date'][] = $location;
                }
            }
        }

        return $findings;
    }

    /**
     * Every rule set in the file, as key => [first line, rule names with parameters].
     *
     * @param  array<Node>  $ast
     * @return list<array<string, array{int, list<string>}>>
     */
    private function ruleSetsIn(array $ast, NodeFinder $finder): array
    {
        $ruleSets = [];

        /** @var list<ClassMethod> $methods */
        $methods = $finder->find($ast, fn (Node $node): bool => $node instanceof ClassMethod
            && $node->name->toLowerString() === 'rules');
        foreach ($methods as $method) {
            $ruleSet = [];
            foreach ($finder->findInstanceOf($method->stmts ?? [], Array_::class) as $array) {
                $this->collectKeyedRules($array, $ruleSet);
            }
            foreach ($finder->findInstanceOf($method->stmts ?? [], Assign::class) as $assign) {
                if ($assign->var instanceof ArrayDimFetch && $assign->var->dim instanceof String_) {
                    $this->addRules($ruleSet, $assign->var->dim->value, $assign->getStartLine(), $assign->expr);
                }
            }
            $ruleSets[] = $ruleSet;
        }

        foreach ($finder->find($ast, fn (Node $node): bool => $node instanceof MethodCall
            || $node instanceof StaticCall || $node instanceof FuncCall) as $call) {
            $rulesArgument = $this->rulesArgumentOf($call);
            if ($rulesArgument instanceof Array_) {
                $ruleSet = [];
                $this->collectKeyedRules($rulesArgument, $ruleSet);
                $ruleSets[] = $ruleSet;
            }
        }

        return $ruleSets;
    }

    private function rulesArgumentOf(Node $call): ?Expr
    {
        $arguments = ($call instanceof MethodCall || $call instanceof StaticCall || $call instanceof FuncCall)
            ? array_values(array_filter($call->args, fn ($argument): bool => $argument instanceof Arg))
            : [];
        $value = fn (int $index): ?Expr => isset($arguments[$index]) ? $arguments[$index]->value : null;

        if ($call instanceof MethodCall && $call->name instanceof Identifier) {
            return match ($call->name->toLowerString()) {
                'validate' => $value(0) instanceof Array_ ? $value(0) : $value(1),
                'validatewithbag' => $value(1),
                default => null,
            };
        }

        if ($call instanceof StaticCall && $call->class instanceof Name && $call->name instanceof Identifier
            && $call->class->getLast() === 'Validator' && $call->name->toLowerString() === 'make') {
            return $value(1);
        }

        if ($call instanceof FuncCall && $call->name instanceof Name && $call->name->toLowerString() === 'validator') {
            return $value(1);
        }

        return null;
    }

    /**
     * @param  array<string, array{int, list<string>}>  $ruleSet
     */
    private function collectKeyedRules(Array_ $array, array &$ruleSet): void
    {
        foreach ($array->items as $item) {
            if ($item->key instanceof String_) {
                $this->addRules($ruleSet, $item->key->value, $item->getStartLine(), $item->value);
            }
        }
    }

    /**
     * @param  array<string, array{int, list<string>}>  $ruleSet
     */
    private function addRules(array &$ruleSet, string $key, int $line, Expr $value): void
    {
        $rules = [];
        foreach ($value instanceof Array_ ? array_map(fn ($item) => $item->value, $value->items) : [$value] as $rule) {
            $literal = $this->leadingLiteral($rule);
            if ($literal !== null) {
                $rules = [...$rules, ...array_map('trim', explode('|', strtolower($literal)))];
            }
        }

        $ruleSet[$key] = [$ruleSet[$key][0] ?? $line, [...($ruleSet[$key][1] ?? []), ...$rules]];
    }

    /**
     * The literal text a rule starts with: `'max:'.self::LIMIT` gives `max:`.
     */
    private function leadingLiteral(Expr $rule): ?string
    {
        if ($rule instanceof String_) {
            return $rule->value;
        }

        return $rule instanceof Concat ? $this->leadingLiteral($rule->left) : null;
    }

    /**
     * @param  list<string>  $rules
     */
    private function isUnboundedArray(array $rules): bool
    {
        $names = $this->ruleNames($rules);
        $isArray = in_array('list', $names, true) || in_array('array', $rules, true);

        return $isArray && array_intersect($names, ['max', 'size', 'between']) === [];
    }

    /**
     * @param  list<string>  $rules
     */
    private function isUnboundedDate(string $key, array $rules): bool
    {
        $names = $this->ruleNames($rules);

        if (array_intersect($names, ['date', 'date_format']) !== [] && array_intersect($names, self::DATE_BOUNDS) === []) {
            return true;
        }

        $segments = explode('.', $key);
        if (preg_match('/(^|_)(year|month)$/i', end($segments)) !== 1) {
            return false;
        }

        $hasRange = array_intersect($names, ['between', 'in', 'size']) !== []
            || (in_array('min', $names, true) && in_array('max', $names, true));

        return ! $hasRange && array_intersect($names, ['date', 'date_format']) === [];
    }

    /**
     * `max:50` gives `max`; `array:name,email` stays distinct from bare `array`.
     *
     * @param  list<string>  $rules
     * @return list<string>
     */
    private function ruleNames(array $rules): array
    {
        return array_map(fn (string $rule): string => explode(':', $rule, 2)[0], $rules);
    }

    /**
     * @param  list<string>  $findings
     * @param  array<string, string>  $allowlist
     * @return list<string>
     */
    private function filterAllowlisted(array $findings, array $allowlist): array
    {
        return array_values(array_filter(
            $findings,
            fn (string $finding): bool => ! array_key_exists(explode('  ', $finding)[0], $allowlist),
        ));
    }

    /**
     * Findings left once the baseline's entries are taken out, one finding per entry.
     *
     * @param  list<string>  $findings
     * @return list<string>
     */
    private function withoutBaselined(array $findings): array
    {
        $baseline = $this->dateBaseline();
        $left = [];
        foreach ($findings as $finding) {
            $index = array_search($this->keysOf([$finding])[0], $baseline, true);
            if ($index === false) {
                $left[] = $finding;
            } else {
                unset($baseline[$index]);
            }
        }

        return $left;
    }

    /**
     * @return list<string>
     */
    private function dateBaseline(): array
    {
        $contents = file_get_contents(dirname(__DIR__, 3).'/'.self::DATE_BASELINE);
        self::assertIsString($contents, self::DATE_BASELINE.' is missing.');
        $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $entries = $decoded['entries'] ?? null;
        self::assertIsArray($entries, self::DATE_BASELINE.' must hold an "entries" list.');
        self::assertTrue(array_is_list($entries));

        $baseline = [];
        foreach ($entries as $entry) {
            self::assertIsString($entry);
            $baseline[] = $entry;
        }

        return $baseline;
    }

    /**
     * @param  array<string, string>  $allowlist
     * @param  list<string>  $findings
     * @return list<string>
     */
    private function staleEntries(array $allowlist, array $findings): array
    {
        return array_values(array_diff(array_keys($allowlist), $this->keysOf($findings)));
    }

    /**
     * @param  list<string>  $findings
     * @return list<string>
     */
    private function keysOf(array $findings): array
    {
        return array_map(fn (string $finding): string => explode('  ', $finding)[0], $findings);
    }

    /**
     * @param  list<string>  $findings
     */
    private function fixMessage(string $problem, array $findings, string $fix): string
    {
        return $problem."\n  - ".implode("\n  - ", $findings)."\n\n".$fix."\n";
    }
}
