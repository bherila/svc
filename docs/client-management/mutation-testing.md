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

Laravel-backed Unit tests should declare the classes whose behavior they assert
with PHPUnit's `CoversClass` attributes, including deliberately tested domain
collaborators. Otherwise application startup can attribute an unrelated API
catalog to hundreds of tests. Infection adds their recorded execution times and
can skip a mutant before running it when that estimate exceeds the timeout.
Inspect the coverage XML and JUnit timings before treating skips as hung tests;
keep direct catalog contracts covered by fast registry tests. Do not remove
assertions or use `CoversNothing` to conceal application behavior.

Every mutant runs in a fresh PHP process, so whatever a selected test pays
before its first assertion is paid again per mutant. A test that extends
`Tests\TestCase` boots the application and generates an OAuth signing key in
each new process (about 1.5 seconds locally, before PCOV); a plain PHPUnit test
of the same pure decision costs about 0.15 seconds. Write pure decisions -
enums, dates, rules over value objects - as plain PHPUnit tests, as
`RecurringItemBillerTest`, `CancellationDatesTest` and `CancellationRulesTest`
do, and keep Laravel-backed Unit tests for behavior that needs the container.

## Diagnostics

`scripts/run-mutation-diff.sh` runs the lane in two steps, so its evidence
survives both a finished and a cancelled run:

1. **Baseline.** The Unit suite runs once with PCOV and writes its coverage map
   and JUnit timings to `storage/framework/cache/infection-diff/diagnostics/baseline/`.
   It must pass before any mutant runs: under `--skip-initial-tests`, an
   ordinary failing test would otherwise read as a killed mutant.
2. **Mutants.** Infection reads that baseline (`--coverage`,
   `--skip-initial-tests`) and streams one TeamCity record per finished mutant
   to `diagnostics/mutants.teamcity.log`: mutator, id, location, outcome, diff
   and process runtime.

Infection appends `infection/` to its configured tmpDir and deletes that
directory when a run finishes (unless `--debug`), which is why nothing the lane
needs afterwards is kept there. `diagnostics/run.txt` records the base, head,
changed-file count, CPU count and the duration of each step.

`scripts/mutation-timings.php` summarizes the per-mutant log: outcome counts, the
slowest mutants with their source line, and the mutants still running when the
log ends, which is what a run cut off by the job deadline needs. The hosted job
writes that report to its summary and uploads `summary.json`, `mutations.log`
and `diagnostics/` as the `mutation-diagnostics` artifact for seven days.
Infection's per-mutant duration is approximate (infection/infection#2900): use it
to rank mutants, not to budget them.

### What the lane does not mutate

`*Operations::documentation()` (the agent API's descriptions, error maps and
the match arms that choose them) is excluded in `infection.diff.json5`. Route
registration builds the whole operation catalog at every application boot, so
every Laravel-backed Unit test was recorded as covering that text, and each
mutant of it re-ran almost the whole Unit suite: the dominant cost of #673's
run, about 57 seconds per mutant. The text itself is pinned byte for byte by
`OpenApiDocumentGenerationTest`, which fails when the generated document
drifts from `public/openapi/svc-agent-v1.json`. Scopes, methods and routes come
from the catalog's own declarations and stay mutated. Remove the exclusion once
the catalog no longer builds its documentation at boot.

### Measuring a merged change

A follow-up that changes only tests or tooling has no changed application lines
against `main`, so the pull-request lane correctly reports it as not applicable.
To measure the application change it follows up, dispatch the `tests` workflow
on the follow-up branch with `mutation_base` set to the original pull request's
base commit. The lane then mutates every application line changed between that
base and the dispatched commit; if later application changes landed in between,
they are in scope too and should be named in the result.

The per-mutant timeout is 120 seconds: about three times the idle cost of
booting the Unit suite for a service-provider mutant, so a loaded hosted runner
running one child per core does not read as a timeout. A unit mutation taking
longer is a test-design signal rather than grounds to restore feature-suite
runtime. The
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

The local runner and hosted workflow use the same `scripts/mutation-scope.sh`
detector, comparing the merge base with the candidate. With rename detection
disabled, it counts added/changed surviving lines in `app/**/*.php` rather than
just modified files. Tool-only diffs and pure method/file deletions are explicitly
**not applicable**: there are no surviving changed application lines for Infection
to mutate. No summary or mutation score is fabricated. Deletion behavior is
verified by regression tests, full suites and static checks instead of a
changed-line mutation score.

Additions, replacements, mixed additions/deletions and renamed/imported source
still run the existing mutation and measurement gates. Binary application diffs
are conservatively applicable because their surviving lines cannot be classified.
Invalid Git comparisons, failed commands and malformed diff output fail closed.
Synthetic scope tests exercise the shared detector through both the local runner
and the hosted detection step, including filenames with spaces, tabs and newlines.

If applicable source produces zero mutants, Infection must still write its
zero-count summary and the job reports it explicitly. A missing summary remains a
failure for every applicable diff. Neither N/A nor a zero-mutant result is evidence
that a test killed a behavioral change.

## Advisory policy

Infection 0.35.9 refreshes a child's status before checking its deadline, so a
child that exits while the runner generates the next mutations is no longer
reported as timed out. The former Composer post-autoload compatibility patch is
retired. `MutationProcessPollingTest` now runs against the upstream
`ParallelProcessRunner` and fails if a future release regresses: an exited child
must be reaped without a timeout, and a child still running beyond its deadline
must be marked as timed out.

A **measurement gate** (`scripts/mutation-gate.php`, run by both the Composer
script and the hosted job) fails when more than half of the generated mutants
timed out or were skipped, or when Infection wrote no summary, and prints the
killed/escaped/timed-out/skipped counts to the job summary. Such a run
classified nothing, so it cannot be allowed to look green (#443).
`MutationGateScriptTest` runs it against fixtures. This gate is not advisory:
the hosted job fails on it.

The hosted mutation *score* remains advisory while runtime is calibrated. A red
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
