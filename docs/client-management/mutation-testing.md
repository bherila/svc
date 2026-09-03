# Mutation testing the money paths

Infection mutates PHP in `app/Services/Billing/` and `app/Support/Billing/`.
`app/Services/ExternalImport/` was a third scope until the external importer was
retired; the baseline figures below were measured while it was still in scope and
are left as measured rather than re-stated.

The configured minimum MSI is intentionally conservative: it is the rounded-down
result of the first full run over those paths, not an aspirational guess. Raise
it when new assertions kill escaped mutants; do not lower it to make a branch
green.
Because both commands use `--with-uncovered`, uncovered mutants lower MSI
instead of disappearing from the result.

The initial baseline was measured on 2026-08-30 against application/test head
`737f172` with Infection 0.35.3 and PCOV 1.0.12. Seven workers on the shared
eight-core x86_64 host generated 6,450 mutants in 1h 16m 33s: 4,460 were
killed, 590 were uncovered, 376 timed out, and 1,024 were pre-skipped because
their known covering-test runtime exceeded the 30-second mutant budget. MSI was
82.20%, mutation coverage 89.13%, and covered-code MSI 92.22%; the configured
minimum is its conservative integer floor, 82. The pull-request job is advisory
while timeout and skipped-mutant counts are tuned, but timeouts are scored as
escapes and never inflate MSI.

The diff gate uses `infection.diff.json5` with a 120-second mutant budget. A
real covered-line scratch diff generated and killed all 10 mutants in 2m 46s;
with the full run's 30-second budget those same mutants were all pre-skipped.
Keeping the wider diff budget prevents a changed line from passing vacuously
while remaining comfortably below the roughly ten-minute PR target.

The setup sanity check reverted `InvoiceStatus::isSettledValue()` to treat an
unknown stored status as mutable. The diff command rejected that scratch commit
during its initial suite because `InvoiceStatusVocabularyTest` went red. Mutation
runs use PHPUnit's default order so unrelated order dependencies cannot make the
re-review gate intermittent.

Install PCOV before running either command locally. The Composer entry points
enable it, including for Infection's serial coverage subprocess, and give both
processes the repository's 1 GiB memory budget. The pull-request job installs
PCOV on the SQLite lane and is advisory while the threshold is tuned.

```sh
composer test:mutation-diff # only PHP lines changed from origin/main
composer test:mutation      # every configured source file; scheduled use
```

An escaped mutant means a behavior change survived the tests. Triage has two
allowed outcomes:

1. Add the missing assertion so the mutant is killed.
2. Add `@infection-ignore-all` with a nearby reason comment. Prefer a reason
   that names the covering test; prose alone is not evidence that the behavior
   is safe.

Fetch or update `origin/main` before the diff-scoped run. Before re-requesting
review after a fix, that command must be green.
