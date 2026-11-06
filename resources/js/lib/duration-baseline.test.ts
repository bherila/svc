import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';
import { describe, expect, it } from 'vitest';

/**
 * Durations go through `lib/duration.ts`. These raw minutes-to-hours
 * conversions predate it and are not migrated yet; the list may only shrink.
 * A new `/ 60` anywhere else in `resources/js` fails here - use
 * `formatDuration` instead.
 */
const BASELINE: Record<string, number> = {
    'resources/js/components/agreements/agreement-editor.tsx': 1,
    'resources/js/lib/time.ts': 1,
};

const ROOT = join(__dirname, '..', '..', '..');
const EXEMPT = new Set(['resources/js/lib/duration.ts']);
const GENERATED = new Set(['actions', 'routes', 'wayfinder']);

function sources(dir: string): string[] {
    return readdirSync(dir).flatMap((name) => {
        const path = join(dir, name);

        if (statSync(path).isDirectory()) {
            return GENERATED.has(name) ? [] : sources(path);
        }

        return /\.(ts|tsx)$/.test(name) &&
            !/\.test\.(ts|tsx)$/.test(name) &&
            !name.endsWith('.generated.ts')
            ? [path]
            : [];
    });
}

describe('raw hour conversions', () => {
    it('match the recorded baseline', () => {
        const found: Record<string, number> = {};

        for (const path of sources(join(ROOT, 'resources/js'))) {
            const rel = relative(ROOT, path);

            if (EXEMPT.has(rel)) {
                continue;
            }

            const count = (
                readFileSync(path, 'utf8').match(/\/\s*60\b(?!\s*[*/])/g) ?? []
            ).length;

            if (count > 0) {
                found[rel] = count;
            }
        }

        expect(found).toEqual(BASELINE);
    });
});
