<?php

namespace Tests\Unit\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Workflow credential census: only commits on `main` ancestry may reach deploy
 * credentials.
 *
 * A job is *credentialed* when it can act on something outside the run: it names
 * a GitHub Environment (`environment:`), references a deploy-class secret (a
 * `secrets.*` name containing a SENSITIVE_SECRET_WORDS word, or `secrets: inherit`), or is granted
 * a write-class token permission (contents, packages, deployments or id-token
 * write, or write-all). Every credentialed job must prove it runs a main commit in
 * one of three ways:
 *
 * A. **Main-only triggers.** The workflow runs only on `push` to `main` (no tags,
 *    no other branches) and on `schedule`, which always runs the default branch.
 *    deploy.yml takes this form.
 * B. **A ref gate.** The job's `if:` requires `github.ref == 'refs/heads/main'`
 *    (no `||`, no negation) and every trigger is one of REF_GATED_EVENTS. For those
 *    events `github.ref` is the ref whose commit the run checks out (pull_request,
 *    merge_group and release never carry refs/heads/main, so the gate skips them).
 *    It is NOT valid for workflow_run, pull_request_target, workflow_call,
 *    repository_dispatch or issue_comment, where `github.ref` names the default
 *    branch while the code can come from anywhere. cloud-vendor.yml's publish job
 *    takes this form.
 * C. **An ancestry check.** A step running `git merge-base --is-ancestor` (not
 *    swallowed by `|| true` or `continue-on-error`) comes before every other step
 *    except `actions/checkout`, and no job- or workflow-level `env:` hands a
 *    deploy-class secret to the steps before it. This is the form for a
 *    workflow_dispatch that deploys a chosen SHA, or a workflow_run deploy.
 *
 * Forms A and B also refuse an `actions/checkout` whose `ref:` is anything but
 * `${{ github.sha }}` or main: checking out another commit discards the guarantee
 * the trigger or gate gave. The one exception is `${{ steps.<id>.outputs.sha }}`
 * written by a gate script listed in MAIN_ANCESTRY_GATES: the step with that id
 * must come earlier in the job, run exactly the listed command, and be able to fail
 * the job (no `if:`, no `continue-on-error`). Because the job is already ref-gated
 * or main-only, the gate script runs from a main commit, so a branch cannot swap
 * in a gate of its own. staging-deploy.yml takes this form: it deploys an earlier
 * main commit that scripts/deploy/staging/gate.mjs proved is on main (#728).
 *
 * What this does and does not protect: the census reads the workflow files on the
 * branch under test, and anyone who can push a branch can edit them there. It
 * stops the mistakes — a deploy workflow given workflow_dispatch, a workflow_run
 * deploy that trusts the triggering branch, a release job dispatched from an
 * unmerged branch — not a malicious author. The control against that is a
 * repository setting: restrict each Environment's deployment branches to `main`
 * (see SECURITY.md).
 *
 * Ported from x-template; MAIN_ANCESTRY_GATES is SVC's addition.
 */
final class WorkflowCredentialCensusTest extends TestCase
{
    private const MAIN_BRANCH = 'main';

    /**
     * Underscore-separated words that mark a secret as unlocking deploy,
     * production, staging or cloud credentials: SSH_PRIVATE_KEY, PROD_DB_PASSWORD
     * and STAGING_APP_KEY match, POSTHOG_NONPROD_API_KEY and CODECOV_TOKEN do not.
     */
    private const SENSITIVE_SECRET_WORDS = [
        'SSH', 'DEPLOY', 'PROD', 'PRODUCTION', 'STAGING', 'PRIVATE', 'KNOWN', 'HOST', 'HOSTS', 'SERVER',
        'KUBE', 'KUBECONFIG', 'FTP', 'SFTP', 'CPANEL', 'AWS', 'AZURE', 'GCP', 'ROLE', 'DB', 'DATABASE',
        'RELEASE', 'SIGNING', 'PUBLISH', 'NPM', 'PACKAGIST', 'CLOUDFLARE',
    ];

    /** Checkout refs that name the commit the trigger or gate already vouched for. */
    private const TRUSTED_CHECKOUT_REFS = ['${{github.sha}}', 'refs/heads/main', 'main'];

    /** Events for which `github.ref == 'refs/heads/main'` implies the run's commit is main's. */
    private const REF_GATED_EVENTS = ['push', 'schedule', 'workflow_dispatch', 'pull_request', 'merge_group', 'release'];

    private const WRITE_PERMISSIONS = ['contents', 'packages', 'deployments', 'id-token'];

    /**
     * Credentialed jobs that may skip the proof, keyed "workflow.yml#job". Every
     * entry needs a reason; an entry that matches no violation fails the census.
     *
     * @var array<string, string>
     */
    private const ALLOWLIST = [];

    /**
     * Gate steps trusted to prove that the SHA they write to `$GITHUB_OUTPUT` as `sha`
     * is on main, keyed by the step's exact `run:`. `script` is the gate itself,
     * `proof` the test that exercises its refusals, and `markers` the source text of
     * the ancestry check the census credits it with: rewriting the gate without them
     * fails test_trusted_gates_still_prove_main_ancestry, so the entry is revisited.
     *
     * @var array<string, array{script: string, proof: string, markers: list<string>, reason: string}>
     */
    private const MAIN_ANCESTRY_GATES = [
        'node scripts/deploy/staging/gate.mjs' => [
            'script' => 'scripts/deploy/staging/gate.mjs',
            'proof' => 'tests/Unit/Deployment/StagingWorkflowTest.php',
            'markers' => [
                "env.GITHUB_REF !== 'refs/heads/main'",
                '/compare/${sha}...main',
                "['identical', 'ahead'].includes(comparison?.status)",
                'comparison?.behind_by !== 0',
                'comparison?.merge_base_commit?.sha !== sha',
                'verifySource(',
            ],
            'reason' => 'Refuses anything but a workflow_dispatch on refs/heads/main, then asks the GitHub compare API '
                .'for <sha>...main and refuses unless main is identical to or ahead of the SHA with the SHA as merge '
                .'base (an ancestor of main), and unless a main push run of tests.yml for that SHA has a successful '
                .'`ci` job. It writes sha= only after every check passed and exits non-zero otherwise.',
        ],
    ];

    public function test_credentialed_jobs_only_run_commits_on_main(): void
    {
        $violations = [];
        foreach ($this->workflowFiles() as $file) {
            $workflow = Yaml::parseFile($file);
            if (is_array($workflow)) {
                $violations += $this->violationsIn($workflow, basename($file));
            }
        }

        $violations = array_diff_key($violations, self::ALLOWLIST);

        self::assertSame([], $violations, $this->fixMessage($violations));
    }

    public function test_allowlist_entries_match_a_violation(): void
    {
        $violations = [];
        foreach ($this->workflowFiles() as $file) {
            $workflow = Yaml::parseFile($file);
            if (is_array($workflow)) {
                $violations += $this->violationsIn($workflow, basename($file));
            }
        }

        $stale = array_keys(array_diff_key(self::ALLOWLIST, $violations));

        self::assertSame([], $stale, 'ALLOWLIST in '.self::class.' names jobs that are no longer violations. '
            .'Remove these entries: '.implode(', ', $stale));
    }

    /**
     * Every job that deploys or publishes must stay credentialed-and-proven rather
     * than pass because the census stopped recognising it.
     */
    public function test_this_repositorys_credentialed_jobs_are_recognised(): void
    {
        $credentialed = [];
        foreach ($this->workflowFiles() as $file) {
            $workflow = Yaml::parseFile($file);
            foreach (is_array($workflow) ? ($workflow['jobs'] ?? []) : [] as $name => $job) {
                if (is_array($job) && $this->credentialsOf($workflow, $job) !== []) {
                    $credentialed[] = basename($file).'#'.$name;
                }
            }
        }

        $expected = [
            'cloud-vendor.yml#publish', 'deployment-diagnostic.yml#diagnose', 'pilot-deploy.yml#deploy',
            'staging-deploy.yml#deploy', 'tests.yml#deploy',
        ];

        foreach ($expected as $job) {
            self::assertContains($job, $credentialed, "$job holds deploy credentials but the census no longer sees them.");
        }
    }

    /**
     * A trusted gate must still exist, still make the check it is credited with, and
     * still have the test that proves its refusals.
     */
    public function test_trusted_gates_still_prove_main_ancestry(): void
    {
        $root = dirname(__DIR__, 3);
        foreach (self::MAIN_ANCESTRY_GATES as $run => $gate) {
            $source = file_get_contents($root.'/'.$gate['script']);
            self::assertIsString($source, "$run names a gate script that does not exist.");
            foreach ($gate['markers'] as $marker) {
                self::assertStringContainsString($marker, $source, $gate['script'].' no longer contains `'.$marker.'`. '
                    .'Re-read the gate: if it still proves main ancestry, update MAIN_ANCESTRY_GATES; if not, remove it.');
            }
            $proof = file_get_contents($root.'/'.$gate['proof']);
            self::assertIsString($proof, $gate['proof'].' is missing.');
            self::assertStringContainsString($gate['script'], $proof, $gate['proof'].' no longer exercises '.$gate['script'].'.');
            self::assertStringContainsString('commit not on main', $proof, $gate['proof'].' no longer proves the refusal.');
        }
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function fixtures(): array
    {
        $deployStep = "      - run: echo \"\${{ secrets.SSH_PRIVATE_KEY }}\" > key\n";
        $ancestry = "      - uses: actions/checkout@v4\n        with:\n          fetch-depth: 0\n"
            ."      - run: git merge-base --is-ancestor \"\$GITHUB_SHA\" origin/main\n";
        // staging-deploy.yml's shape: check out the dispatched main head, run the gate
        // from it, then check out the earlier main commit the gate verified.
        $gateStep = "      - uses: actions/checkout@v4\n        with:\n          ref: \${{ github.sha }}\n"
            ."      - id: gate\n        run: node scripts/deploy/staging/gate.mjs\n";
        $gateCheckout = "      - uses: actions/checkout@v4\n        if: \${{ steps.gate.outputs.sha != github.sha }}\n"
            ."        with:\n          ref: \${{ steps.gate.outputs.sha }}\n";
        $gated = fn (string $on, string $steps): string => "on:\n  $on\njobs:\n  deploy:\n"
            ."    if: \${{ github.ref == 'refs/heads/main' }}\n    runs-on: x\n    environment: staging\n    steps:\n$steps";

        return [
            'push to main only' => ["on:\n  push:\n    branches: [main]\njobs:\n  deploy:\n    runs-on: x\n    environment: prod\n    steps:\n$deployStep", true],
            'push to main plus schedule' => ["on:\n  push:\n    branches: [main]\n  schedule:\n    - cron: '0 0 * * *'\njobs:\n  deploy:\n    runs-on: x\n    steps:\n$deployStep", true],
            'push to any branch' => ["on: push\njobs:\n  deploy:\n    runs-on: x\n    steps:\n$deployStep", false],
            'push to main and tags' => ["on:\n  push:\n    branches: [main]\n    tags: ['v*']\njobs:\n  deploy:\n    runs-on: x\n    environment: prod\n    steps:\n      - run: make\n", false],
            'manual dispatch' => ["on: workflow_dispatch\njobs:\n  deploy:\n    runs-on: x\n    environment:\n      name: production\n    steps:\n      - run: make\n", false],
            'ref-gated dispatch' => ["on: [push, workflow_dispatch]\njobs:\n  deploy:\n    if: github.ref == 'refs/heads/main' && vars.ON == 'true'\n    runs-on: x\n    steps:\n$deployStep", true],
            'ref gate with an escape hatch' => ["on: [push, workflow_dispatch]\njobs:\n  deploy:\n    if: github.ref == 'refs/heads/main' || inputs.force\n    runs-on: x\n    steps:\n$deployStep", false],
            'ref gate alongside pull_request' => ["on: [push, pull_request]\njobs:\n  deploy:\n    if: \${{ !cancelled() && github.ref == 'refs/heads/main' }}\n    runs-on: x\n    environment: web1\n    steps:\n      - run: make\n", true],
            'ref gate under workflow_run' => ["on:\n  workflow_run:\n    workflows: [CI]\n    types: [completed]\njobs:\n  deploy:\n    if: github.ref == 'refs/heads/main'\n    runs-on: x\n    steps:\n$deployStep", false],
            'ref gate checking out github.sha' => ["on: workflow_dispatch\njobs:\n  deploy:\n    if: \${{ github.event_name == 'workflow_dispatch' && github.ref == 'refs/heads/main' }}\n    runs-on: x\n    steps:\n      - uses: actions/checkout@v4\n        with:\n          ref: \${{ github.sha }}\n$deployStep", true],
            'ref gate but checks out an input' => ["on: workflow_dispatch\njobs:\n  deploy:\n    if: github.ref == 'refs/heads/main'\n    runs-on: x\n    steps:\n      - uses: actions/checkout@v4\n        with:\n          ref: \${{ inputs.sha }}\n$deployStep", false],
            'ancestry check first' => ["on:\n  workflow_dispatch:\n    inputs:\n      sha: {required: true}\njobs:\n  deploy:\n    runs-on: x\n    environment: prod\n    steps:\n$ancestry$deployStep", true],
            'ancestry check after the secret' => ["on: workflow_dispatch\njobs:\n  deploy:\n    runs-on: x\n    steps:\n$deployStep$ancestry", false],
            'ancestry check swallowed' => ["on: workflow_dispatch\njobs:\n  deploy:\n    runs-on: x\n    steps:\n      - run: git merge-base --is-ancestor HEAD origin/main || true\n$deployStep", false],
            'ancestry check with secret in job env' => ["on: workflow_dispatch\njobs:\n  deploy:\n    runs-on: x\n    env:\n      KEY: \${{ secrets.DEPLOY_KEY }}\n    steps:\n$ancestry      - run: deploy\n", false],
            'OIDC on pull requests' => ["on: pull_request\njobs:\n  plan:\n    runs-on: x\n    permissions:\n      id-token: write\n    steps:\n      - run: plan\n", false],
            'workflow write-all on pull_request_target' => ["on: pull_request_target\npermissions: write-all\njobs:\n  label:\n    runs-on: x\n    steps:\n      - run: label\n", false],
            'inherited secrets on pull requests' => ["on: pull_request\njobs:\n  call:\n    uses: ./.github/workflows/deploy.yml\n    secrets: inherit\n", false],
            'ancestry check via git -C' => ["on: workflow_dispatch\njobs:\n  deploy:\n    runs-on: x\n    environment: prod\n    steps:\n      - uses: actions/checkout@v4\n      - run: |\n          if ! git -C . merge-base --is-ancestor HEAD origin/main; then exit 1; fi\n$deployStep", true],
            'untrusted step before the ancestry check' => ["on: workflow_dispatch\njobs:\n  deploy:\n    runs-on: x\n    environment: prod\n    steps:\n      - uses: actions/checkout@v4\n      - run: pnpm install\n$ancestry$deployStep", false],
            'CI with a non-production secret' => ["on: pull_request\njobs:\n  test:\n    runs-on: x\n    steps:\n      - run: upload \${{ secrets.POSTHOG_NONPROD_API_KEY }}\n", true],
            'CI with a non-deploy secret' => ["on: pull_request\npermissions:\n  contents: read\njobs:\n  test:\n    runs-on: x\n    steps:\n      - run: upload \${{ secrets.CODECOV_TOKEN }}\n", true],
            'ref gate checking out a trusted gate output' => [$gated('workflow_dispatch', $gateStep.$gateCheckout.$deployStep), true],
            'gate output from an unlisted script' => [$gated('workflow_dispatch', str_replace('staging/gate.mjs', 'staging/other.mjs', $gateStep).$gateCheckout.$deployStep), false],
            'gate output checked out before the gate runs' => [$gated('workflow_dispatch', $gateCheckout.$gateStep.$deployStep), false],
            'gate step that cannot fail the job' => [$gated('workflow_dispatch', $gateStep."        continue-on-error: true\n".$gateCheckout.$deployStep), false],
            'gate step swallowed with || true' => [$gated('workflow_dispatch', str_replace('gate.mjs', 'gate.mjs || true', $gateStep).$gateCheckout.$deployStep), false],
            'gate step that may be skipped' => [$gated('workflow_dispatch', $gateStep."        if: inputs.check\n".$gateCheckout.$deployStep), false],
            'gate output read from another step' => [$gated('workflow_dispatch', $gateStep.str_replace('steps.gate.', 'steps.other.', $gateCheckout).$deployStep), false],
            'gate output under workflow_run' => [$gated("workflow_run:\n    workflows: [CI]", $gateStep.$gateCheckout.$deployStep), false],
            'gate checked out from the dispatch input' => [$gated('workflow_dispatch', "      - uses: actions/checkout@v4\n        with:\n          ref: \${{ inputs.sha }}\n".$gateStep.$gateCheckout.$deployStep), false],
        ];
    }

    /**
     * The census must accept the three proofs and refuse everything else; this
     * template has only two credentialed jobs, so prove it on fixtures.
     */
    #[DataProvider('fixtures')]
    public function test_census_classifies_fixture_workflows(string $yaml, bool $passes): void
    {
        $workflow = Yaml::parse($yaml);
        self::assertIsArray($workflow);

        $violations = $this->violationsIn($workflow, 'fixture.yml');

        self::assertSame($passes, $violations === [], $passes ? implode("\n", $violations) : 'Expected a violation.');
    }

    /**
     * @param  array<mixed>  $workflow
     * @return array<string, string>
     */
    private function violationsIn(array $workflow, string $file): array
    {
        $events = $this->events($workflow);
        $violations = [];

        foreach ($workflow['jobs'] ?? [] as $name => $job) {
            if (! is_array($job)) {
                continue;
            }

            $credentials = $this->credentialsOf($workflow, $job);
            if ($credentials === []) {
                continue;
            }

            $problem = $this->unprovenBecause($workflow, $events, $job);
            if ($problem !== null) {
                $violations[$file.'#'.$name] = $file.'#'.$name.' holds '.implode(', ', $credentials).' but '.$problem;
            }
        }

        return $violations;
    }

    /**
     * Why the job cannot prove it runs a main commit, or null when it can.
     *
     * @param  array<mixed>  $workflow
     * @param  array<string, mixed>  $events
     * @param  array<mixed>  $job
     */
    private function unprovenBecause(array $workflow, array $events, array $job): ?string
    {
        $ancestry = $this->ancestryProblem($workflow, $job);
        if ($ancestry === null) {
            return null;
        }

        $eventNames = array_keys($events);
        $mainOnly = $eventNames !== [] && array_diff($eventNames, ['push', 'schedule']) === []
            && (! array_key_exists('push', $events) || $this->pushIsMainOnly($events['push']));
        $refGated = $eventNames !== [] && array_diff($eventNames, self::REF_GATED_EVENTS) === []
            && $this->ifRequiresMain($job['if'] ?? null);

        if ($mainOnly || $refGated) {
            return $this->checksOutAnotherRef($job)
                ? 'its actions/checkout sets `ref:`, so it may not run the commit its trigger guarantees.'
                : null;
        }

        return 'its workflow is triggered by ['.implode(', ', $eventNames).'] with no main-only proof: '.$ancestry;
    }

    /**
     * @param  array<mixed>  $workflow
     * @param  array<mixed>  $job
     * @return list<string>
     */
    private function credentialsOf(array $workflow, array $job): array
    {
        $credentials = [];
        if (isset($job['environment'])) {
            $environment = is_array($job['environment']) ? ($job['environment']['name'] ?? '?') : $job['environment'];
            $credentials[] = 'environment `'.$environment.'`';
        }

        $secrets = $this->sensitiveSecrets([$job, $workflow['env'] ?? []]);
        if ($secrets !== []) {
            $credentials[] = 'secrets '.implode(', ', $secrets);
        }
        if (($job['secrets'] ?? null) === 'inherit') {
            $credentials[] = '`secrets: inherit`';
        }

        $permissions = $job['permissions'] ?? $workflow['permissions'] ?? [];
        if ($permissions === 'write-all') {
            $credentials[] = 'permissions write-all';
        } elseif (is_array($permissions)) {
            foreach (self::WRITE_PERMISSIONS as $scope) {
                if (($permissions[$scope] ?? null) === 'write') {
                    $credentials[] = "$scope: write";
                }
            }
        }

        return $credentials;
    }

    /**
     * @param  array<mixed>  $nodes
     * @return list<string>
     */
    private function sensitiveSecrets(array $nodes): array
    {
        preg_match_all('/secrets\s*(?:\.\s*([A-Za-z_][A-Za-z0-9_]*)|\[\s*[\'"]([^\'"]+)[\'"]\s*\])/', implode("\n", $this->strings($nodes)), $matches);
        $names = array_filter([...$matches[1], ...$matches[2]], fn (string $name): bool => $name !== ''
            && array_intersect(explode('_', strtoupper($name)), self::SENSITIVE_SECRET_WORDS) !== []);

        return array_values(array_unique($names));
    }

    /**
     * Every string key and scalar value in a parsed YAML tree.
     *
     * @return list<string>
     */
    private function strings(mixed $node): array
    {
        if (! is_array($node)) {
            return is_scalar($node) ? [(string) $node] : [];
        }

        $strings = [];
        foreach ($node as $key => $value) {
            $strings = [...$strings, (string) $key, ...$this->strings($value)];
        }

        return $strings;
    }

    /**
     * Why the job has no valid ancestry check, or null when it has one.
     *
     * @param  array<mixed>  $workflow
     * @param  array<mixed>  $job
     */
    private function ancestryProblem(array $workflow, array $job): ?string
    {
        if ($this->sensitiveSecrets([$job['env'] ?? [], $workflow['env'] ?? []]) !== []) {
            return 'a job- or workflow-level env: passes a deploy secret to every step, including those before any ancestry check.';
        }

        foreach (array_values(is_array($job['steps'] ?? null) ? $job['steps'] : []) as $step) {
            $run = is_array($step) && is_string($step['run'] ?? null) ? $step['run'] : '';
            if (preg_match('/\bgit\b[^\n]*\bmerge-base\s+--is-ancestor\b/', $run) === 1) {
                $swallowed = str_contains($run, '|| true') || ! in_array($step['continue-on-error'] ?? false, [false, 'false'], true);

                return $swallowed ? 'its `git merge-base --is-ancestor` step cannot fail the job (`|| true` or continue-on-error).' : null;
            }

            $uses = is_array($step) && is_string($step['uses'] ?? null) ? $step['uses'] : '';
            if (! str_starts_with($uses, 'actions/checkout@')) {
                return 'no `git merge-base --is-ancestor` step runs before its first step that is not actions/checkout.';
            }
        }

        return 'it has no `git merge-base --is-ancestor` step.';
    }

    private function pushIsMainOnly(mixed $push): bool
    {
        if (! is_array($push) || ! is_array($push['branches'] ?? null)) {
            return false;
        }

        foreach (['tags', 'branches-ignore', 'tags-ignore'] as $widening) {
            if (array_key_exists($widening, $push)) {
                return false;
            }
        }

        $branches = array_unique(array_map(
            fn (mixed $branch): string => is_string($branch) ? preg_replace('#^refs/heads/#', '', $branch) ?? '' : '',
            $push['branches'],
        ));

        return $branches === [self::MAIN_BRANCH];
    }

    private function ifRequiresMain(mixed $condition): bool
    {
        if (! is_string($condition) || str_contains($condition, '||') || preg_match('/!\s*\(/', $condition) === 1) {
            return false;
        }

        $main = preg_quote('refs/heads/'.self::MAIN_BRANCH, '/');

        return preg_match('/github\.ref\s*==\s*([\'"])'.$main.'\1/', $condition) === 1;
    }

    /**
     * @param  array<mixed>  $job
     */
    private function checksOutAnotherRef(array $job): bool
    {
        $trustedGates = [];
        foreach (is_array($job['steps'] ?? null) ? array_values($job['steps']) : [] as $step) {
            if (! is_array($step)) {
                continue;
            }

            $gate = $this->trustedGateId($step);
            if ($gate !== null) {
                $trustedGates[] = '${{steps.'.$gate.'.outputs.sha}}';
            }

            $uses = is_string($step['uses'] ?? null) ? $step['uses'] : '';
            if (! str_starts_with($uses, 'actions/checkout@') || ! is_array($step['with'] ?? null)
                || ! array_key_exists('ref', $step['with'])) {
                continue;
            }

            $ref = is_string($step['with']['ref']) ? preg_replace('/\s+/', '', $step['with']['ref']) : null;
            if (! in_array($ref, [...self::TRUSTED_CHECKOUT_REFS, ...$trustedGates], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The id of a step that runs a MAIN_ANCESTRY_GATES gate and can fail the job, or null.
     *
     * @param  array<mixed>  $step
     */
    private function trustedGateId(array $step): ?string
    {
        $id = $step['id'] ?? null;
        $run = $step['run'] ?? null;
        if (! is_string($id) || ! is_string($run) || ! array_key_exists(trim($run), self::MAIN_ANCESTRY_GATES)) {
            return null;
        }

        $mayNotFail = ! in_array($step['continue-on-error'] ?? false, [false, 'false'], true);

        return $mayNotFail || array_key_exists('if', $step) ? null : $id;
    }

    /**
     * The workflow's triggers, as event => configuration.
     *
     * @param  array<mixed>  $workflow
     * @return array<string, mixed>
     */
    private function events(array $workflow): array
    {
        // YAML 1.1 reads a bare `on` key as boolean true; accept either spelling.
        $on = $workflow['on'] ?? $workflow[1] ?? [];
        if (is_string($on)) {
            return [$on => null];
        }

        $events = [];
        foreach (is_array($on) ? $on : [] as $key => $value) {
            if (is_int($key)) {
                if (is_string($value)) {
                    $events[$value] = null;
                }
            } else {
                $events[(string) $key] = $value;
            }
        }

        return $events;
    }

    /**
     * @return list<string>
     */
    private function workflowFiles(): array
    {
        return [
            ...glob($this->workflowDirectory().'/*.yml') ?: [],
            ...glob($this->workflowDirectory().'/*.yaml') ?: [],
        ];
    }

    private function workflowDirectory(): string
    {
        return dirname(__DIR__, 3).'/.github/workflows';
    }

    /**
     * @param  array<string, string>  $violations
     */
    private function fixMessage(array $violations): string
    {
        return "These jobs can reach deploy credentials from a commit that is not on main:\n  - "
            .implode("\n  - ", $violations)."\n\n"
            .'Fix one of three ways (see the class docblock of '.self::class."):\n"
            ."  A. Trigger the workflow only on `push: branches: [main]` (and `schedule`).\n"
            ."  B. Gate the job with `if: github.ref == 'refs/heads/main'` (no `||`), keep the triggers to\n"
            .'     '.implode(', ', self::REF_GATED_EVENTS).", and do not set a checkout `ref:`.\n"
            ."  C. Make the first step after actions/checkout (with `fetch-depth: 0`) fail closed:\n"
            ."       - name: Refuse commits that are not on main\n"
            ."         run: git merge-base --is-ancestor \"\$(git rev-parse HEAD)\" origin/main\n"
            ."     and keep deploy secrets out of job- and workflow-level `env:`.\n"
            ."Also restrict the job's GitHub Environment to the main branch in the repository settings.\n"
            .'If a job genuinely needs none of these, add "<file>#<job>" to ALLOWLIST with the reason.'."\n";
    }
}
