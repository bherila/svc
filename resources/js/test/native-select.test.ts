import { readdirSync, readFileSync, statSync } from 'node:fs';
import path from 'node:path';
import { expect, test } from 'vitest';

const root = path.resolve(__dirname, '..');

function sources(directory: string): string[] {
    return readdirSync(directory).flatMap((entry) => {
        const full = path.join(directory, entry);

        if (statSync(full).isDirectory()) {
            return entry === 'wayfinder' || entry === 'actions'
                ? []
                : sources(full);
        }

        return /\.tsx$/.test(entry) && !/\.test\.tsx$/.test(entry)
            ? [full]
            : [];
    });
}

/**
 * Every choice from a list is the shared `Select` (through `OptionSelect`),
 * never a native `<select>`: the native control draws with the browser's own
 * palette and ignores the theme tokens, so it is unreadable in one of the two
 * themes, and it prints its stored value wherever `items` was forgotten. The
 * check is on the JSX element, so a comment that names the tag is allowed.
 */
test('no native <select> is rendered anywhere in resources/js', () => {
    const offenders = sources(root).filter((file) =>
        /<select[\s>]/.test(
            readFileSync(file, 'utf8')
                .replace(/\/\*[\s\S]*?\*\//g, '')
                .replace(/^\s*\/\/.*$/gm, ''),
        ),
    );

    expect(offenders.map((file) => path.relative(root, file))).toEqual([]);
});
