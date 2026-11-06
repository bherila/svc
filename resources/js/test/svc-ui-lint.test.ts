import { spawnSync } from 'node:child_process';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { afterAll, describe, expect, it } from 'vitest';

/**
 * The `svc-ui` oxlint rules actually fire. The rules are scoped to
 * `resources/js/pages/**` in `.oxlintrc.json`; this runs them against a
 * throwaway config that applies them everywhere, so a rule that silently stops
 * matching fails here rather than letting hand-drawn cards back in.
 */
const ROOT = join(__dirname, '..', '..', '..');
const dir = mkdtempSync(join(tmpdir(), 'svc-ui-lint-'));

afterAll(() => rmSync(dir, { recursive: true, force: true }));

function lint(source: string): string {
    writeFileSync(
        join(dir, '.oxlintrc.json'),
        JSON.stringify({
            jsPlugins: [
                {
                    name: 'svc-ui',
                    specifier: join(ROOT, 'tools/oxlint/svc-ui.mjs'),
                },
            ],
            rules: {
                'svc-ui/no-hand-rolled-box': 'error',
                'svc-ui/no-button-link-in-card': 'error',
            },
        }),
    );
    writeFileSync(join(dir, 'fixture.tsx'), source);

    const result = spawnSync(
        join(ROOT, 'node_modules/.bin/oxlint'),
        ['-c', join(dir, '.oxlintrc.json'), join(dir, 'fixture.tsx')],
        { encoding: 'utf8', cwd: dir },
    );

    return `${result.stdout}${result.stderr}`;
}

describe('svc-ui lint rules', () => {
    it('flags a border, radius and padding on a raw element', () => {
        expect(
            lint(
                `export const A = () => <div className="rounded-lg border p-4">x</div>;`,
            ),
        ).toContain('no-hand-rolled-box');
        expect(
            lint(
                `export const A = () => <section className={cn('grid gap-3', 'rounded-xl border border-border p-5')}>x</section>;`,
            ),
        ).toContain('no-hand-rolled-box');
    });

    it('allows the shapes that are not cards', () => {
        const clean = lint(`
            export const A = () => (
                <div>
                    <div className="border-b pb-4">rule</div>
                    <span className="rounded-md border px-2 py-0.5 text-xs">badge</span>
                    <select className="h-9 rounded-md border bg-background px-3" />
                    <div className="rounded-full bg-border p-3" />
                    <Card className="rounded-lg border p-4" />
                </div>
            );
        `);

        expect(clean).not.toContain('no-hand-rolled-box');
    });

    it('flags a buttonVariants Link inside CardContent only', () => {
        const inside = lint(`
            export const A = () => (
                <CardContent>
                    <Link href="/x" className={buttonVariants({ variant: 'outline' })}>Go</Link>
                </CardContent>
            );
        `);
        const outside = lint(`
            export const A = () => (
                <CardHeader>
                    <Link href="/x" className={buttonVariants({ variant: 'outline' })}>Go</Link>
                </CardHeader>
            );
        `);

        expect(inside).toContain('no-button-link-in-card');
        expect(outside).not.toContain('no-button-link-in-card');
    });
});
