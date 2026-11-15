import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import {
    mkdtempSync,
    mkdirSync,
    readFileSync,
    rmSync,
    writeFileSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { test } from 'node:test';
import { assertLockIdentities, inspectActiveGraph } from './toolchain.mjs';

const legacy = readFileSync(
    new URL('./fixtures/legacy-toolchain.mjs', import.meta.url),
    'utf8',
);
const nativeLock = 'packages:\n  typescript@7.0.2:\n    resolution: {}\n';

function fixture(run) {
    const root = mkdtempSync(join(tmpdir(), 'svc-toolchain-'));
    function packageAt(path, identity) {
        mkdirSync(path, { recursive: true });
        writeFileSync(join(path, 'package.json'), JSON.stringify(identity));

        return { path };
    }

    try {
        const project = packageAt(root, {
            name: 'synthetic-app',
            private: true,
        });
        const compiler = packageAt(join(root, 'node_modules/typescript'), {
            name: 'typescript',
            version: '7.0.2',
        });
        project.devDependencies = { typescript: compiler };
        const generator = packageAt(
            join(root, 'node_modules/openapi-typesafe'),
            { name: 'openapi-typesafe', version: '1.0.0', main: 'index.cjs' },
        );
        writeFileSync(
            join(generator.path, 'index.cjs'),
            'module.exports = {};',
        );
        project.devDependencies['openapi-typesafe'] = generator;
        const testPath = join(root, 'tools/oxlint/toolchain.test.mjs');
        mkdirSync(dirname(testPath), { recursive: true });
        writeFileSync(testPath, legacy);
        function oldGuard(lock) {
            writeFileSync(join(root, 'pnpm-lock.yaml'), lock);
            const env = { ...process.env };
            delete env.NODE_TEST_CONTEXT;
            const result = spawnSync(process.execPath, ['--test', testPath], {
                cwd: root,
                env,
                encoding: 'utf8',
                timeout: 10_000,
            });
            assert.ifError(result.error);

            return result;
        }
        function dependency(alias, identity) {
            const node = packageAt(join(root, 'node_modules', alias), identity);
            project.devDependencies[alias] = node;

            return node;
        }
        run({ root, project, compiler, dependency, oldGuard });
    } finally {
        rmSync(root, { recursive: true, force: true });
    }
}

void test('the unchanged old guard passes an aliased @typescript/old fixture, and both new guards reject it', () => {
    fixture(({ project, dependency, oldGuard }) => {
        const clean = oldGuard(nativeLock);
        assert.equal(clean.status, 0, clean.stdout + clean.stderr);
        assert.match(clean.stdout, /pass 2/);
        dependency('compat-compiler', {
            name: '@typescript/old',
            version: '6.0.3',
        });
        const poisoned =
            nativeLock + "  '@typescript/old@6.0.3':\n    resolution: {}\n";
        const escaped = oldGuard(poisoned);
        assert.equal(escaped.status, 0, escaped.stdout + escaped.stderr);
        assert.match(escaped.stdout, /pass 2/);
        assert.throws(() => assertLockIdentities(poisoned), /Classic compiler/);
        assert.throws(() => inspectActiveGraph([project]), /Classic compiler/);
        // A direct classic compiler is the historical negative control.
        const rejected = oldGuard(
            nativeLock + '  typescript@6.0.3:\n    resolution: {}\n',
        );
        assert.notEqual(rejected.status, 0);
        assert.match(rejected.stdout, /Legacy compiler is locked/);
    });
});

for (const [alias, name, version] of [
    ['typescript6', 'typescript', '6.0.3'],
    ['compat-compiler', 'typescript', '6.0.3'],
    ['compat-adapter', '@typescript/typescript6', '6.0.3'],
    ['compat-old', '@typescript/old', '6.0.3'],
    ['eslint', 'eslint', '10.14.0'],
    ['harmless-rules', 'eslint', '10.14.0'],
]) {
    void test(`rejects the actual ${name}@${version} identity under ${alias}`, () => {
        fixture(({ project, dependency }) => {
            dependency(alias, { name, version });
            assert.throws(
                () => inspectActiveGraph([project]),
                /compiler|ESLint/,
            );

            for (const quote of ['', "'", '"']) {
                assert.throws(
                    () =>
                        assertLockIdentities(
                            nativeLock +
                                `  ${quote}${name}@${version}${quote}: {}\n`,
                        ),
                    /compiler|ESLint/,
                );
            }
        });
    });
}

void test('native packages, pure rule/type packages and optional peer mentions remain valid', () => {
    fixture(({ project, dependency }) => {
        dependency('rules', {
            name: 'eslint-plugin-react-hooks',
            version: '7.1.1',
            peerDependencies: { eslint: '^9', typescript: '^6' },
        });
        dependency('types', {
            name: '@typescript-eslint/types',
            version: '8.72.1',
        });
        assert.equal(inspectActiveGraph([project]), 5);
        assert.doesNotThrow(() =>
            assertLockIdentities(
                nativeLock +
                    "  'eslint-plugin-react-hooks@7.1.1':\n    peerDependencies:\n      eslint: ^9\n      typescript: ^6\n",
            ),
        );
    });
});

void test('unreachable cache entries do not contaminate the active graph', () => {
    fixture(({ root, project }) => {
        const cache = join(root, 'unrelated-shared-store/eslint');
        mkdirSync(cache, { recursive: true });
        writeFileSync(
            join(cache, 'package.json'),
            JSON.stringify({ name: 'eslint', version: '10.14.0' }),
        );
        assert.equal(inspectActiveGraph([project]), 3);
    });
});

void test('only missing optional platform edges are skipped, including pnpm-flattened edges', () => {
    fixture(({ root, project, compiler, dependency }) => {
        const missing = { path: join(root, 'missing-platform') };
        writeFileSync(
            join(compiler.path, 'package.json'),
            JSON.stringify({
                name: 'typescript',
                version: '7.0.2',
                optionalDependencies: { platform: '7.0.2' },
            }),
        );
        compiler.dependencies = { platform: missing };
        project.optionalDependencies = { anotherPlatform: missing };
        assert.equal(inspectActiveGraph([project]), 3);
        project.devDependencies.required = missing;
        assert.throws(() => inspectActiveGraph([project]), /ENOENT/);
        delete project.devDependencies.required;
        const bad = dependency('optional-compiler', {
            name: '@typescript/old',
            version: '6.0.3',
        });
        delete project.devDependencies['optional-compiler'];
        project.optionalDependencies.installed = bad;
        assert.throws(() => inspectActiveGraph([project]), /Classic compiler/);
    });
});

void test('native compiler presence and readable required manifests are mandatory', () => {
    assert.throws(
        () => assertLockIdentities('packages:\n  some-plugin@1.0.0: {}\n'),
        /No native compiler/,
    );
    assert.throws(() => inspectActiveGraph([]), /No native compiler/);
    assert.throws(
        () => inspectActiveGraph([{ name: 'compiler', version: '7.0.2' }]),
        /no installed path/,
    );
    fixture(({ compiler, project }) => {
        rmSync(join(compiler.path, 'package.json'));
        assert.throws(() => inspectActiveGraph([project]), /ENOENT/);
    });
});
