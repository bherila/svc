<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class DeploymentSafetyTest extends TestCase
{
    #[Test]
    public function deployment_is_atomic_hard_scoped_and_preserves_private_files(): void
    {
        $workflow = file_get_contents(__DIR__.'/../../.github/workflows/tests.yml');
        $preMigrate = file_get_contents(__DIR__.'/../../scripts/deploy/pre-migrate.sh');
        $quiesce = file_get_contents(__DIR__.'/../../scripts/deploy/quiesce.sh');
        $verifyLive = file_get_contents(__DIR__.'/../../scripts/deploy/verify-live.sh');
        $preflight = file_get_contents(__DIR__.'/../../scripts/deploy/preflight.sh');

        $this->assertIsString($workflow);
        $this->assertIsString($preMigrate);
        $this->assertIsString($quiesce);
        $this->assertIsString($verifyLive);
        $this->assertIsString($preflight);
        $this->assertStringContainsString('environment: web1', $workflow);

        // The shared action holds a key that reaches every application on the account, so it
        // must be pinned to a full commit, never a mutable tag.
        $this->assertStringContainsString(
            'uses: bherila/shared-cpanel-deployment@46a47522ad977c008a1251b0f67e73807a8bfb10',
            $workflow,
        );
        $this->assertMatchesRegularExpression('~uses: bherila/shared-cpanel-deployment@[0-9a-f]{40}\b~', $workflow);
        $this->assertDoesNotMatchRegularExpression('~uses: bherila/shared-cpanel-deployment@(?![0-9a-f]{40}\b)~', $workflow);

        // Exactly one destination, and it is this application's.
        $this->assertSame(1, preg_match_all('~^\s*deploy-dir:~m', $workflow));
        $this->assertMatchesRegularExpression('~^\s*deploy-dir: svc-laravel\s*$~m', $workflow);
        $this->assertStringNotContainsString(':~/', $workflow);
        foreach (['bwh-php', 'phr-laravel', 'games-laravel'] as $other) {
            $this->assertStringNotContainsString("deploy-dir: {$other}", $workflow);
        }

        // Atomic releases share the complete storage tree. That makes svc-blobs, sessions,
        // runtime state and OAuth keys authoritative across every release and rollback.
        $this->assertStringContainsString('deployment-mode: atomic', $workflow);
        $this->assertStringContainsString('persistent-paths: storage', $workflow);
        $this->assertStringContainsString("retain-releases: '0'", $workflow);
        $this->assertStringNotContainsString('webroot-symlink:', $workflow);
        $this->assertStringContainsString('failure-policy: rollback', $workflow);
        $this->assertStringContainsString("operational-audit: 'true'", $workflow);
        $this->assertStringContainsString("runtime-audit: 'true'", $workflow);
        $this->assertStringContainsString('initial-live-commit:', $workflow);
        $this->assertStringContainsString('env-source: .config/svc/deployment.env', $workflow);
        $this->assertStringContainsString('passport-key-directory: storage/app/private/oauth', $workflow);
        $this->assertStringNotContainsString("excludes: |\n            svc-blobs", $workflow);

        // The candidate is gated before migration, activation and final commit. The live OAuth/MCP
        // probe executes inside the action's recovery boundary, not in a detached follow-up job.
        $this->assertStringContainsString('preflight-script: scripts/deploy/preflight.sh', $workflow);
        $this->assertStringContainsString('bash tests/Deployment/test-preflight.sh', $workflow);
        $this->assertStringContainsString("stat -c '%a:%u'", $preflight);
        $this->assertStringContainsString('test "$stable_dir" = svc-laravel', $preflight);
        foreach (['deployment.env', 'database.env', 'oauth-private.key', 'oauth-public.key'] as $prerequisite) {
            $this->assertStringContainsString($prerequisite, $preflight);
        }
        foreach (['chmod ', 'install ', 'cat ', 'passport:keys', 'artisan '] as $mutationOrContentRead) {
            $this->assertStringNotContainsString($mutationOrContentRead, $preflight);
        }
        $this->assertStringContainsString('quiesce-script: scripts/deploy/quiesce.sh', $workflow);
        $this->assertStringContainsString('pre-migrate-script: scripts/deploy/pre-migrate.sh', $workflow);
        $this->assertStringContainsString('post-deploy-script: scripts/deploy/post-deploy.sh', $workflow);
        $this->assertStringContainsString('verification-script: scripts/deploy/verify-live.sh', $workflow);
        $this->assertStringNotContainsString('deployed-mcp-smoke:', $workflow);

        $this->assertStringContainsString('mapfile -d', $quiesce);
        $this->assertStringContainsString('artisan | */artisan', $quiesce);
        $this->assertStringContainsString('"$stable_root/"*', $quiesce);
        $this->assertStringNotContainsString('pkill', $quiesce);
        $this->assertStringNotContainsString('kill ', $quiesce);

        $this->assertStringContainsString('test -s storage/app/private/oauth/oauth-private.key', $preMigrate);
        $this->assertStringContainsString('test -s storage/app/private/oauth/oauth-public.key', $preMigrate);
        $this->assertStringNotContainsString('passport:keys --force', $preMigrate);

        $this->assertStringContainsString('test "$DEPLOY_LIVE_COMMIT" = "$DEPLOY_SOURCE_COMMIT"', $verifyLive);
        $this->assertStringContainsString('test "$home_status" = 200', $verifyLive);
        $this->assertStringContainsString('Public home page did not render HTML.', $verifyLive);
        $this->assertStringContainsString('--connect-timeout 5 --max-time 20', $verifyLive);
        $this->assertStringContainsString('trap cleanup EXIT', $verifyLive);
        $this->assertStringContainsString('svc:mcp:deploy-smoke-credentials', $verifyLive);
        $this->assertStringContainsString('"$remote_artisan --revoke"', $verifyLive);
        $this->assertStringContainsString('node scripts/mcp-smoke.mjs', $verifyLive);
        $this->assertStringContainsString('node scripts/deploy/verify-oauth-redirect.mjs', $verifyLive);
        $this->assertStringContainsString('node --test tests/Deployment/verify-oauth-redirect.test.mjs tests/Deployment/verify-hashed-assets.test.mjs', $workflow);
        $this->assertStringContainsString('node scripts/deploy/verify-hashed-assets.mjs', $verifyLive);
        $this->assertLessThan(
            strpos($verifyLive, 'credentials=$(ssh'),
            strpos($verifyLive, 'node scripts/deploy/verify-hashed-assets.mjs'),
            'Hashed asset proof must pass before smoke credential issuance.',
        );
        $this->assertLessThan(
            strpos($verifyLive, 'credentials=$(ssh'),
            strpos($verifyLive, 'node scripts/deploy/verify-oauth-redirect.mjs'),
            'The complete OAuth redirect contract must pass before smoke credential issuance.',
        );
        $this->assertLessThan(
            strpos($verifyLive, 'credentials=$(ssh'),
            strpos($verifyLive, 'trap cleanup EXIT'),
            'Credential cleanup must be armed before the remote issuance attempt.',
        );
    }

    #[Test]
    public function the_action_manages_exactly_one_canonical_scheduler_line(): void
    {
        $workflow = $this->read('.github/workflows/tests.yml');
        $cronAudit = $this->read('scripts/deploy/cron-audit.sh');
        $verifyLive = $this->read('scripts/deploy/verify-live.sh');
        $postFinalize = $this->read('scripts/deploy/post-finalize.sh');
        $preflight = $this->read('scripts/deploy/preflight.sh');

        // Owner decision on #327: the shared action installs SVC's scheduler line. The scheduler is
        // the only background runner, so there are no replacement or extra (worker) lines, and the
        // line uses the same 1G limit as the deploy's Artisan commands.
        $this->assertStringContainsString("install-cron: 'true'", $workflow);
        $this->assertMatchesRegularExpression('~^\s*cron-memory-limit: 1G\s*$~m', $workflow);
        $this->assertMatchesRegularExpression('~^\s*artisan-memory-limit: 1G\s*$~m', $workflow);
        foreach (['cron-lines:', 'extra-cron-lines:', 'scheduler-log:'] as $override) {
            $this->assertDoesNotMatchRegularExpression('~^\s*'.preg_quote($override, '~').'~m', $workflow);
        }

        // The audit accepts exactly the line the pinned action renders for those inputs (the hook
        // test runs the pinned installer to prove it) and never writes the crontab.
        $this->assertStringContainsString(
            '\'* * * * * cd "$HOME/\'"$stable_dir"\'" && \'"$php"\' -d memory_limit=1G artisan schedule:run > /dev/null 2>&1 # JOB:\'"$stable_dir"\'-scheduler\'',
            $cronAudit,
        );
        $this->assertStringContainsString('test "$stable_dir" = svc-laravel', $cronAudit);
        $this->assertStringContainsString('crontab -l >"$work/current"', $cronAudit);
        foreach ([$cronAudit, $preflight] as $readOnly) {
            $this->assertDoesNotMatchRegularExpression('~\bcrontab\s+(?!-l\b)["$/-]~', $readOnly);
            $this->assertStringNotContainsString('cat "$work/current"', $readOnly);
            $this->assertStringNotContainsString('cat "$cron_work/current"', $readOnly);
        }
        $this->assertStringContainsString('1:1) echo \'SVC scheduler cron: exactly one canonical line.\' ;;', $cronAudit);
        $this->assertStringContainsString('cannot pause or replace it', $preflight);

        // The release is not healthy, and no smoke credential is issued, until the line is proven;
        // the read-only post-finalizer proves it again once the release is committed.
        $this->assertStringContainsString('<scripts/deploy/cron-audit.sh', $verifyLive);
        $this->assertLessThan(
            strpos($verifyLive, 'credentials=$(ssh'),
            strpos($verifyLive, '<scripts/deploy/cron-audit.sh'),
            'The scheduler cron proof must pass before smoke credential issuance.',
        );
        $this->assertStringContainsString('post-finalize-script: scripts/deploy/post-finalize.sh', $workflow);
        $this->assertStringContainsString('<scripts/deploy/cron-audit.sh', $postFinalize);
        $this->assertStringContainsString('test "$DEPLOY_LIVE_COMMIT" = "$DEPLOY_SOURCE_COMMIT"', $postFinalize);
        $this->assertStringContainsString('test "$DEPLOY_LIVE_STATE" = serving', $postFinalize);
        $this->assertStringNotContainsString('artisan', $postFinalize);

        $this->assertStringContainsString('bash tests/Deployment/test-cron-audit.sh', $workflow);
        $this->assertStringContainsString('SHARED_DEPLOY_ACTION_PATH: .shared-cpanel-deployment', $workflow);
    }

    #[Test]
    public function the_live_diagnostic_is_read_only_dispatch_only_and_takes_a_reviewed_identity(): void
    {
        $deploy = $this->read('.github/workflows/tests.yml');
        $workflow = $this->read('.github/workflows/deployment-diagnostic.yml');
        $diagnose = $this->read('scripts/deploy/diagnose-live.sh');

        // Every checkout of the shared action, here and in CI, is the deploy job's exact pin.
        $this->assertSame(1, preg_match('~uses: bherila/shared-cpanel-deployment@([0-9a-f]{40})\b~', $deploy, $pin));
        $pinnedCommit = $pin[1] ?? null;
        $this->assertIsString($pinnedCommit);
        foreach ([$deploy, $workflow] as $file) {
            preg_match_all('~repository: bherila/shared-cpanel-deployment\s+ref: (\S+)~', $file, $refs);
            $this->assertNotEmpty($refs[1]);
            foreach ($refs[1] as $ref) {
                $this->assertSame($pinnedCommit, $ref);
            }
        }
        $this->assertStringNotContainsString('uses: bherila/shared-cpanel-deployment', $workflow);

        // Manually dispatched only, from main, behind the deploy's protected environment, and never
        // in the deploy's concurrency group, where a queued run could displace a pending deploy.
        $this->assertMatchesRegularExpression('~^on:\n  workflow_dispatch:\n~m', $workflow);
        foreach (['push:', 'pull_request', 'schedule:', 'workflow_run', 'repository_dispatch'] as $trigger) {
            $this->assertStringNotContainsString($trigger, $workflow);
        }
        $this->assertStringContainsString('environment: web1', $workflow);
        $this->assertStringContainsString("if: \${{ github.ref == 'refs/heads/main' }}", $workflow);
        $this->assertStringContainsString("permissions:\n  contents: read", $workflow);
        $this->assertStringContainsString('group: svc-production-web1-diagnostic', $workflow);
        $this->assertStringNotContainsString("group: svc-production-web1\n", $workflow);

        // The identity is an input, never a hardcoded SHA, and reaches the shell only through env.
        $this->assertSame(1, preg_match_all('~(?<!@)\b[0-9a-f]{40}\b~', $workflow), 'Only the shared action ref may be a literal SHA.');
        foreach (['release_id', 'commit'] as $input) {
            $this->assertMatchesRegularExpression("~^      {$input}:\n(?:        .+\n)*?        required: true\n~m", $workflow);
        }
        $this->assertStringContainsString('DIAG_RELEASE_ID: ${{ inputs.release_id }}', $workflow);
        $this->assertStringContainsString('DIAG_COMMIT: ${{ inputs.commit }}', $workflow);
        $this->assertDoesNotMatchRegularExpression('~run:[^\n]*\$\{\{~', $workflow);
        $this->assertStringContainsString('[[ $commit =~ ^[0-9a-f]{40}$ ]]', $diagnose);
        $this->assertStringContainsString('[ "${BASH_REMATCH[1]}" = "${commit:0:12}" ]', $diagnose);

        // It runs the pinned finalized runtime audit, the cron proof and the hashed asset audit, and
        // nothing that writes, provisions or issues credentials.
        $this->assertStringContainsString('DIAG_AUDIT_SCRIPT: .shared-cpanel-deployment/scripts/operational-audit.sh', $workflow);
        $this->assertStringContainsString('"$persistent" finalized', $diagnose);
        $this->assertStringContainsString('phase=finalized', $diagnose);
        $this->assertStringContainsString("= 'runtime-audit generation=superseded'", $diagnose);
        // Not serialised with deploys, so the reviewed generation is re-proven after every check.
        $this->assertStringContainsString('"$persistent" generation', $diagnose);
        $this->assertStringContainsString("= 'runtime-audit generation=current'", $diagnose);
        $this->assertStringContainsString(
            "runtime_audit\ncron_audit\nasset_audit\ngeneration_recheck\n\nif [ \"\$failures\" -ne 0 ]",
            $diagnose,
        );
        $this->assertStringContainsString('<scripts/deploy/cron-audit.sh', $diagnose);
        $this->assertStringContainsString('node scripts/deploy/verify-hashed-assets.mjs', $diagnose);
        foreach ([$workflow, $diagnose] as $file) {
            // Comments may name what the diagnostic avoids; only executable lines are checked.
            $file = (string) preg_replace('~^\s*#.*$~m', '', $file);
            foreach (['storage:status', '--write', 'deploy-smoke-credentials', 'passport', 'verify-live.sh', 'mcp-smoke', 'migrate', 'artisan up', 'artisan down', 'rsync'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $file);
            }
        }
    }

    private function read(string $path): string
    {
        $contents = file_get_contents(__DIR__.'/../../'.$path);
        $this->assertIsString($contents);

        return $contents;
    }

    #[Test]
    public function blob_mirror_has_fixed_authoritative_and_local_roots(): void
    {
        $script = file_get_contents(__DIR__.'/../../scripts/blobs.sh');

        $this->assertIsString($script);
        $this->assertStringContainsString('PROJECT="svc"', $script);
        $this->assertStringContainsString('REMOTE_HOST="ssh-svc"', $script);
        $this->assertStringContainsString('REMOTE_PATH="svc-laravel/storage/app/private"', $script);
        $this->assertStringContainsString('rsync "${RSYNC_OPTS[@]}" --delete --chmod=Du=rwx,Dgo=,Fu=rw,Fgo= "${REMOTE}/" "${LOCAL_PATH}/"', $script);
        $this->assertStringContainsString('find "$LOCAL_PATH" -type d -exec chmod 700 {} +', $script);
        $this->assertStringContainsString('find "$LOCAL_PATH" -type f -exec chmod 600 {} +', $script);
        $this->assertStringContainsString('sha256sum {} +', $script);
        $this->assertStringContainsString('shasum -a 256 {} +', $script);
        $this->assertStringContainsString('cmp -s "$manifest_directory/web1.sha256" "$manifest_directory/x-data.sha256"', $script);
        $this->assertStringContainsString('[[ ! -L "$LOCAL_PATH" ]]', $script);
        $this->assertStringContainsString('canonical_x_data=$(cd "$X_DATA" && pwd -P)', $script);
        $this->assertStringContainsString('if [ "$APPLY" -eq 1 ]; then', $script);
        $this->assertStringNotContainsString('rsync "${RSYNC_OPTS[@]}" --delete "${LOCAL_PATH}/" "${REMOTE}/"', $script);
    }

    #[Test]
    public function database_snapshot_is_pull_only_verified_and_scoped_to_x_data(): void
    {
        $script = file_get_contents(__DIR__.'/../../scripts/db-snapshot.sh');

        $this->assertIsString($script);
        $this->assertStringContainsString('PROJECT="svc"', $script);
        $this->assertStringContainsString('REMOTE_HOST="ssh-svc"', $script);
        $this->assertStringContainsString('REMOTE_PROJECT_PATH="svc-laravel"', $script);
        $this->assertStringContainsString('LOCAL_DIRECTORY="${DB_SNAPSHOT_DIR:-$X_DATA/${PROJECT}-database}"', $script);
        $this->assertStringNotContainsString('$X_DATA/$PROJECT/database', $script);
        $this->assertStringContainsString('database snapshots must stay outside the rsync-managed', $script);
        $this->assertStringContainsString('--single-transaction', $script);
        $this->assertStringContainsString('--quick', $script);
        $this->assertStringContainsString('--no-tablespaces', $script);
        $this->assertStringContainsString('gzip -t', $script);
        $this->assertStringContainsString('sha256_file', $script);
        $this->assertStringContainsString('[[ "$candidate" =~ ^${PROJECT}-[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{6}Z\\.sql\\.gz$ ]] || continue', $script);
        $this->assertStringContainsString('canonical_snapshot_parent=$(cd "$snapshot_parent" && pwd -P)', $script);
        $this->assertStringContainsString('[[ ! -L "$LOCAL_DIRECTORY" ]]', $script);
        $this->assertStringContainsString('no restore or push mode exists', $script);
        $this->assertStringNotContainsString('case "$MODE" in\n    push)', $script);
    }
}
