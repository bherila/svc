import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { basename, dirname, join, resolve } from 'node:path';
import { after, before, test } from 'node:test';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const require = createRequire(import.meta.url);
const config = JSON.parse(readFileSync(join(root, '.oxlintrc.json'), 'utf8'));
const cases = [
    {
        name: 'brace-style',
        rule: '@stylistic(brace-style)',
        invalid: 'export function example() { return 1; }',
        valid: 'export function example() {\n    return 1;\n}',
    },
    {
        name: 'padding-line-between-statements',
        rule: '@stylistic(padding-line-between-statements)',
        invalid:
            'export function example(value) {\n    const next = value + 1;\n    return next;\n}',
        valid: 'export function example(value) {\n    const next = value + 1;\n\n    return next;\n}',
    },
    {
        name: 'import-groups',
        rule: 'import-js(order)',
        invalid:
            "import external from 'synthetic-external';\nimport { readFile } from 'node:fs';\nexport { external, readFile };",
        valid: "import { readFile } from 'node:fs';\nimport external from 'synthetic-external';\nexport { external, readFile };",
    },
    {
        name: 'import-alias',
        rule: 'import-js(order)',
        invalid:
            "import internal from '@/synthetic';\nimport external from 'synthetic-external';\nexport { external, internal };",
        valid: "import external from 'synthetic-external';\nimport internal from '@/synthetic';\nexport { external, internal };",
    },
    {
        name: 'import-case-insensitive-alphabetization',
        rule: 'import-js(order)',
        invalid:
            "import zulu from 'synthetic-Zulu';\nimport alpha from 'synthetic-alpha';\nexport { alpha, zulu };",
        valid: "import alpha from 'synthetic-alpha';\nimport zulu from 'synthetic-Zulu';\nexport { alpha, zulu };",
    },
    {
        name: 'import-relative-groups',
        rule: 'import-js(order)',
        invalid:
            "import index from './';\nimport sibling from './synthetic-sibling';\nimport parent from '../synthetic-parent';\nexport { index, parent, sibling };",
        valid: "import parent from '../synthetic-parent';\nimport sibling from './synthetic-sibling';\nimport index from './';\nexport { index, parent, sibling };",
    },
    {
        name: 'import-type-source-order',
        rule: 'import-js(order)',
        invalid:
            "import type { Zulu } from 'synthetic-zulu';\nimport type { Alpha } from 'synthetic-alpha';\nexport type { Alpha, Zulu };",
        valid: "import type { Alpha } from 'synthetic-alpha';\nimport type { Zulu } from 'synthetic-zulu';\nexport type { Alpha, Zulu };",
    },
];
let directory;
let diagnostics;

function lint(configuration, files, name, extra = []) {
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
        [
            '--config',
            path,
            '--format',
            'json',
            '--threads',
            '1',
            ...extra,
            ...files,
        ],
        { cwd: root, encoding: 'utf8', timeout: 30_000 },
    );
    assert.ifError(result.error);

    return result;
}

before(() => {
    directory = mkdtempSync(join(root, 'tools/oxlint/.eslint-free-'));
    const files = [];

    for (const { name, invalid, valid } of cases) {
        for (const [kind, source] of Object.entries({ invalid, valid })) {
            const path = join(directory, `${name}.${kind}.tsx`);
            writeFileSync(path, source);
            files.push(path);
        }
    }

    const result = lint(config, files, 'configured');
    assert.ok([0, 1].includes(result.status), result.stderr || result.stdout);
    const output = JSON.parse(result.stdout);
    assert.equal(output.number_of_files, files.length);
    diagnostics = output.diagnostics;
});

after(() => {
    if (directory) {
        rmSync(directory, { recursive: true, force: true });
    }
});

for (const { name, rule } of cases) {
    void test(`${name} still rejects invalid code and accepts its control`, () => {
        const matching = diagnostics.filter(({ code }) => code === rule);
        const invalid = matching.filter(
            ({ filename }) => basename(filename) === `${name}.invalid.tsx`,
        );
        assert.ok(invalid.length > 0, `Rule no longer runs: ${rule}`);
        assert.ok(invalid.every(({ severity }) => severity === 'error'));
        assert.deepEqual(
            matching.filter(
                ({ filename }) => basename(filename) === `${name}.valid.tsx`,
            ),
            [],
        );
    });
}

void test('import ordering preserves side effects and leaves named specifiers alone', () => {
    const source = `import 'synthetic-side-effect';
import { zulu, alpha } from 'synthetic-alpha';
import './synthetic-side-effect';
import beta from 'synthetic-beta';
export { alpha, beta, zulu };
`;
    const path = join(directory, 'side-effects.tsx');
    writeFileSync(path, source);
    const result = lint(config, [path], 'side-effects', ['--fix']);
    assert.ok([0, 1].includes(result.status), result.stderr || result.stdout);
    assert.deepEqual(
        JSON.parse(result.stdout).diagnostics.filter(
            ({ code }) => code === 'import-js(order)',
        ),
        [],
    );
    assert.equal(readFileSync(path, 'utf8'), source);
});

void test('the retained compiler config rule rejects invalid compiler options', () => {
    const path = join(directory, 'config.tsx');
    writeFileSync(
        path,
        'export function Component() {\n    return <div />;\n}',
    );
    const valid = lint(config, [path], 'config-valid');
    assert.ok([0, 1].includes(valid.status), valid.stderr || valid.stdout);
    assert.deepEqual(
        JSON.parse(valid.stdout).diagnostics.filter(
            ({ code }) => code === 'react-hooks-js(config)',
        ),
        [],
    );
    const invalid = structuredClone(config);
    invalid.rules['react-hooks-js/config'] = [
        'error',
        { target: 'unsupported' },
    ];
    const rejected = lint(invalid, [path], 'config-invalid');
    assert.notEqual(rejected.status, 0);
    assert.match(
        `${rejected.stdout}\n${rejected.stderr}`,
        /Not a valid target/,
    );
});

void test('ESLint is unavailable from every retained plugin dependency path', () => {
    const paths = [
        import.meta.url,
        require.resolve('@stylistic/eslint-plugin'),
        require.resolve('eslint-plugin-react-hooks'),
        require.resolve('eslint-plugin-import/lib/rules/order.js'),
    ];
    const stylistic = createRequire(paths[1]);
    paths.push(stylistic.resolve('@eslint-community/eslint-utils'));

    for (const path of paths) {
        const pluginRequire = createRequire(path);
        assert.throws(
            () => pluginRequire.resolve('eslint'),
            {
                code: 'MODULE_NOT_FOUND',
            },
            `ESLint is still resolvable from ${path}`,
        );
    }
});
