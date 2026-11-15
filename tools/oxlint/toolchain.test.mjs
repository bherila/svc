import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, join, resolve } from 'node:path';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { assertLockIdentities, inspectToolchain } from './toolchain.mjs';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const require = createRequire(import.meta.url);

void test('the locked dependency graph contains TypeScript 7 without legacy engines', () => {
    assertLockIdentities(readFileSync(join(root, 'pnpm-lock.yaml'), 'utf8'));
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

void test('every reachable installed identity is free of classic compilers and ESLint', () => {
    const count = inspectToolchain(root);
    console.log(
        `Toolchain contract: ${count} reachable package manifests inspected; native TypeScript only, no ESLint engine/classic compiler.`,
    );
});
