import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, join, resolve } from 'node:path';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const require = createRequire(import.meta.url);

void test('the locked dependency graph contains TypeScript 7 without legacy engines', () => {
    // Inspect package and snapshot keys, rather than peer declarations: retained
    // plugins mention optional ESLint peers and the generator accepts a legacy
    // TypeScript callback adapter, but neither engine should be installed.
    const lock = readFileSync(join(root, 'pnpm-lock.yaml'), 'utf8');
    const packages = [
        ...lock.matchAll(
            /^ {2}['"]?(eslint|typescript|@typescript\/typescript6)@([^'":\s]+)['"]?:$/gm,
        ),
    ];
    assert.ok(packages.length > 0, 'No compiler package records found');

    for (const [, name, version] of packages) {
        assert.equal(name, 'typescript', `Legacy engine is locked: ${name}`);
        assert.ok(
            Number.parseInt(version, 10) >= 7,
            `Legacy compiler is locked: ${version}`,
        );
    }
});

void test('API generation resolves TypeScript 7 and has no classic compiler adapter', () => {
    for (const context of [
        import.meta.url,
        require.resolve('openapi-typesafe'),
    ]) {
        const resolveFrom = createRequire(context);
        const manifest = JSON.parse(
            readFileSync(
                resolveFrom.resolve('typescript/package.json'),
                'utf8',
            ),
        );
        assert.ok(Number.parseInt(manifest.version, 10) >= 7);
        assert.throws(() => resolveFrom.resolve('@typescript/typescript6'), {
            code: 'MODULE_NOT_FOUND',
        });
    }
});
