#!/usr/bin/env node
/* global process */

import { spawn, spawnSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import {
    existsSync,
    mkdirSync,
    mkdtempSync,
    readFileSync,
    rmSync,
    writeFileSync,
} from 'node:fs';
import { request } from 'node:http';
import { createServer } from 'node:net';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium, expect } from '@playwright/test';

const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)));

if (existsSync(path.join(root, 'public', 'hot'))) {
    throw new Error('Stop the Vite dev server and remove its stale public/hot marker before running the layout harness.');
}

const runtime = mkdtempSync(path.join(tmpdir(), 'svc-layout-'));
const artifacts = path.join(
    root,
    'test-results',
    'layout',
    path.basename(runtime),
);
const widths = [390, 820, 1440, 1920];
const results = [];
const failures = [];
const serverLog = [];
let server;
let browser;

for (const signal of ['SIGINT', 'SIGTERM']) {
    process.once(signal, async () => {
        server?.kill();
        await browser?.close();
        rmSync(runtime, { recursive: true, force: true });
        process.exit(signal === 'SIGINT' ? 130 : 143);
    });
}

function run(command, args, env) {
    const result = spawnSync(command, args, {
        cwd: root,
        env,
        stdio: 'inherit',
    });

    if (result.status !== 0) {
        throw new Error(
            `${command} failed (${result.status ?? result.error?.message}).`,
        );
    }
}

async function portOnLoopback() {
    const listener = createServer();
    await new Promise((resolve, reject) =>
        listener.once('error', reject).listen(0, '127.0.0.1', resolve),
    );
    const port = listener.address().port;
    await new Promise((resolve) => listener.close(resolve));

    return port;
}

