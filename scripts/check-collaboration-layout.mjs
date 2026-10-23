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
import { createServer } from 'node:net';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium, expect } from '@playwright/test';

const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)));

if (existsSync(path.join(root, 'public/hot'))) {
    throw new Error('Stop Vite before running browser checks.');
}

const runtime = mkdtempSync(path.join(tmpdir(), 'svc-collaboration-layout-'));
const artifacts = path.join(
    root,
    'test-results/collaboration-layout',
    path.basename(runtime),
);
mkdirSync(artifacts, { recursive: true });
let server;
let browser;
const logs = [];

try {
    for (const folder of [
        'framework/views',
        'framework/sessions',
        'framework/cache/data',
        'logs',
        'app/private',
    ]) {
        mkdirSync(path.join(runtime, 'storage', folder), { recursive: true });
    }

    const listener = createServer();
    await new Promise((resolve) => listener.listen(0, '127.0.0.1', resolve));
    const port = listener.address().port;
    await new Promise((resolve) => listener.close(resolve));
    const host = `127.0.0.1:${port}`;
    const origin = `http://${host}`;
    const token = randomBytes(32).toString('hex');
    writeFileSync(
        path.join(runtime, 'harness.json'),
        JSON.stringify({ host, token }),
        { mode: 0o600 },
    );
    writeFileSync(path.join(runtime, '.env'), '');
    writeFileSync(path.join(runtime, 'database.sqlite'), '');
    const env = {
        PATH: process.env.PATH,
        HOME: process.env.HOME,
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
    function run(command, args) {
        const result = spawnSync(command, args, {
            cwd: root,
            env,
            stdio: 'inherit',
        });

        if (result.status !== 0) {
            throw new Error(`${command} failed: ${result.status}`);
        }
    }
    run('php', ['scripts/layout/collaboration-seed.php']);
    const fixture = JSON.parse(
        readFileSync(path.join(runtime, 'fixture.json'), 'utf8'),
    );

    if (!process.argv.includes('--skip-build')) {
        run('pnpm', ['run', 'build']);
    }

    writeFileSync(
        path.join(runtime, 'router.php'),
        `<?php
$runtime = getenv('SVC_LAYOUT_RUNTIME');
$settings = json_decode(file_get_contents($runtime.'/harness.json'), true);
if (PHP_SAPI !== 'cli-server' || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1' || ($_SERVER['HTTP_HOST'] ?? '') !== $settings['host'] || !hash_equals($settings['token'], $_SERVER['HTTP_X_SVC_Layout_TOKEN'] ?? $_SERVER['HTTP_X_SVC_LAYOUT_TOKEN'] ?? '') || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') { http_response_code(403); exit; }
$root = ${JSON.stringify(root)};
$file = realpath($root.'/public'.parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if ($file !== false && str_starts_with($file, $root.'/public/build/') && is_file($file)) { return false; }
$app = require $root.'/scripts/layout/bootstrap.php';
$fixture = json_decode(file_get_contents($runtime.'/fixture.json'), true);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$preview = $_SERVER['HTTP_X_SVC_LAYOUT_PREVIEW'] ?? null;
Illuminate\\Support\\Facades\\Auth::onceUsingId($preview === 'dual' ? $fixture['dual_preview_user_id'] : (str_starts_with($path, '/portal/') && $preview === null ? $fixture['portal_user_id'] : $fixture['user_id']));
$app->handleRequest(Illuminate\\Http\\Request::capture());
`,
    );
    server = spawn(
        'php',
        ['-S', host, '-t', 'public', path.join(runtime, 'router.php')],
        { cwd: root, env, stdio: ['ignore', 'pipe', 'pipe'] },
    );
    server.stdout.on('data', (data) => logs.push(data.toString()));
    server.stderr.on('data', (data) => logs.push(data.toString()));
    const headers = { 'X-SVC-Layout-Token': token };
    await expect
        .poll(
            async () => {
                try {
                    return (await fetch(origin + fixture.index, { headers }))
                        .status;
                } catch {
                    return 0;
                }
            },
            { timeout: 15000 },
        )
        .toBe(200);
    browser = await chromium.launch({ headless: true });
    const results = [];

    for (const width of [390, 820, 1440, 1920]) {
        const context = await browser.newContext({
            viewport: { width, height: 1080 },
            extraHTTPHeaders: headers,
        });
        const page = await context.newPage();

        for (const screen of [
            'index',
            'note',
            'discussion',
            'portal',
            'taskthread',
            'agreementfiles',
            'portalagreementfiles',
            'nativeportalagreementfiles',
            'dualportalagreementfiles',
            'expensefiles',
            'operationsfiles',
        ]) {
            await page.setExtraHTTPHeaders({
                ...headers,
                ...(screen === 'nativeportalagreementfiles'
                    ? { 'X-SVC-Layout-Preview': '1' }
                    : screen === 'dualportalagreementfiles'
                      ? { 'X-SVC-Layout-Preview': 'dual' }
                      : {}),
            });
            await page.goto(origin + fixture[screen]);
            await expect(page.locator('main')).toBeVisible();

            if (screen !== 'operationsfiles') {
                await expect(
                    page.getByRole('button', { name: 'Current client' }),
                ).toContainText(fixture.company_name);
                const activeSection = screen.includes('agreementfiles')
                    ? width < 640
                        ? 'Home'
                        : 'Client Home'
                    : screen === 'expensefiles'
                      ? 'Expenses'
                      : screen === 'taskthread'
                        ? 'Tasks'
                        : ['discussion', 'portal'].includes(screen)
                          ? 'Discussions'
                          : 'Notes';

                if (width < 640) {
                    const sectionMenu = page.getByRole('button', {
                        name: 'Client sections',
                    });
                    await expect(sectionMenu).toContainText(activeSection);
                    await sectionMenu.click();
                    await expect(
                        page.getByRole('menuitem', {
                            name: 'Notes',
                            exact: true,
                        }),
                    ).toBeVisible();
                    await expect(
                        page.getByRole('menuitem', {
                            name: 'Discussions',
                            exact: true,
                        }),
                    ).toBeVisible();
                    await expect(
                        page.locator('[role="menuitem"][aria-current="page"]'),
                    ).toContainText(activeSection);
                    await page.screenshot({
                        animations: 'disabled',
                        path: path.join(
                            artifacts,
                            `${screen}-menu-${width}.png`,
                        ),
                    });
                    await page.keyboard.press('Escape');
                    await page
                        .locator('[data-slot="dropdown-menu-content"]')
                        .waitFor({ state: 'detached' });
                } else {
                    await expect(
                        page
                            .getByRole('navigation', {
                                name: 'Client sections',
                            })
                            .locator('[aria-current="page"]'),
                    ).toHaveText(activeSection);
                }

                if (screen === 'portal') {
                    await expect(
                        page.getByRole('button', { name: 'Add comment' }),
                    ).toHaveCount(0);
                }

                if (screen === 'note') {
                    await expect(
                        page
                            .getByText(
                                'Hidden from clients because a parent is private.',
                            )
                            .first(),
                    ).toBeVisible();
                }

                if (width >= 640) {
                    const strip = page.getByRole('navigation', {
                        name: 'Client sections',
                    });
                    const selected = strip.locator('[aria-current="page"]');
                    await expect
                        .poll(async () => {
                            const [navBounds, itemBounds] = await Promise.all([
                                strip.boundingBox(),
                                selected.boundingBox(),
                            ]);

                            return (
                                navBounds !== null &&
                                itemBounds !== null &&
                                itemBounds.x >= navBounds.x - 1 &&
                                itemBounds.x + itemBounds.width <=
                                    navBounds.x + navBounds.width + 1
                            );
                        })
                        .toBe(true);
                }
            }

            if (screen === 'operationsfiles') {
                const header = page.getByRole('banner');
                await expect(header).toBeVisible();
                await expect(
                    header.getByRole('link', { name: '← Workspaces' }),
                ).toBeVisible();
                const navigation = header.getByRole('navigation', {
                    name: 'Workspace operations',
                });
                await expect(
                    navigation.getByRole('link', {
                        name: 'Clients',
                        exact: true,
                    }),
                ).toBeVisible();
                await expect(
                    navigation.getByRole('link', {
                        name: 'Time sheet',
                        exact: true,
                    }),
                ).toBeVisible();
                await expect(
                    navigation.getByRole('combobox', { name: 'Appearance' }),
                ).toBeVisible();
                const bounds = await header.evaluate((element) => {
                    const bar = element.getBoundingClientRect();

                    return Array.from(
                        element.querySelectorAll('a, button, select'),
                    )
                        .filter(
                            (control) =>
                                control.getBoundingClientRect().width > 0,
                        )
                        .map((control) => {
                            const box = control.getBoundingClientRect();
                            const text = document.createRange();
                            text.selectNodeContents(control);

                            return {
                                name:
                                    control.getAttribute('aria-label') ??
                                    control.textContent?.trim(),
                                contained:
                                    box.left >= bar.left &&
                                    box.right <= bar.right &&
                                    box.top >= bar.top &&
                                    box.bottom <= bar.bottom,
                                textHeight:
                                    control.tagName === 'A'
                                        ? text.getBoundingClientRect().height
                                        : null,
                                lineHeight: Number.parseFloat(
                                    getComputedStyle(control).lineHeight,
                                ),
                            };
                        });
                });

                for (const control of bounds) {
                    expect(
                        control.contained,
                        `Operations ${control.name} bounds at ${width}`,
                    ).toBe(true);

                    if (control.textHeight !== null) {
                        expect(
                            control.textHeight,
                            `Operations ${control.name} one line at ${width}`,
                        ).toBeLessThanOrEqual(control.lineHeight + 1);
                    }
                }
            }

            if (screen.includes('agreementfiles')) {
                const projectValue = page
                    .locator('dt')
                    .filter({ hasText: /^Project$/ })
                    .locator('..')
                    .locator('dd');
                const termBounds = await projectValue.evaluate((element) => {
                    const value = element.getBoundingClientRect();
                    const row = element.parentElement.getBoundingClientRect();
                    const terms = element.closest('dl').getBoundingClientRect();
                    const text = document.createRange();
                    text.selectNodeContents(element);
                    const painted = text.getBoundingClientRect();

                    return {
                        contained:
                            value.left >= row.left &&
                            value.right <= row.right + 1 &&
                            row.left >= terms.left &&
                            row.right <= terms.right + 1,
                        textContained:
                            painted.left >= value.left - 1 &&
                            painted.right <= value.right + 1,
                        scrollWidth: element.scrollWidth,
                        width: element.clientWidth,
                    };
                });
                expect(
                    termBounds.contained,
                    `${screen} Project term bounds at ${width}`,
                ).toBe(true);
                expect(
                    termBounds.textContained,
                    `${screen} complete Project term at ${width}`,
                ).toBe(true);
                expect(
                    termBounds.scrollWidth,
                    `${screen} Project term overflow at ${width}`,
                ).toBeLessThanOrEqual(termBounds.width + 1);
            }

            if (screen.endsWith('files')) {
                const pagination = page.getByRole('navigation', {
                    name: 'File pages',
                });
                await pagination.scrollIntoViewIfNeeded();
                const older = pagination.getByRole('link', {
                    name: 'Older files',
                });
                await expect(older).toBeVisible();
                await older.click();
                await expect(page).toHaveURL(/files_page=2/);
                const newer = page
                    .getByRole('navigation', { name: 'File pages' })
                    .getByRole('link', { name: 'Newer files' });
                await expect(newer).toBeVisible();
                await newer.click();
                await expect(page).toHaveURL(/files_page=1/);
                await page
                    .getByRole('navigation', { name: 'File pages' })
                    .scrollIntoViewIfNeeded();
                await page.screenshot({
                    animations: 'disabled',
                    path: path.join(
                        artifacts,
                        `${screen}-pagination-${width}.png`,
                    ),
                });
                await page.evaluate(() => scrollTo(0, 0));
            }

            const layout = await page.evaluate(() => ({
                document: document.documentElement.scrollWidth,
                viewport: innerWidth,
                nav:
                    document.querySelector('header')?.getBoundingClientRect()
                        .height ?? 0,
            }));
            expect(
                layout.document,
                `${screen} at ${width}`,
            ).toBeLessThanOrEqual(width);

            expect(layout.nav, `${screen} navbar at ${width}`).toBe(49);

            await page.screenshot({
                animations: 'disabled',
                path: path.join(artifacts, `${screen}-${width}.png`),
                fullPage: false,
            });

            if (screen === 'note') {
                await page
                    .getByRole('button', { name: 'Edit content' })
                    .click();
                const editor = page.getByRole('textbox', { name: 'Content' });
                await expect(editor).toHaveValue(/Literal synthetic text/);
                await editor.scrollIntoViewIfNeeded();
                await page.screenshot({
                    animations: 'disabled',
                    path: path.join(artifacts, `editor-${width}.png`),
                });
                await page
                    .getByRole('button', { name: 'Cancel', exact: true })
                    .click();
                await page
                    .getByRole('textbox', { name: 'New comment' })
                    .scrollIntoViewIfNeeded();
                await page.screenshot({
                    animations: 'disabled',
                    path: path.join(artifacts, `new-comment-${width}.png`),
                });
                expect(
                    await page.evaluate(
                        () => document.documentElement.scrollWidth,
                    ),
                ).toBeLessThanOrEqual(width);
            }

            results.push({ screen, width, ...layout });
        }

        await context.close();
    }

    writeFileSync(
        path.join(artifacts, 'results.json'),
        JSON.stringify(results, null, 2),
    );
    process.stdout.write(
        `Collaboration browser checks passed. Screenshots: ${artifacts}\n`,
    );
} catch (error) {
    writeFileSync(path.join(artifacts, 'server.log'), logs.join(''));

    throw error;
} finally {
    await browser?.close();
    server?.kill();
    rmSync(runtime, { recursive: true, force: true });
}
