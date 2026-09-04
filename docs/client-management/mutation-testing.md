# Mutation testing changed PHP behavior

The pull-request mutation lane analyzes added and modified PHP lines under
`app/`. The broad root is deliberate: billing services, commands, controllers,
authorization, queries, and new application domains all enter the same gate.
Git diff line filtering keeps the work bounded to the branch rather than
mutating the whole application.

The Composer entry point enables PCOV, uses only the test cases that cover each
mutated line, and enforces an 82% **covered-code MSI**:

```sh
git fetch origin main
composer test:mutation-diff
```

`--with-uncovered` is intentionally absent. Coverage answers whether a line is
tested at all; mutation testing answers whether the tests discriminate changes
to covered behavior. Mixing uncovered mutants into raw MSI made one score
answer two different questions and spent time generating mutants that could not
be killed.

## The Unit-suite tradeoff

The pull-request lane deliberately limits PHPUnit coverage to the `Unit` suite.
This makes Eloquent-free billing value objects and application services cheap
enough to mutate on every relevant diff. The cost is real: behavior covered
only by database-backed feature tests produces no covered mutant in this lane.
Controllers, authorization, tenant queries, and commands remain in the source
scope so that result is reported explicitly as zero mutants instead of being
skipped at workflow detection time. Their feature tests, static rules, and
normal CI remain authoritative; this lane asks the narrower question, “do the
fast focused tests discriminate this changed PHP behavior?”

The per-mutant timeout is 30 seconds because a unit mutation taking longer is a
test-design signal rather than grounds to restore feature-suite runtime. The
Actions job keeps a 45-minute hard failsafe while the broader application scope
collects measurements. Changing the mutator profile at the same time as the
score, source scope, and test suite would make those measurements incomparable,
so `@default` remains for now.

## Calibration evidence

On 2026-09-04 the calibrated command was run against the exact historical #125
head (`1371737`) and base (`ec5e735`): 47 changed files and 6,839 additions, the
large replay diff that drove this issue. Infection 0.35.3 with PCOV and seven
local workers completed in **2m46s**. The Unit coverage pass took eight seconds;
1,398 mutants were generated, 1,397 were killed, and one loop-progress mutant
timed out and was scored as an escape. Mutation coverage was 100% and
covered-code MSI was 99%. The former full-suite run over that change took
44m11s, so the calibrated result is well inside the unchanged 45-minute hosted
failsafe even allowing for the hosted runner's lower worker count.

A separate comment-only `app/` probe completed the Unit coverage and zero-mutant
path in ten seconds and wrote a zero-count summary. The same probe with the full
suite took 2m49s before generating no mutants, which is why the suite boundary
is explicit rather than inferred from file names.

## Honest empty results

The workflow first detects added or modified `app/**/*.php` files. If there are
none, it emits a GitHub notice and a job summary saying mutation testing was not
applicable. If Infection analyzes PHP source but produces zero mutants, the
summary says that explicitly too. Neither case is evidence that a test killed a
behavioral change.

## Advisory policy

The hosted mutation job remains advisory while runtime is calibrated. A red
hosted result on an already-reviewed pull request creates a focused follow-up;
it does not by itself reopen unrelated review. That does not waive the local
rule: before re-requesting review after a code fix, refresh `origin/main` and
require `composer test:mutation-diff` to pass for the candidate head.

For an escaped mutant, either add the missing assertion or put a code-local
`@infection-ignore-all` annotation on the narrowest method with an adjacent
reason explaining why no observable test can distinguish it. Do not add a new
configuration-only exemption and do not lower the threshold to make a branch
green. Very small diffs can make one equivalent mutant dominate a percentage,
so a reviewed, nearby equivalence reason is part of the denominator policy
rather than a reason to ignore a real survivor.

The scheduled/full command remains limited to the established billing money
paths and keeps its historical uncovered-code baseline:

```sh
composer test:mutation
```
