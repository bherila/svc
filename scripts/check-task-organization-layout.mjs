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

if (existsSync(path.join(root, 'public/hot'))) {
    throw new Error('Stop Vite and remove its stale hot marker first.');
}

const runtime = mkdtempSync(
    path.join(tmpdir(), 'svc-task-organization-layout-'),
);
const artifacts = path.join(
    root,
    'test-results/task-organization-layout',
    path.basename(runtime),
);
mkdirSync(artifacts, { recursive: true });
const results = [];
const failures = [];
const logs = [];
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

try {
    for (const folder of [
        'framework/views',
        'framework/sessions',
        'framework/cache/data',
        'logs',
    ]) {
        mkdirSync(path.join(runtime, 'storage', folder), { recursive: true });
    }

    const listener = createServer();
    await new Promise((resolve, reject) =>
        listener.once('error', reject).listen(0, '127.0.0.1', resolve),
    );
    const port = listener.address().port;
    await new Promise((resolve) => listener.close(resolve));
    const host = `127.0.0.1:${port}`;
    const origin = `http://${host}`;
    const token = randomBytes(32).toString('hex');
    const headers = { 'X-SVC-Layout-Token': token };
    writeFileSync(
        path.join(runtime, 'harness.json'),
        JSON.stringify({ host, token }),
        { mode: 0o600 },
    );
    writeFileSync(path.join(runtime, '.env'), '', { mode: 0o600 });
    writeFileSync(path.join(runtime, 'database.sqlite'), '', { mode: 0o600 });
    // Credentials and remote database overrides are deliberately not inherited.
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
        TASK_ORGANIZATION_WRITES_ENABLED: 'true',
        SVC_LAYOUT_RUNTIME: runtime,
    };
    function run(command, args) {
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
    run('php', ['scripts/layout/task-organization/seed.php']);
    const fixture = JSON.parse(
        readFileSync(path.join(runtime, 'fixture.json'), 'utf8'),
    );

    if (process.argv.includes('--build')) {
        run('pnpm', ['run', 'build']);
    }

    if (!existsSync(path.join(root, 'public/build/manifest.json'))) {
        throw new Error(
            'Build this checkout before running the browser harness.',
        );
    }

    server = spawn(
        'php',
        [
            '-S',
            host,
            '-t',
            'public',
            'scripts/layout/task-organization/router.php',
        ],
        {
            cwd: root,
            env,
            stdio: ['ignore', 'pipe', 'pipe'],
        },
    );
    server.stdout.on('data', (data) => logs.push(data.toString()));
    server.stderr.on('data', (data) => logs.push(data.toString()));
    server.on('error', (error) => logs.push(error.message));
    const initial = new URL(fixture.pages.public_list, origin).href;
    await expect
        .poll(
            async () => {
                try {
                    return (await fetch(initial, { headers })).status;
                } catch {
                    return 0;
                }
            },
            { timeout: 15000 },
        )
        .toBe(200);
    expect((await fetch(initial)).status).toBe(403);
    expect(
        (await fetch(initial, { headers: { 'X-SVC-Layout-Token': 'wrong' } }))
            .status,
    ).toBe(403);
    expect(
        (await fetch(origin + '/up', { method: 'POST', headers })).status,
    ).toBe(403);
    expect((await fetch(initial, { method: 'DELETE', headers })).status).toBe(
        403,
    );
    const wrongHost = await new Promise((resolve, reject) => {
        request(
            initial,
            { headers: { ...headers, Host: 'example.test' } },
            (response) => {
                response.resume();
                resolve(response.statusCode);
            },
        )
            .on('error', reject)
            .end();
    });
    expect(wrongHost).toBe(403);

    if (process.argv.includes('--probe')) {
        const assetVersion = spawnSync(
            'php',
            ['-r', "echo hash_file('xxh128', 'public/build/manifest.json');"],
            { cwd: root, env, encoding: 'utf8' },
        );

        if (assetVersion.status !== 0 || !assetVersion.stdout.trim()) {
            throw new Error(
                'Cannot determine the actual Inertia asset version.',
            );
        }

        for (const href of Object.values(fixture.pages)) {
            const response = await fetch(new URL(href, origin), {
                headers: {
                    ...headers,
                    'X-Inertia': 'true',
                    'X-Inertia-Version': assetVersion.stdout.trim(),
                    Accept: 'application/json',
                },
            });
            expect(response.status).toBe(200);
            expect((await response.json()).component).toBe(
                'clients/task-organization',
            );
        }
    } else {
        browser = await chromium.launch({ headless: true });

        for (const width of [390, 820, 1440, 1920]) {
            const context = await browser.newContext({
                viewport: { width, height: 1080 },
                extraHTTPHeaders: headers,
            });
            await context.route('**/*', (route) =>
                new URL(route.request().url()).origin === origin
                    ? route.continue()
                    : route.abort(),
            );
            const page = await context.newPage();
            page.on('pageerror', (error) =>
                failures.push(`Browser error: ${error.message}`),
            );
            async function capture(state) {
                await page.evaluate(() => document.fonts.ready);
                await page.evaluate(() => window.scrollTo(0, 0));
                const metrics = await page.evaluate(() => {
                    const row =
                        document.querySelector('header')?.firstElementChild;
                    const children = row
                        ? [...row.children].filter(
                              (child) =>
                                  child.getBoundingClientRect().width > 0,
                          )
                        : [];
                    const tabs = document.querySelector(
                        'nav[aria-label="Client sections"]',
                    );
                    const selected = tabs?.querySelector(
                        '[aria-current="page"]',
                    );
                    const tabsBounds = tabs?.getBoundingClientRect();
                    const selectedBounds = selected?.getBoundingClientRect();
                    const selectedTabVisible =
                        !tabsBounds || tabsBounds.width === 0
                            ? true
                            : Boolean(
                                  selectedBounds &&
                                  selectedBounds.left >= tabsBounds.left - 1 &&
                                  selectedBounds.right <= tabsBounds.right + 1,
                              );

                    return {
                        documentWidth: document.documentElement.scrollWidth,
                        viewport: innerWidth,
                        navbarHeight:
                            row?.getBoundingClientRect().height ?? null,
                        selectedTabVisible,
                        centers: children.map((child) => {
                            const rect = child.getBoundingClientRect();

                            return rect.y + rect.height / 2;
                        }),
                    };
                });
                const passes =
                    metrics.documentWidth <= width &&
                    metrics.navbarHeight === 48 &&
                    metrics.selectedTabVisible &&
                    metrics.centers.length > 0 &&
                    Math.max(...metrics.centers) -
                        Math.min(...metrics.centers) <=
                        2;
                const screenshot = `${state}-${width}.png`;
                await page.screenshot({
                    path: path.join(artifacts, screenshot),
                    fullPage: true,
                    animations: 'disabled',
                });
                results.push({ state, width, ...metrics, passes, screenshot });

                if (!passes) {
                    failures.push(
                        `${state}/${width}: document overflow, navbar wrapped or selected tab clipped.`,
                    );
                }
            }

            if (!process.argv.includes('--assignee-seed')) {
                for (const [state, href] of Object.entries(fixture.pages)) {
                    await page.goto(new URL(href, origin).href);
                    await expect(page.locator('main')).toBeVisible();
                    await expect(
                        page.getByRole('button', { name: 'Current client' }),
                    ).toContainText(fixture.company_name);
                    await capture(state);
                }

                await page.goto(initial);
                await page
                    .getByRole('button', { name: 'New list', exact: true })
                    .click();
                await page
                    .getByLabel('List name', { exact: true })
                    .fill('Synthetic browser list');
                await capture('new-list-dialog');
                await page.keyboard.press('Escape');
                await expect(page.getByRole('dialog')).toHaveCount(0);
                await page
                    .getByRole('button', { name: 'Edit list', exact: true })
                    .first()
                    .click();
                await capture('edit-list-dialog');
                await page.keyboard.press('Escape');
                await expect(page.getByRole('dialog')).toHaveCount(0);
                await page
                    .getByRole('button', { name: 'New task', exact: true })
                    .click();
                await page
                    .getByLabel('Title', { exact: true })
                    .fill('Synthetic browser task');
                await page
                    .getByLabel('Description', { exact: true })
                    .fill('Synthetic task description '.repeat(25));
                await capture('new-task-dialog');
                await page.keyboard.press('Escape');
                await expect(page.getByRole('dialog')).toHaveCount(0);
                await page
                    .getByRole('button', { name: 'Archive list', exact: true })
                    .first()
                    .click();
                await capture('archive-list-dialog');
                await page.keyboard.press('Escape');
                await expect(page.getByRole('dialog')).toHaveCount(0);
            }

            const staffQueries = [];
            const staffPath = new URL(fixture.advisory_assignees_page, origin)
                .pathname;
            page.on('request', (request) => {
                const url = new URL(request.url());

                if (url.origin === origin && url.pathname === staffPath) {
                    staffQueries.push(url.searchParams.get('q'));
                }
            });
            await page.goto(new URL(fixture.advisory_task_page, origin).href);
            await expect(page.getByRole('checkbox')).toHaveCount(1);
            await page.waitForTimeout(350);
            expect(staffQueries).toEqual([]);
            await capture('initial-assignee-seed');
            const search = page.getByLabel('Find staff with project access');
            const staffResponse = page.waitForResponse((response) => {
                const actual = new URL(response.url());

                return (
                    actual.origin === origin &&
                    actual.pathname === staffPath &&
                    actual.searchParams.get('q') === 'Synthetic'
                );
            });
            await search.fill('Synthetic');
            expect((await staffResponse).status()).toBe(200);
            await expect(page.getByRole('checkbox')).toHaveCount(1);
            await search.fill('');
            await expect(page.getByRole('checkbox')).toHaveCount(1);
            await page.waitForTimeout(350);
            expect(staffQueries).toEqual(['Synthetic']);
            await page
                .getByRole('combobox', { name: 'Status', exact: true })
                .click();
            await page
                .getByRole('option', { name: 'Completed', exact: true })
                .click();
            await expect(
                page.getByText(
                    'This task has unfinished subtasks. Completing it leaves those subtasks unchanged.',
                    { exact: true },
                ),
            ).toBeVisible();
            await capture('completion-advisory');
            await context.close();
        }
    }

    run('php', ['scripts/layout/task-organization/verify.php']);
} catch (error) {
    failures.push(error.stack ?? String(error));
} finally {
    await browser?.close();
    server?.kill();
    rmSync(runtime, { recursive: true, force: true });
    writeFileSync(path.join(artifacts, 'server.log'), logs.join(''));
    writeFileSync(
        path.join(artifacts, 'results.json'),
        JSON.stringify({ results, failures }, null, 2),
    );
    writeFileSync(
        path.join(artifacts, 'report.md'),
        [
            '# Task organization browser evidence',
            '',
            'Disposable synthetic fixtures rendered by the actual native presenter. No production data or credentials.',
            '',
            '| State | Viewport | Document | Navbar | Gate | Screenshot |',
            '| --- | --- | --- | --- | --- | --- |',
            ...results.map(
                (row) =>
                    `| ${row.state} | ${row.width} | ${row.documentWidth} | ${row.navbarHeight} | ${row.passes ? 'pass' : 'FAIL'} | [image](${row.screenshot}) |`,
            ),
            '',
            'Every screenshot requires manual overlap/clipping review before layout completion.',
            '',
            '## Failures',
            '',
            ...(failures.length ? failures : ['None.']),
            '',
        ].join('\n'),
    );
    console.log(`Layout report: ${path.relative(root, artifacts)}/report.md`);

    if (failures.length) {
        console.error(failures.join('\n'));
        process.exitCode = 1;
    }
}
