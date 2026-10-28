#!/usr/bin/env node
/* global process */
import { spawn, spawnSync } from 'node:child_process';
import { generateKeyPairSync, randomBytes } from 'node:crypto';
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

const paginationOnly = process.argv.includes('--pagination-only');
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
    const oauthKeys = generateKeyPairSync('rsa', {
        modulusLength: 2048,
        publicKeyEncoding: { type: 'spki', format: 'pem' },
        privateKeyEncoding: { type: 'pkcs8', format: 'pem' },
    });
    const env = {
        ...process.env,
        APP_ENV: 'testing',
        APP_DEBUG: 'true',
        APP_KEY: `base64:${randomBytes(32).toString('base64')}`,
        APP_URL: origin,
        PASSPORT_PRIVATE_KEY: oauthKeys.privateKey,
        PASSPORT_PUBLIC_KEY: oauthKeys.publicKey,
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
        SVC_LAYOUT_ATTACHMENT_PAGINATION: paginationOnly ? 'true' : 'false',
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
if (PHP_SAPI !== 'cli-server' || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1' || ($_SERVER['HTTP_HOST'] ?? '') !== $settings['host'] || !hash_equals($settings['token'], $_SERVER['HTTP_X_SVC_Layout_TOKEN'] ?? $_SERVER['HTTP_X_SVC_LAYOUT_TOKEN'] ?? '')) { http_response_code(403); exit; }
$root = ${JSON.stringify(root)};
$app = require $root.'/scripts/layout/bootstrap.php';
$fixture = json_decode(file_get_contents($runtime.'/fixture.json'), true);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET' && !(($_SERVER['REQUEST_METHOD'] ?? '') === 'PATCH' && $path === $fixture['attachment_visibility'])) { http_response_code(403); exit; }
Illuminate\\Support\\Facades\\Auth::onceUsingId(str_starts_with($path, '/portal/') || ($_GET['presentation'] ?? '') === 'portal' ? $fixture['portal_user_id'] : $fixture['user_id']);
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

        for (const screen of paginationOnly
            ? [
                  'pagination_native_parent',
                  'pagination_portal_parent',
                  'pagination_native_collaboration',
                  'pagination_portal_collaboration',
              ]
            : process.argv.includes('--parent-only')
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

            if (
                screen !== 'operationsfiles' &&
                screen !== 'pagination_native_parent'
            ) {
                await expect(
                    page.getByRole('button', { name: 'Current client' }),
                ).toContainText(fixture.company_name);
            }

            const panel = paginationOnly
                ? page.getByRole('region', {
                      name: 'Attached files',
                      exact: true,
                  })
                : screen === 'agreementfiles'
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

            if (paginationOnly) {
                await expect(panel.locator('li')).toHaveCount(1);
                expect(requests, 'seed requires no append fetch').toEqual([]);

                for (const state of ['initial', 'loaded']) {
                    if (state === 'loaded') {
                        let reads = 0;

                        while (
                            await panel
                                .getByRole('button', {
                                    name: 'Load more files',
                                })
                                .count()
                        ) {
                            const before = await panel.locator('li').count();
                            await panel
                                .getByRole('button', {
                                    name: 'Load more files',
                                })
                                .click();
                            await expect
                                .poll(async () => panel.locator('li').count())
                                .toBeGreaterThan(before);
                            expect(++reads).toBeLessThan(6);
                        }

                        expect(requests.length).toBeGreaterThan(0);

                        for (const href of requests) {
                            const url = new URL(href);
                            expect(url.pathname).toMatch(
                                /^\/api\/v1\/workspaces\//,
                            );
                            expect(url.searchParams.get('presentation')).toBe(
                                screen.includes('_portal_')
                                    ? 'portal'
                                    : 'native',
                            );
                            expect(url.searchParams.get('cursor')).toBeTruthy();
                            expect(url.searchParams.get('limit')).toBe('1');
                        }
                    }

                    if (screen.includes('_portal_')) {
                        await expect(
                            panel.getByLabel('Visible to client'),
                        ).toHaveCount(0);
                        await expect(
                            panel.getByLabel('Upload a file'),
                        ).toHaveCount(0);
                        await expect(
                            panel.getByText('SyntheticPrivateFile.txt', {
                                exact: true,
                            }),
                        ).toHaveCount(0);
                        await expect(
                            panel.getByRole('button', { name: 'Remove file' }),
                        ).toHaveCount(0);
                    }

                    await panel.scrollIntoViewIfNeeded();
                    const measured = await page.evaluate(() => ({
                        document: document.documentElement.scrollWidth,
                        viewport: innerWidth,
                        nav:
                            document
                                .querySelector('header')
                                ?.getBoundingClientRect().height ?? 0,
                    }));
                    expect(measured.document).toBeLessThanOrEqual(width);
                    expect(measured.nav).toBe(49);
                    expect(errors).toEqual([]);
                    await page.screenshot({
                        animations: 'disabled',
                        path: path.join(
                            artifacts,
                            `${screen}-${state}-${width}.png`,
                        ),
                    });
                    results.push({
                        screen,
                        state,
                        width,
                        ...measured,
                        appendFetches: requests.length,
                    });
                }

                page.off('pageerror', onError);
                page.off('request', onRequest);
                continue;
            }

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

            if (screen === 'agreementfiles') {
                const row = panel.locator('li').filter({
                    has: page.getByRole('link', {
                        name: 'SyntheticReaderFile.txt',
                        exact: true,
                    }),
                });
                const checkbox = row.getByLabel('Visible to client');
                const writes = [];
                await page.route(
                    origin + fixture.attachment_visibility,
                    async (route) => {
                        if (route.request().method() !== 'PATCH') {
                            await route.continue();

                            return;
                        }

                        writes.push({
                            body: route.request().postData(),
                            key: route.request().headers()['idempotency-key'],
                        });
                        const result = await route.fetch();

                        if (result.status() !== 200) {
                            writeFileSync(
                                path.join(
                                    artifacts,
                                    'sharing-recovery-error.txt',
                                ),
                                await result.text(),
                            );
                        }

                        if (writes.length === 1) {
                            await route.abort('failed');
                        } else {
                            await route.fulfill({ response: result });
                        }
                    },
                );
                await checkbox.click();
                await expect(
                    row.getByRole('button', {
                        name: 'Check sharing',
                        exact: true,
                    }),
                ).toBeEnabled();
                await expect(checkbox).toBeDisabled();
                await row.scrollIntoViewIfNeeded();
                await page.screenshot({
                    animations: 'disabled',
                    path: path.join(artifacts, `sharing-unknown-${width}.png`),
                });
                await row
                    .getByRole('button', { name: 'Check sharing', exact: true })
                    .click();
                await expect(checkbox).toBeEnabled();
                expect(writes).toHaveLength(2);
                expect(writes[1]).toEqual(writes[0]);
                await page.screenshot({
                    animations: 'disabled',
                    path: path.join(
                        artifacts,
                        `sharing-recovered-${width}.png`,
                    ),
                });
                await page.unroute(origin + fixture.attachment_visibility);
                expect(errors, 'sharing recovery runtime errors').toEqual([]);
            }

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
