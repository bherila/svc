import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { basename, dirname, join, resolve } from 'node:path';
import { after, before, test } from 'node:test';
import { fileURLToPath } from 'node:url';

import { compilerCases } from './react-compiler-fixtures.mjs';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const config = JSON.parse(readFileSync(join(root, '.oxlintrc.json'), 'utf8'));
const rules = new Set(compilerCases.map(({ rule }) => rule));
let directory;
let previous;
let current;

function lint(configuration, files, name) {
    const portable = structuredClone(configuration);

    for (const plugin of portable.jsPlugins) {
        if (plugin.specifier?.startsWith('.')) {
            plugin.specifier = resolve(root, plugin.specifier);
        }
    }

    const path = join(directory, `${name}.json`);
    writeFileSync(path, JSON.stringify(portable));
    const result = spawnSync(
        join(root, 'node_modules/.bin/oxlint'),
        ['--config', path, '--format', 'json', '--threads', '1', ...files],
        { cwd: root, encoding: 'utf8', timeout: 30_000 },
    );
    assert.ifError(result.error);
    assert.ok([0, 1].includes(result.status), result.stderr || result.stdout);
    const output = JSON.parse(result.stdout);
    assert.equal(output.number_of_files, files.length);

    return output.diagnostics;
}

function compilerDiagnostics(diagnostics, filename) {
    return diagnostics.filter((diagnostic) => {
        const match = /^(react|react-hooks-js)\((.+)\)$/.exec(diagnostic.code);

        return (
            match !== null &&
            rules.has(match[2]) &&
            basename(diagnostic.filename) === filename
        );
    });
}

// The native compiler can highlight a narrower expression or add secondary
// labels. Keep the primary source line and strongest severity when deduplicating.
function effectiveDiagnostics(diagnostics) {
    const effective = new Map();

    for (const diagnostic of diagnostics) {
        const rule = diagnostic.code.match(/\((.+)\)$/)[1];
        const key = `${rule}:${diagnostic.labels[0].span.line}`;

        if (effective.get(key) !== 'error') {
            effective.set(key, diagnostic.severity);
        }
    }

    return [...effective].sort(([a], [b]) => a.localeCompare(b));
}

before(() => {
    // Keep temporary files within the project so all JS plugins resolve exactly
    // as they do in lint:check. after() removes even a failed probe's files.
    directory = mkdtempSync(join(root, 'tools/oxlint/.react-compiler-'));
    const files = [];

    for (const { rule, invalid, valid } of compilerCases) {
        for (const [kind, source] of Object.entries({ invalid, valid })) {
            const path = join(directory, `${rule}.${kind}.tsx`);
            writeFileSync(path, source);
            files.push(path);
        }
    }

    // Reconstruct the pre-migration configuration: these native correctness
    // checks already ran, alongside JS duplicates. Unsupported syntax was JS only.
    const legacy = structuredClone(config);

    for (const rule of rules) {
        delete legacy.rules[`react/${rule}`];
        legacy.rules[`react-hooks-js/${rule}`] = [
            'incompatible-library',
            'unsupported-syntax',
        ].includes(rule)
            ? 'warn'
            : 'error';
    }

    previous = lint(legacy, files, 'previous');
    current = lint(config, files, 'current');
});

after(() => {
    if (directory) {
        rmSync(directory, { recursive: true, force: true });
    }
});

for (const { rule, invalid, location } of compilerCases) {
    void test(`${rule} retains coverage, severity and source location`, () => {
        const filename = `${rule}.invalid.tsx`;
        const oldDiagnostics = compilerDiagnostics(previous, filename);
        const newDiagnostics = compilerDiagnostics(current, filename);
        const legacy = oldDiagnostics.filter(
            (diagnostic) => diagnostic.code === `react-hooks-js(${rule})`,
        );
        assert.ok(legacy.length > 0, `Legacy fixture did not trigger ${rule}`);
        const native = newDiagnostics.filter(
            (diagnostic) => diagnostic.code === `react(${rule})`,
        );
        assert.ok(native.length > 0, `Native coverage missing for ${rule}`);

        for (const diagnostic of legacy) {
            const source = diagnostic.labels[0].span;
            assert.ok(
                native.some((replacement) =>
                    replacement.labels.some(
                        ({ span }) =>
                            span.offset < source.offset + source.length &&
                            source.offset < span.offset + span.length,
                    ),
                ),
                `Native diagnostic no longer points into the JS finding: ${rule}`,
            );
        }

        const line =
            invalid.split('\n').findIndex((text) => text.includes(location)) +
            1;
        assert.ok(
            native.some((diagnostic) =>
                diagnostic.labels.some(({ span }) => span.line === line),
            ),
            `Native diagnostic moved away from the invalid expression: ${rule}`,
        );
        assert.ok(
            native.every(
                ({ severity }) =>
                    severity ===
                    (rule === 'unsupported-syntax' ? 'warning' : 'error'),
            ),
        );
        assert.deepEqual(
            effectiveDiagnostics(newDiagnostics),
            effectiveDiagnostics(oldDiagnostics),
        );
        assert.ok(
            newDiagnostics.every(
                (diagnostic) => !diagnostic.code.startsWith('react-hooks-js('),
            ),
            `Duplicate JS compiler diagnostic remains for ${rule}`,
        );
    });

    void test(`${rule} accepts its valid control`, () => {
        const filename = `${rule}.valid.tsx`;
        assert.deepEqual(compilerDiagnostics(previous, filename), []);
        assert.deepEqual(compilerDiagnostics(current, filename), []);
    });
}

void test('JS configuration and gating validation remain enabled', () => {
    assert.equal(config.rules['react-hooks-js/config'], 'error');
    assert.equal(config.rules['react-hooks-js/gating'], 'error');
    assert.ok(
        config.jsPlugins.some(
            (plugin) =>
                plugin.name === 'react-hooks-js' &&
                plugin.specifier === 'eslint-plugin-react-hooks',
        ),
    );

    // Exercise the retained JS plugin: malformed dynamic gating identifiers
    // have no native replacement. The valid directive is a negative control.
    const files = [];

    for (const [kind, identifier] of [
        ['invalid', 'not a valid name'],
        ['valid', 'enabled'],
    ]) {
        const path = join(directory, `gating.${kind}.tsx`);
        writeFileSync(
            path,
            `export function Component() {
    "use memo if(${identifier})";
    return <div />;
}`,
        );
        files.push(path);
    }

    const gating = structuredClone(config);
    gating.rules['react-hooks-js/gating'] = [
        'error',
        {
            dynamicGating: { source: 'synthetic-feature-flags' },
        },
    ];
    const diagnostics = lint(gating, files, 'gating').filter(
        ({ code }) => code === 'react-hooks-js(gating)',
    );
    assert.equal(diagnostics.length, 1);
    assert.equal(basename(diagnostics[0].filename), 'gating.invalid.tsx');
    assert.equal(diagnostics[0].severity, 'error');
    assert.equal(diagnostics[0].labels[0].span.line, 2);
});
