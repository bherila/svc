<?php

namespace Tests\Unit\Deployment;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

final class CloudVendorProvenanceTest extends TestCase
{
    public function test_installer_requires_the_immutable_main_run_proof(): void
    {
        $process = new Process([
            'bash',
            dirname(__DIR__, 2).'/scripts/vendor-bundle-provenance-test.sh',
        ]);
        $process->setTimeout(30);
        $process->run();

        self::assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
        self::assertStringContainsString('tampered release bytes are refused', $process->getOutput());
        self::assertStringContainsString('all immutable vendor provenance checks passed', $process->getOutput());
    }

    public function test_label_only_forgery_and_expired_proof_fail_before_bundle_download(): void
    {
        $process = new Process([
            'bash',
            dirname(__DIR__, 2).'/scripts/vendor-bundle-label-forgery-test.sh',
        ]);
        $process->setTimeout(30);
        $process->run();

        self::assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
        self::assertStringContainsString('forged legacy label', $process->getOutput());
        self::assertStringContainsString('missing or expired immutable proof', $process->getOutput());
        self::assertStringContainsString('distinct workflow/source SHAs through full proof verification', $process->getOutput());
    }

    public function test_publish_job_records_an_immutable_proof_and_refreshes_it_before_expiry(): void
    {
        $workflow = Yaml::parseFile(dirname(__DIR__, 3).'/.github/workflows/cloud-vendor.yml');
        $plan = $workflow['jobs']['plan'];
        $build = $workflow['jobs']['build'];
        $publish = $workflow['jobs']['publish'];
        $buildSteps = collect($build['steps']);
        $publishSteps = collect($publish['steps']);

        $upload = $buildSteps->firstWhere('name', 'Upload immutable provenance proof');
        self::assertNotNull($upload);
        self::assertStringStartsWith('actions/upload-artifact@', $upload['uses']);
        self::assertSame('${{ steps.bundle.outputs.artifact }}', $upload['with']['name']);
        self::assertSame('${{ steps.bundle.outputs.manifest }}', $upload['with']['path']);
        self::assertSame(90, $upload['with']['retention-days']);
        self::assertSame('error', $upload['with']['if-no-files-found']);

        self::assertSame('0 5 1 * *', $workflow['on']['schedule'][0]['cron']);
        $planCheckout = collect($plan['steps'])->first(static fn (array $step): bool => str_starts_with((string) ($step['uses'] ?? ''), 'actions/checkout@'));
        self::assertNotNull($planCheckout);
        self::assertSame(0, $planCheckout['with']['fetch-depth']);
        self::assertSame('${{ fromJSON(needs.plan.outputs.matrix) }}', $build['strategy']['matrix']);
        self::assertSame(['plan', 'build'], $publish['needs']);
        self::assertStringContainsString('always()', $publish['if']);
        self::assertSame('${{ steps.plan.outputs.current_lock }}', $plan['outputs']['current_lock']);
        self::assertSame('${{ needs.plan.outputs.current_lock }}', $publish['env']['CURRENT_LOCK']);
        self::assertSame('read', $plan['permissions']['contents']);
        self::assertSame('read', $build['permissions']['contents']);
        self::assertSame('write', $publish['permissions']['contents']);

        $serializedPlan = Yaml::dump($plan, 20);
        $serializedBuild = Yaml::dump($build, 20);
        $serializedPublish = Yaml::dump($publish, 20);
        self::assertContains('scripts/cloud/vendor-bundle-publish-asset.sh', $workflow['on']['push']['paths']);
        self::assertStringContainsString('vendor-bundle-refresh-plan.sh retained', $serializedPlan);
        self::assertStringContainsString('scripts/cloud/vendor-bundle-publish-asset.sh', $serializedPublish);
        self::assertStringContainsString('cloud-vendor-attestation/v2', $serializedBuild);
        self::assertStringContainsString('run_sha=$GITHUB_SHA', $serializedBuild);
        self::assertStringContainsString('source_sha=$SOURCE_SHA', $serializedBuild);
        self::assertStringContainsString('cloud-vendor-provenance-$lock-$GITHUB_RUN_ID-$GITHUB_RUN_ATTEMPT', $serializedPublish);
        self::assertStringContainsString(
            'cloud-vendor/v4 h=$GITHUB_SHA s=$source_sha r=$GITHUB_RUN_ID a=$GITHUB_RUN_ATTEMPT l=$lock e=$evidence_id',
            $serializedPublish,
        );
        self::assertStringContainsString('vendor-bundle-artifact-meta.sh', $serializedPublish);
        self::assertStringContainsString('while IFS=', $serializedPublish);
        self::assertStringContainsString('historical build failed', $serializedPublish);
        self::assertStringContainsString('current bundle build did not stage', $serializedPublish);
        $publishBundles = $publishSteps->firstWhere('name', 'Publish rebuilt bundles sequentially');
        self::assertNotNull($publishBundles);
        $publishScript = $publishBundles['run'];
        self::assertIsString($publishScript);
        self::assertStringContainsString('done <"$target_rows"', $publishScript);
        self::assertStringContainsString('map(select(.name | test(', $publishScript);
        self::assertStringContainsString(
            '^vendor-[0-9a-f]{64}[.]tar[.]zst$',
            $publishScript,
            'Retention must not count or delete preserved candidate/previous recovery assets.',
        );
        self::assertDoesNotMatchRegularExpression(
            '/[<>]\(/',
            $publishScript,
            'The Lambda-backed publish job must not require /dev/fd process substitution.',
        );
        // Every job but the read-only branch validation is gated on main, and
        // publish is the only job that may write contents.
        self::assertSame(['contents' => 'read'], $workflow['permissions']);
        foreach ($workflow['jobs'] as $name => $job) {
            $writes = ($job['permissions']['contents'] ?? 'read') === 'write';
            self::assertSame($name === 'publish', $writes, $name);
            if ($name === 'validate') {
                self::assertSame("github.ref != 'refs/heads/main'", $job['if']);
                self::assertSame(['contents' => 'read'], $job['permissions']);
            } else {
                self::assertStringContainsString("github.ref == 'refs/heads/main'", $job['if'], $name);
            }
        }

        $installerSource = file_get_contents(dirname(__DIR__, 3).'/scripts/cloud/vendor-bundle.sh');
        self::assertIsString($installerSource);
        self::assertStringContainsString('"$archive_digest" =~ ^sha256:[0-9a-f]{64}$', $installerSource);
        self::assertStringContainsString('["cloud-vendor/v3", "cloud-vendor/v4"]', $installerSource);
    }

