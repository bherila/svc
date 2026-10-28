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
    throw new Error('Stop the existing Vite server first.');
}

const runtime = mkdtempSync(
    path.join(tmpdir(), 'svc-attachment-sharing-layout-'),
);
const artifacts = path.join(
    root,
    'test-results/attachment-sharing-layout',
    path.basename(runtime),
);
mkdirSync(artifacts, { recursive: true });
let server;
let vite;
let browser;
const logs = [];

async function port() {
    const listener = createServer();
    await new Promise((resolve) => listener.listen(0, '127.0.0.1', resolve));
    const result = listener.address().port;
    await new Promise((resolve) => listener.close(resolve));

    return result;
}

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

    const host = `127.0.0.1:${await port()}`;
    const origin = `http://${host}`;
    const vitePort = await port();
    const token = randomBytes(32).toString('hex');
    writeFileSync(
        path.join(runtime, 'harness.json'),
        JSON.stringify({ host, token }),
        { mode: 0o600 },
    );
    writeFileSync(path.join(runtime, '.env'), '');
    writeFileSync(path.join(runtime, 'database.sqlite'), '');
    const env = {
        ...process.env,
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
        SVC_LAYOUT_ATTACHMENT_SHARING: 'true',
        SVC_ATTACHMENT_SHARING_WRITES_ENABLED: 'true',
    };
    const seed = spawnSync('php', ['scripts/layout/collaboration-seed.php'], {
        cwd: root,
        env,
        stdio: 'inherit',
    });

    if (seed.status !== 0) {
        throw new Error('Synthetic attachment fixture failed.');
    }

    const fixture = JSON.parse(
        readFileSync(path.join(runtime, 'fixture.json'), 'utf8'),
    );
    vite = spawn(
        'pnpm',
        [
            'exec',
            'vite',
            '--host',
            '127.0.0.1',
            '--port',
            String(vitePort),
            '--strictPort',
        ],
        { cwd: root, env, detached: true, stdio: ['ignore', 'pipe', 'pipe'] },
    );
    vite.stdout.on('data', (data) => logs.push(data.toString()));
    vite.stderr.on('data', (data) => logs.push(data.toString()));
    await expect
        .poll(
            async () => {
                try {
                    return (
                        await fetch(`http://127.0.0.1:${vitePort}/@vite/client`)
                    ).status;
                } catch {
                    return 0;
                }
            },
            { timeout: 30000 },
        )
        .toBe(200);
    writeFileSync(
        path.join(runtime, 'router.php'),
        `<?php
$runtime = getenv('SVC_LAYOUT_RUNTIME');
$settings = json_decode(file_get_contents($runtime.'/harness.json'), true);
if (PHP_SAPI !== 'cli-server' || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1' || ($_SERVER['HTTP_HOST'] ?? '') !== $settings['host'] || !hash_equals($settings['token'], $_SERVER['HTTP_X_SVC_Layout_TOKEN'] ?? $_SERVER['HTTP_X_SVC_LAYOUT_TOKEN'] ?? '') || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') { http_response_code(403); exit; }
$root = ${JSON.stringify(root)};
$app = require $root.'/scripts/layout/bootstrap.php';
$fixture = json_decode(file_get_contents($runtime.'/fixture.json'), true);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
Illuminate\\Support\\Facades\\Auth::onceUsingId(str_starts_with($path, '/portal/') ? $fixture['portal_user_id'] : $fixture['user_id']);
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
                    const response = await fetch(origin + fixture.note, {
                        headers,
                    });

                    if (response.status >= 500) {
                        writeFileSync(
                            path.join(artifacts, 'initial-error.html'),
                            await response.text(),
                        );
                    }

                    return response.status;
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

        for (const screen of process.argv.includes('--parent-only')
            ? ['parentfiles']
            : process.argv.includes('--portal-structural-only')
              ? ['portalprojectfiles', 'portaltaskfiles']
              : process.argv.includes('--portal-commercial-only')
                ? ['portalproposalfiles', 'portalinvoicefiles']
                : [
                      'note',
                      'portal',
                      'agreementfiles',
                      'operationsfiles',
                      'parentfiles',
                      'portalproposalfiles',
                      'portalinvoicefiles',
                      'portalprojectfiles',
                      'portaltaskfiles',
                  ]) {
            const errors = [];
            const requests = [];
            const onError = (error) => errors.push(error.message);
            const onRequest = (request) => {
                if (
                    request.resourceType() === 'fetch' &&
                    request.url().startsWith(origin)
                ) {
                    requests.push(request.url());
                }
            };
            page.on('pageerror', onError);
            page.on('request', onRequest);
            await page.goto(origin + fixture[screen]);
            await expect(page.locator('main')).toBeVisible();

            if (screen !== 'operationsfiles') {
                await expect(
                    page.getByRole('button', { name: 'Current client' }),
                ).toContainText(fixture.company_name);
            }

            const panel =
                screen === 'agreementfiles'
                    ? page.getByRole('region', {
                          name: 'Attached files',
                          exact: true,
                      })
                    : page
                          .getByRole('region', {
                              name: [
                                  'parentfiles',
                                  'portalprojectfiles',
                                  'portaltaskfiles',
                              ].includes(screen)
                                  ? 'Attached files'
                                  : 'Files',
                              exact: true,
                          })
                          .first();
            await expect(panel).toBeVisible();

            if (screen.startsWith('portal')) {
                await expect(page.getByLabel('Visible to client')).toHaveCount(
                    0,
                );
                await expect(page.getByLabel('Upload a file')).toHaveCount(0);
                await expect(
                    page.getByText('SyntheticPrivateFile.txt', { exact: true }),
                ).toHaveCount(0);
            } else {
                await expect(
                    panel.getByLabel('Visible to client').first(),
                ).toBeVisible();
                await expect(panel.getByLabel('Upload a file')).toBeVisible();
            }

            if (screen === 'note' || screen === 'parentfiles') {
                await expect(
                    panel
                        .getByText(
                            'The parent is not currently visible to clients.',
                        )
                        .first(),
                ).toBeVisible();
            }

            await panel.scrollIntoViewIfNeeded();
            const layout = await page.evaluate(() => ({
                document: document.documentElement.scrollWidth,
                viewport: innerWidth,
                nav:
                    document.querySelector('header')?.getBoundingClientRect()
                        .height ?? 0,
            }));
            expect(
                layout.document,
                `${screen} overflow at ${width}`,
            ).toBeLessThanOrEqual(width);
            expect(layout.nav, `${screen} navbar at ${width}`).toBe(49);
            expect(errors, `${screen} runtime errors`).toEqual([]);
            expect(
                requests,
                `${screen} fetches seeded file data on mount`,
            ).toEqual([]);
            await page.screenshot({
                animations: 'disabled',
                path: path.join(artifacts, `${screen}-${width}.png`),
            });

            if (screen === 'note') {
                const comment = page
                    .locator('[id^="comment-"]')
                    .filter({ has: page.locator('details') })
                    .first();
                await comment.locator('summary').click();
                const commentPanel = comment.getByRole('region', {
                    name: 'Files',
                    exact: true,
                });
                await expect(commentPanel).toBeVisible();
                await commentPanel.scrollIntoViewIfNeeded();
                await page.screenshot({
                    animations: 'disabled',
                    path: path.join(artifacts, `comment-files-${width}.png`),
                });
            }

            results.push({
                screen,
                width,
                ...layout,
                mountFetches: requests.length,
            });
            page.off('pageerror', onError);
            page.off('request', onRequest);
        }

        await context.close();
    }

    writeFileSync(
        path.join(artifacts, 'results.json'),
        JSON.stringify({ results }, null, 2),
    );
    process.stdout.write(
        `Attachment sharing browser checks passed. Screenshots: ${artifacts}\n`,
    );
} catch (error) {
    writeFileSync(path.join(artifacts, 'server.log'), logs.join(''));

    throw error;
} finally {
    await browser?.close();
    server?.kill();

    if (vite?.pid) {
        process.kill(-vite.pid, 'SIGTERM');
    }

    rmSync(path.join(root, 'public/hot'), { force: true });
    rmSync(runtime, { recursive: true, force: true });
}