try {
    mkdirSync(artifacts, { recursive: true });

    for (const directory of [
        'framework/views',
        'framework/sessions',
        'framework/cache/data',
        'logs',
    ]) {
        mkdirSync(path.join(runtime, 'storage', directory), {
            recursive: true,
        });
    }

    const port = await portOnLoopback();
    const host = `127.0.0.1:${port}`;
    const origin = `http://${host}`;
    const token = randomBytes(32).toString('hex');
    const headers = { 'X-SVC-Layout-Token': token };
    writeFileSync(
        path.join(runtime, 'harness.json'),
        JSON.stringify({ host, token }),
        { mode: 0o600 },
    );
    writeFileSync(path.join(runtime, 'database.sqlite'), '', { mode: 0o600 });
    writeFileSync(path.join(runtime, '.env'), '');

    // Do not inherit application credentials, DB URLs, or runtime overrides.
    // HOME/PATH are only for locating installed PHP, pnpm and build tooling.
    const env = {
        PATH: process.env.PATH,
        HOME: process.env.HOME,
        TMPDIR: runtime,
        APP_ENV: 'testing',
        APP_DEBUG: 'true',
        APP_KEY: `base64:${randomBytes(32).toString('base64')}`,
        APP_URL: origin,
        APP_CONFIG_CACHE: path.join(runtime, 'config.php'),
        DB_CONNECTION: 'sqlite',
        DB_DATABASE: path.join(runtime, 'database.sqlite'),
        SESSION_DRIVER: 'file',
        SESSION_SECURE_COOKIE: 'false',
        CACHE_STORE: 'array',
        MAIL_MAILER: 'array',
        QUEUE_CONNECTION: 'sync',
        SVC_LAYOUT_RUNTIME: runtime,
    };
    run('php', ['scripts/layout/seed.php'], env);
    const fixture = JSON.parse(
        readFileSync(path.join(runtime, 'fixture.json'), 'utf8'),
    );
    run('pnpm', ['run', 'build'], env);
    server = spawn(
        'php',
        ['-S', host, '-t', 'public', 'scripts/layout/router.php'],
        {
            cwd: root,
            env,
            stdio: ['ignore', 'pipe', 'pipe'],
        },
    );
    server.stdout.on('data', (data) => serverLog.push(data.toString()));
    server.stderr.on('data', (data) => serverLog.push(data.toString()));
    server.on('error', (error) => serverLog.push(error.message));
    await expect
        .poll(
            async () => {
                try {
                    return (await fetch(`${origin}/up`, { headers })).status;
                } catch {
                    return 0;
                }
            },
            { timeout: 15000 },
        )
        .toBe(200);

    // These refusals exercise the real router before any browser authentication.
    expect((await fetch(`${origin}/up`)).status).toBe(403);
    expect(
        (
            await fetch(`${origin}/up`, {
                headers: { 'X-SVC-Layout-Token': 'wrong' },
            })
        ).status,
    ).toBe(403);
    expect(
        (await fetch(`${origin}/up`, { method: 'POST', headers })).status,
    ).toBe(403);
    // fetch normalizes Host back to the URL, so use a raw HTTP request here.
    const wrongHostStatus = await new Promise((resolve, reject) => {
        request(
            `${origin}/up`,
            { headers: { ...headers, Host: 'example.test' } },
            (response) => {
                response.resume();
                resolve(response.statusCode);
            },
        )
            .on('error', reject)
            .end();
    });
    expect(wrongHostStatus).toBe(403);

    browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({ extraHTTPHeaders: headers });
    await context.route('**/*', (route) => {
        return new URL(route.request().url()).origin === origin
            ? route.continue()
            : route.abort();
    });
    const page = await context.newPage();
    page.on('pageerror', (error) =>
        failures.push(`Browser error: ${error.message}`),
    );

    async function capture(screen, state, width, measuredOnly = false) {
        await page.evaluate(() => document.fonts.ready);
        await page.evaluate(() => window.scrollTo(0, 0));
        const metrics = await page.evaluate(() => {
            const header = document.querySelector('header');
            const row = header?.firstElementChild;
            const rect = row?.getBoundingClientRect();

            return {
                viewport: window.innerWidth,
                documentWidth: document.documentElement.scrollWidth,
                navbarHeight: rect?.height ?? null,
                navbarChildCenters: row
                    ? Array.from(row.children)
                          .map((child) => {
                              const bounds = child.getBoundingClientRect();

                              return bounds.width && bounds.height
                                  ? bounds.top + bounds.height / 2
                                  : null;
                          })
                          .filter((center) => center !== null)
                    : [],
            };
        });
        const centers = metrics.navbarChildCenters;
        const passes =
            metrics.documentWidth === width &&
            metrics.navbarHeight === 48 &&
            centers.length > 0 &&
            Math.max(...centers) - Math.min(...centers) <= 1;
        const screenshot = `${screen}-${state}-${width}.png`;
        await page.screenshot({
            path: path.join(artifacts, screenshot),
            fullPage: true,
        });
        results.push({
            screen,
            state,
            width,
            measuredOnly,
            passes,
            ...metrics,
            screenshot,
        });

        if (!passes && !measuredOnly) {
            failures.push(
                `${screen}/${state} at ${width}px: overflow or navbar no longer one row.`,
            );
        }
    }

    for (const width of widths) {
        await page.setViewportSize({ width, height: 1000 });

        for (const screen of ['invoice', 'proposal', 'proposal_acceptance', 'operations', 'time', 'expense_schedules']) {
            const response = await page.goto(origin + fixture[screen]);
            expect(response.status()).toBe(200);
            await expect(page.locator('main')).toBeVisible().catch(async (error) => {
                writeFileSync(path.join(artifacts, `${screen}-failure.html`), await page.content());
                await page.screenshot({ path: path.join(artifacts, `${screen}-failure.png`), fullPage: true });

                throw error;
            });

            if (screen === 'proposal_acceptance') {
                await expect(page.locator('#signer-name')).toBeVisible();
                await expect(page.locator('#signer-title')).toBeVisible();
                await expect(page.getByRole('button', { name: 'Accept', exact: true })).toBeVisible();
                await page.locator('#signer-name').fill('SyntheticLayoutSigner'.repeat(8));
                await page.locator('#signer-title').fill('SyntheticLayoutTitle'.repeat(8));
            }

            await capture(screen, 'initial', width, screen === 'operations');

            if (screen === 'expense_schedules') {
                await page.getByRole('button', { name: 'Edit schedule', exact: true }).click();
                await capture(screen, 'edit', width);
                await page.getByRole('button', { name: 'Cancel', exact: true }).click();
                await page.getByRole('button', { name: 'New schedule', exact: true }).click();
                await page.getByLabel('Description', { exact: true }).fill('SyntheticUnbrokenRecurringExpense'.repeat(8));
                await capture(screen, 'create', width);
            }

            if (screen === 'time') {
                await page.getByRole('button', { name: 'Select time to invoice', exact: true }).click();
                const checkboxes = page.getByRole('checkbox', { name: / for invoice$/ });
                await expect(checkboxes).toHaveCount(2);
                await checkboxes.nth(0).check();
                await checkboxes.nth(1).check();
                await capture(screen, 'selection', width);
                await page.getByRole('button', { name: 'Review draft invoice', exact: true }).click();
                const dialog = page.getByRole('dialog');
                await expect(dialog).toBeVisible();
                await expect(dialog).toHaveCSS('opacity', '1');
                await dialog.evaluate((element) => Promise.all(element.getAnimations().map((animation) => animation.finished.catch(() => {}))));
                await expect(dialog.getByText('Draft total: $280.75', { exact: true })).toBeVisible();
                await dialog.evaluate((element) => {
 element.scrollTop = 0;
});
                await capture(screen, 'invoice-preview', width);
                await page.getByLabel('Invoice number', { exact: true }).fill('SYN-SELECTED-'.repeat(6));
                await page.getByLabel('Notes (optional)', { exact: true }).fill('Synthetic review notes '.repeat(12));
                await page.getByRole('button', { name: 'Create draft invoice', exact: true }).scrollIntoViewIfNeeded();
                await capture(screen, 'invoice-fields', width);
                // This GET-only harness inspects the form, never submits it.
                await page.getByRole('button', { name: 'Cancel', exact: true }).click();
            }

            if (screen === 'invoice') {
                await page
                    .getByRole('button', {
                        name: 'Record payment',
                        exact: true,
                    })
                    .click();
                await expect(
                    page.locator('#payment-received-on'),
                ).toBeVisible();
                await page.locator('#payment-received-on').fill(fixture.date);
                await capture(screen, 'payment', width);
                await page
                    .getByRole('button', { name: 'Correct date', exact: true })
                    .click();
                await expect(
                    page.getByLabel(/^Corrected date for/),
                ).toBeVisible();
                await capture(screen, 'correction', width);
            }
        }
    }
} catch (error) {
    failures.push(error.stack ?? String(error));
} finally {
    await browser?.close();
    server?.kill();
    rmSync(runtime, { recursive: true, force: true });
    writeFileSync(path.join(artifacts, 'server.log'), serverLog.join(''));
    writeFileSync(
        path.join(artifacts, 'results.json'),
        JSON.stringify({ results, failures }, null, 2),
    );
    const rows = results.map(
        (row) =>
            `| ${row.screen}/${row.state} | ${row.width} | ${row.documentWidth} | ${row.navbarHeight} | ${row.measuredOnly ? 'measurement only' : row.passes ? 'pass' : 'FAIL'} | [image](${row.screenshot}) |`,
    );
    writeFileSync(
        path.join(artifacts, 'report.md'),
        [
            '# Browser layout evidence',
            '',
            'Synthetic local fixtures only. Invoice/proposal/time are asserted; operations is a bounded measurement of known pre-existing layout problems.',
            '',
            '| Page/state | Viewport | Document width | Navbar height | Gate | Screenshot |',
            '| --- | --- | --- | --- | --- | --- |',
            ...rows,
            '',
            '## Manual screenshot review',
            '',
            'Required: inspect every screenshot for overlap, clipping and dead space before calling a layout complete. Numeric checks do not establish this.',
            '',
            '## Failures',
            '',
            ...(failures.length ? failures : ['None in the asserted pages.']),
            '',
        ].join('\n'),
    );
    console.log(`Layout report: ${path.relative(root, artifacts)}/report.md`);

    if (failures.length) {
        console.error(failures.join('\n'));
        process.exitCode = 1;
    }
}