    public function test_retained_bundle_refreshes_are_planned_from_main_ancestor_sources(): void
    {
        $process = new Process([
            'bash',
            dirname(__DIR__, 2).'/scripts/cloud-vendor-refresh-plan-test.sh',
        ]);
        $process->setTimeout(30);
        $process->run();

        self::assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
        self::assertStringContainsString('trusted main ancestors', $process->getOutput());
        self::assertStringContainsString('mutable release labels are not used', $process->getOutput());
        self::assertStringContainsString('does not require /dev/fd process substitution', $process->getOutput());
    }

    public function test_actions_artifact_pages_are_parsed_using_the_rest_response_shape(): void
    {
        $process = new Process([
            'bash',
            dirname(__DIR__, 2).'/scripts/cloud-vendor-artifact-meta-test.sh',
        ]);
        $process->setTimeout(30);
        $process->run();

        self::assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
        self::assertStringContainsString('top-level total_count', $process->getOutput());
    }

    public function test_release_asset_publication_preserves_the_canonical_bundle_until_replacement_is_verified(): void
    {
        $process = new Process([
            'bash',
            dirname(__DIR__, 2).'/scripts/cloud-vendor-publish-asset-test.sh',
        ]);
        $process->setTimeout(60);
        $process->run();

        self::assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
        self::assertStringContainsString('verified before the canonical asset moves', $process->getOutput());
        self::assertStringContainsString('API digest appears after update', $process->getOutput());
        self::assertStringContainsString('empty legacy digest and label metadata survive', $process->getOutput());
        self::assertStringContainsString('missing canonical asset is recovered', $process->getOutput());
        self::assertStringContainsString('errors retain their validation body', $process->getOutput());
        self::assertStringContainsString('restores the prior canonical asset', $process->getOutput());
        self::assertStringContainsString('uncertain backup rename is rolled back', $process->getOutput());
        self::assertStringContainsString('255-character limit', $process->getOutput());
    }

    public function test_cloud_vendor_helpers_use_the_preinstalled_php_instead_of_missing_jq(): void
    {
        foreach (['vendor-bundle-artifact-meta.sh', 'vendor-bundle-publish-asset.sh', 'vendor-bundle-refresh-plan.sh'] as $script) {
            $source = file_get_contents(dirname(__DIR__, 3).'/scripts/cloud/'.$script);

            self::assertIsString($source);
            self::assertDoesNotMatchRegularExpression('/(^|\s)jq(?:\s|$)/m', $source, "{$script} must run on the provisioned CodeBuild image.");
            self::assertStringContainsString('php ', $source);
        }
    }

    public function test_a_failed_vendor_swap_restores_the_previous_tree(): void
    {
        $process = new Process([
            'bash',
            dirname(__DIR__, 2).'/scripts/vendor-bundle-swap-test.sh',
        ]);
        $process->setTimeout(60);
        $process->run();

        self::assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
        self::assertStringContainsString('all vendor-bundle swap checks passed', $process->getOutput());
    }

    public function test_the_session_hook_installs_the_verified_bundle_before_cloud_repo_bootstrap(): void
    {
        $settings = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/.claude/settings.json'), true, flags: JSON_THROW_ON_ERROR);
        $command = $settings['hooks']['SessionStart'][0]['hooks'][0]['command'];

        self::assertIsString($command);
        $install = strpos($command, 'scripts/cloud/vendor-bundle.sh" install');
        $bootstrap = strpos($command, 'cloud-repo-bootstrap');
        self::assertNotFalse($install);
        self::assertNotFalse($bootstrap);
        self::assertLessThan($bootstrap, $install);

        $installer = (string) file_get_contents(dirname(__DIR__, 3).'/scripts/cloud/vendor-bundle.sh');
        self::assertStringContainsString('REPO="${CLOUD_VENDOR_REPO:-bherila/svc}"', $installer);
        self::assertStringContainsString('vendor/.cloud-repo-bootstrap', $installer);
    }
}
