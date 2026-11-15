import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync, realpathSync } from 'node:fs';
import { join } from 'node:path';

export function assertPackageIdentity(manifest) {
    assert.notEqual(manifest.name, 'eslint', 'ESLint engine is installed.');
    assert.ok(
        !/^@typescript\/(?:typescript[0-6]|old)$/.test(manifest.name),
        `Classic compiler is installed: ${manifest.name}`,
    );

    if (manifest.name === 'typescript') {
        assert.ok(
            Number.parseInt(manifest.version, 10) >= 7,
            `Classic compiler is installed: typescript@${manifest.version}`,
        );

        return manifest.version;
    }

    return null;
}

export function assertLockIdentities(lock) {
    // Match resolved package/snapshot keys, never optional peer-range mentions.
    const identities = [...lock.matchAll(/^ {2}['"]?([^\s'"]+?)['"]?:/gm)].map(
        (match) => match[1],
    );
    let nativeCompiler = false;

    for (const identity of identities) {
        const match =
            /^(eslint|typescript|@typescript\/(?:typescript[0-6]|old))@(.+)$/.exec(
                identity,
            );

        if (!match) {
            continue;
        }

        const version = assertPackageIdentity({
            name: match[1],
            version: match[2],
        });

        if (version) {
            nativeCompiler = true;
        }
    }

    assert.ok(nativeCompiler, 'No native compiler package records found.');
}

export function inspectActiveGraph(graph) {
    const seen = new Map();
    const compilers = new Set();
    function walk(node, optional = false) {
        assert.ok(node.path, 'A reachable package has no installed path.');
        let directory;

        try {
            directory = realpathSync(node.path);
        } catch (error) {
            if (optional && error.code === 'ENOENT') {
                return;
            }

            throw error;
        }

        let manifest = seen.get(directory);

        if (!manifest) {
            // Aliases and hoisting can hide a compiler behind another dependency name.
            manifest = JSON.parse(
                readFileSync(join(directory, 'package.json'), 'utf8'),
            );
            seen.set(directory, manifest);
            const version = assertPackageIdentity(manifest);

            if (version) {
                compilers.add(version);
            }
        }

        for (const field of [
            'dependencies',
            'devDependencies',
            'optionalDependencies',
        ]) {
            for (const [name, dependency] of Object.entries(
                node[field] ?? {},
            )) {
                // pnpm flattens optional edges; check the actual parent's declaration.
                walk(
                    dependency,
                    field === 'optionalDependencies' ||
                        Object.hasOwn(
                            manifest.optionalDependencies ?? {},
                            name,
                        ),
                );
            }
        }
    }

    for (const project of graph) {
        walk(project);
    }

    assert.ok(compilers.size > 0, 'No native compiler is actually installed.');

    return seen.size;
}

export function inspectToolchain(root) {
    const graph = JSON.parse(
        execFileSync('pnpm', ['list', '--depth', 'Infinity', '--json'], {
            cwd: root,
            encoding: 'utf8',
            maxBuffer: 16 * 1024 * 1024,
        }),
    );
    assertLockIdentities(readFileSync(join(root, 'pnpm-lock.yaml'), 'utf8'));

    return inspectActiveGraph(graph);
}
