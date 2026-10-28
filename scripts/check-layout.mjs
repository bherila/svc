#!/usr/bin/env node
/* global process */

import { spawn, spawnSync } from 'node:child_process';
import { generateKeyPairSync, randomBytes, randomUUID } from 'node:crypto';
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
    throw new Error(
        'Stop the Vite dev server and remove its stale public/hot marker before running the layout harness.',
    );
}

const runtime = mkdtempSync(path.join(tmpdir(), 'svc-layout-'));
const artifacts = path.join(
    root,
    'test-results',
    'layout',
    path.basename(runtime),
);
const widths = [390, 820, 1440, 1920];
const clientsOnly = process.argv.includes('--clients');
const portalOnly = process.argv.includes('--portal-access') || clientsOnly;
const correctionOnly = process.argv.includes('--invoice-correction');
const squareOnly = process.argv.includes('--square');
const remindersOnly = process.argv.includes('--reminders');
const disputesOnly = process.argv.includes('--disputes');
const timeOnly = process.argv.includes('--time');
const engagementOnly = process.argv.includes('--engagement');
const backlogOnly = process.argv.includes('--deferred-backlog');
const historyOnly = process.argv.includes('--history');
const tasksOnly = process.argv.includes('--tasks');
const projectsOnly = process.argv.includes('--projects');
const workspacesOnly = process.argv.includes('--workspaces');
const skipBuild = process.argv.includes('--skip-build');
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

    // /up fails unless Passport can load its signing keys, and OAuth issuance is
    // on by default. An ephemeral pair passed in env leaves key files untouched.
    const oauthKeys = generateKeyPairSync('rsa', {
        modulusLength: 2048,
        publicKeyEncoding: { type: 'spki', format: 'pem' },
        privateKeyEncoding: { type: 'pkcs8', format: 'pem' },
    });

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
        PASSPORT_PRIVATE_KEY: oauthKeys.privateKey,
        PASSPORT_PUBLIC_KEY: oauthKeys.publicKey,
        SVC_LAYOUT_RUNTIME: runtime,
        // Shows the seeded portal invitations on client settings; nothing here asks a provider.
        SVC_PORTAL_INVITATIONS_ENABLED: 'true',
        // Links the seeded "Reconciled in finance" badge; never requested.
        SVC_FINANCE_TRANSACTION_URL:
            'https://finance.example.test/transactions/{transaction_uuid}',
        SVC_LAYOUT_PORTAL_PAGES: portalOnly ? '1' : '0',
        SVC_LAYOUT_ENGAGEMENT: engagementOnly ? '1' : '0',
        SVC_LAYOUT_DEFERRED_BACKLOG: backlogOnly ? '1' : '0',
        SVC_LAYOUT_TASKS: tasksOnly ? '1' : '0',
        SVC_LAYOUT_PROJECTS: projectsOnly ? '1' : '0',
        SVC_LAYOUT_WORKSPACES: workspacesOnly ? '1' : '0',
        SVC_LAYOUT_DISPUTES: disputesOnly ? '1' : '0',
        SVC_LAYOUT_CLIENTS: clientsOnly ? '1' : '0',
    };
    run('php', ['scripts/layout/seed.php'], env);
    const fixture = JSON.parse(
        readFileSync(path.join(runtime, 'fixture.json'), 'utf8'),
    );

    if (skipBuild) {
        if (!existsSync(path.join(root, 'public', 'build', 'manifest.json'))) {
            throw new Error(
                '--skip-build requires assets from a successful build of this checkout.',
            );
        }
    } else {
        run('pnpm', ['run', 'build'], env);
    }

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

    for (const method of ['PATCH', 'DELETE']) {
        expect(
            (
                await fetch(origin + fixture.expense_schedule_store, {
                    method,
                    headers,
                })
            ).status,
        ).toBe(403);
    }

    expect(
        (
            await fetch(
                `${origin}/workspaces/00000000-0000-0000-0000-000000000000/expense-schedules/00000000-0000-0000-0000-000000000000/generate`,
                { method: 'POST', headers },
            )
        ).status,
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
            fullPage: !(screen === 'task_list' && state.startsWith('create-')),
            animations: 'disabled',
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

        for (const screen of projectsOnly
            ? ['settings']
            : workspacesOnly
              ? ['selector']
              : engagementOnly
                ? ['operations', 'agreement']
                : disputesOnly
                  ? ['invoice', 'square_portal']
                  : tasksOnly
                    ? [
                          'task_list',
                          'task_project',
                          'task_editor',
                          'task_portal',
                      ]
                    : historyOnly
                      ? [
                            'history_index',
                            'history_discussion',
                            'history_invoice',
                        ]
                      : portalOnly
                        ? ['settings']
                        : correctionOnly
                          ? ['invoice_review']
                          : squareOnly
                            ? [
                                  'square_explore',
                                  'square_invoice',
                                  'square_portal',
                              ]
                            : remindersOnly
                              ? ['reminder_invoice', 'settings']
                              : timeOnly
                                ? ['time', 'draft_time']
                                : backlogOnly
                                  ? ['agreement']
                                  : [
                                        'mcp_setup',
                                        'invoice',
                                        'invoice_review',
                                        'reminder_invoice',
                                        'settings',
                                        'draft_invoice',
                                        'draft_time',
                                        'proposal',
                                        'proposal_acceptance',
                                        'operations',
                                        'time',
                                        'expenses',
                                        'receipts',
                                        'expense_schedules',
                                    ]) {
            const response = await page.goto(
                origin + fixture[portalOnly ? 'portal_settings' : screen],
            );
            expect(response.status()).toBe(200);
            await expect(page.locator('main'))
                .toBeVisible()
                .catch(async (error) => {
                    writeFileSync(
                        path.join(artifacts, `${screen}-failure.html`),
                        await page.content(),
                    );
                    await page.screenshot({
                        path: path.join(artifacts, `${screen}-failure.png`),
                        fullPage: true,
                    });

                    throw error;
                });

            if (screen === 'task_list') {
                const createdTitle = `SyntheticTaskCreatedInBrowser${width}`;
                await expect(
                    page.getByRole('link', { name: 'Next tasks', exact: true }),
                ).toBeVisible();
                await page
                    .getByRole('link', { name: 'Next tasks', exact: true })
                    .click();
                await expect(
                    page.getByRole('link', {
                        name: 'Previous tasks',
                        exact: true,
                    }),
                ).toBeVisible();
                await expect(
                    page.getByRole('link', { name: 'Next tasks', exact: true }),
                ).toHaveCount(0);
                await capture(screen, 'last-page', width);
                await page
                    .getByRole('link', { name: 'Previous tasks', exact: true })
                    .click();
                await expect(
                    page.getByRole('link', { name: 'Next tasks', exact: true }),
                ).toBeVisible();
                await page
                    .getByRole('button', { name: 'New task', exact: true })
                    .click();
                await expect(page.getByRole('dialog')).toBeVisible();
                await capture(screen, 'create-empty', width);
                await page
                    .getByLabel('Title', { exact: true })
                    .fill(createdTitle);
                await page
                    .getByLabel('Description', { exact: true })
                    .fill('Synthetic create task description. '.repeat(20));
                await page
                    .getByLabel('Start date', { exact: true })
                    .fill('2026-11-01');
                await page
                    .getByLabel('Due date', { exact: true })
                    .fill('2026-11-02');
                await expect(page.getByRole('checkbox').first()).toBeVisible();
                await page.getByRole('checkbox').first().check();
                await page
                    .getByRole('button', { name: 'Create task', exact: true })
                    .scrollIntoViewIfNeeded();
                await capture(screen, 'create-dialog', width);

                const createPattern =
                    origin +
                    new URL(fixture.task_create, origin).pathname +
                    '*';
                const readPattern = origin + new URL(page.url()).pathname + '*';
                const writes = [];
                let loseWriteAnswer = true;
                let loseRead = true;
                const createHandler = async (route) => {
                    if (route.request().method() !== 'POST') {
                        return route.continue();
                    }

                    writes.push({
                        body: route.request().postDataJSON(),
                        key: route.request().headers()['idempotency-key'],
                    });

                    if (loseWriteAnswer) {
                        loseWriteAnswer = false;
                        const committed = await route.fetch();
                        expect(committed.status()).toBe(201);

                        return route.abort('failed');
                    }

                    return route.continue();
                };
                const readHandler = async (route) => {
                    if (
                        loseRead &&
                        route.request().method() === 'GET' &&
                        route.request().headers()['x-inertia-partial-data']
                    ) {
                        loseRead = false;

                        return route.abort('failed');
                    }

                    return route.continue();
                };
                await page.route(createPattern, createHandler);
                await page.route(readPattern, readHandler);
                await page
                    .getByRole('button', { name: 'Create task', exact: true })
                    .click();
                const check = page.getByRole('button', {
                    name: 'Check task save',
                    exact: true,
                });
                await expect(check).toBeEnabled();
                await expect(
                    page.getByLabel('Title', { exact: true }),
                ).toBeDisabled();
                await expect(
                    page.getByRole('combobox', {
                        name: 'Project',
                        exact: true,
                    }),
                ).toBeDisabled();
                await page.keyboard.press('Escape');
                await expect(page.getByRole('dialog')).toBeVisible();
                await capture(screen, 'create-unknown', width);
                await check.click();
                await expect(page.getByRole('alert')).toContainText(
                    'saved, but current tasks could not be refreshed',
                );
                await expect(check).toBeEnabled();
                expect(writes).toHaveLength(2);
                expect(writes[1]).toEqual(writes[0]);
                await capture(screen, 'create-refresh-failed', width);
                await check.click();
                await expect(page.getByRole('dialog')).toHaveCount(0);
                expect(writes).toHaveLength(2);
                await expect(
                    page.getByRole('link', { name: createdTitle, exact: true }),
                ).toHaveCount(1);
                await capture(screen, 'created', width);
                await page.unroute(createPattern, createHandler);
                await page.unroute(readPattern, readHandler);
            }

            if (screen === 'task_project') {
                const allTasks = page.getByRole('link', {
                    name: 'View all tasks',
                    exact: true,
                });
                await expect(allTasks).toBeVisible();
                const allTasksHref = await allTasks.getAttribute('href');
                expect(allTasksHref).toContain('?project=');
                await allTasks.click();
                await expect(
                    page.getByRole('link', { name: 'Next tasks', exact: true }),
                ).toBeVisible();
                await capture(screen, 'project-filtered-list', width);
                await page.goto(origin + fixture.task_project);
            }

            if (screen === 'task_editor') {
                await expect(
                    page.locator(
                        'header [aria-label="Client sections"]:visible',
                    ),
                ).toContainText('Tasks');
                await expect(page.locator('header')).not.toContainText(
                    'Select a client',
                );
                await expect(
                    page.getByRole('button', {
                        name: /^Remove SyntheticAssignee/,
                    }),
                ).toHaveCount(10);
                await expect(
                    page.getByText(
                        'More staff match. Refine the search to find them.',
                    ),
                ).toBeVisible();
                await capture(screen, 'editor', width);
                const version = await page.evaluate(() => {
                    const initial = document.querySelector('script[data-page]');

                    if (!initial?.textContent) {
                        throw new Error(
                            'The task page has no initial Inertia data.',
                        );
                    }

                    return JSON.parse(initial.textContent).props.task.version;
                });
                const revised = await context.request.patch(
                    origin + fixture.task_update,
                    {
                        data: { expected_version: version },
                        headers: {
                            'Sec-Fetch-Site': 'same-origin',
                            'Idempotency-Key': randomUUID(),
                        },
                        maxRedirects: 0,
                    },
                );

                expect(revised.status()).toBe(200);

                await page
                    .getByRole('button', { name: 'Save task', exact: true })
                    .click();
                await expect(page.getByRole('alert')).toContainText(
                    'This task has changed. Reload',
                );
                await capture(screen, 'stale-conflict', width);
            }

            if (screen === 'task_portal') {
                await expect(
                    page.getByRole('button', { name: 'New task', exact: true }),
                ).toHaveCount(0);
                await expect(page.getByText(/^SyntheticAssignee/)).toHaveCount(
                    0,
                );
                await page
                    .getByRole('link', { name: 'Next tasks', exact: true })
                    .click();
                await expect(page).toHaveURL(/\/portal\//);
                await expect(
                    page.getByRole('link', {
                        name: 'Previous tasks',
                        exact: true,
                    }),
                ).toBeVisible();
                await expect(
                    page.getByRole('button', { name: 'New task', exact: true }),
                ).toHaveCount(0);
                await capture(screen, 'last-page', width);
                await page
                    .getByRole('link', { name: 'Previous tasks', exact: true })
                    .click();
                await expect(
                    page.getByRole('link', { name: 'Next tasks', exact: true }),
                ).toBeVisible();
            }

            if (disputesOnly) {
                await expect(
                    page.getByText('Disputed', { exact: true }).first(),
                ).toBeVisible();
                await expect(
                    page.getByRole('button', {
                        name: 'Send reminder',
                        exact: true,
                    }),
                ).toHaveCount(0);
                await expect(
                    page.getByRole('button', {
                        name: 'Send invoice',
                        exact: true,
                    }),
                ).toHaveCount(0);

                if (screen === 'square_portal') {
                    await expect(
                        page.getByRole('link', {
                            name: 'View or pay in Square',
                            exact: true,
                        }),
                    ).toHaveCount(0);
                }
            }

            if (screen.startsWith('square_') && !disputesOnly) {
                await expect(
                    page
                        .getByRole('link', {
                            name:
                                screen === 'square_explore'
                                    ? 'Open'
                                    : 'View or pay in Square',
                        })
                        .first(),
                ).toHaveAttribute(
                    'href',
                    'https://squareupsandbox.com/pay-invoice/inv_fixture_layout',
                );
            }

            if (screen === 'square_invoice') {
                await expect(page.getByRole('alert')).toContainText(
                    'Paid in SVC while Square still has an open balance',
                );
                await expect(
                    page.getByRole('link', { name: 'the Square dashboard' }),
                ).toHaveAttribute(
                    'href',
                    'https://squareupsandbox.com/dashboard/invoices/inv_fixture_layout',
                );

                // Synthetic provider responses exercise review/confirmation layout
                // without any network request to Square or ledger mutation.
                let recordable = false;
                const reviewPattern = '**/square-coverage/*/payments';
                await page.route(reviewPattern, async (route) => {
                    await route.fulfill({
                        json: {
                            data: {
                                invoice_id:
                                    '00000000-0000-4000-8000-000000000001',
                                invoice_number:
                                    'SYNTHETICCOVEREDINVOICE'.repeat(8),
                                invoice_version: 'a'.repeat(64),
                                balance_amount: 12345,
                                currency: 'USD',
                                square_invoice_id: 'inv_fixture_layout_covered',
                                square_invoice_number:
                                    'SYNTHETICCOVERED'.repeat(10),
                                payments: [
                                    {
                                        square_payment_id:
                                            'synthetic_payment_'.repeat(10),
                                        amount: 12345,
                                        refunded_amount: 0,
                                        currency: 'USD',
                                        received_on: '2030-02-01',
                                        reviewed_version: 'b'.repeat(64),
                                        recorded_payment_id: null,
                                        refusal: recordable
                                            ? null
                                            : 'This payment includes a refund and requires manual review.',
                                        record_href: recordable
                                            ? '/synthetic-covered-record'
                                            : null,
                                    },
                                ],
                            },
                        },
                    });
                });
                await page
                    .getByRole('button', {
                        name: 'Review payments',
                        exact: true,
                    })
                    .click();
                await expect(
                    page.getByText(
                        'This payment includes a refund and requires manual review.',
                        { exact: true },
                    ),
                ).toBeVisible();
                await capture(screen, 'covered-payment-refused', width);
                recordable = true;
                await page
                    .getByRole('button', {
                        name: 'Review payments',
                        exact: true,
                    })
                    .click();
                await page
                    .getByRole('button', {
                        name: 'Record this payment',
                        exact: true,
                    })
                    .click();
                await expect(page.getByRole('alertdialog')).toBeVisible();
                await capture(screen, 'covered-payment-confirm', width);
                await page
                    .getByRole('button', { name: 'Cancel', exact: true })
                    .click();
                await page.unroute(reviewPattern);
            }

            if (screen === 'reminder_invoice') {
                const card = page.locator('[data-slot="card"]').filter({
                    has: page.getByText('Payment reminders', { exact: true }),
                });
                await expect(
                    card.getByText(
                        'Automatic reminders are on for this client',
                        {
                            exact: false,
                        },
                    ),
                ).toBeVisible();
                await expect(card.getByRole('row')).toHaveCount(4);
            }

            if (screen === 'settings') {
                await expect(
                    page.getByLabel('Billing address', { exact: true }),
                ).toHaveValue('SyntheticAddressWithoutBreaks'.repeat(12));
                await expect(
                    page.getByLabel('Tax ID', { exact: true }),
                ).toHaveValue('SyntheticTaxIdentifier'.repeat(8));
                const historicalContact = page
                    .locator('summary')
                    .filter({ hasText: 'SyntheticContact'.repeat(6) });
                await historicalContact.click();
                await expect(
                    page.getByRole('button', {
                        name: 'Save contact',
                        exact: true,
                    }),
                ).toBeVisible();
                await expect(
                    page.getByText(
                        'Adding a contact does not grant portal access or send an invitation.',
                        { exact: false },
                    ),
                ).toBeVisible();
                await expect(
                    page.getByRole('switch', {
                        name: 'Automatically remind this client about unpaid invoices',
                    }),
                ).toBeChecked();
            }

            if (clientsOnly && screen === 'settings') {
                const restore = page.getByRole('button', {
                    name: 'Restore contact',
                    exact: true,
                });

                if (await restore.count()) {
                    await restore.click();
                    const summary = page
                        .locator('summary')
                        .filter({ hasText: 'SyntheticContact'.repeat(6) });
                    await expect(summary).not.toContainText('Archived');

                    if (
                        !(await summary.evaluate(
                            (element) => element.parentElement.open,
                        ))
                    ) {
                        await summary.click();
                    }

                    await expect(
                        page.getByRole('button', {
                            name: 'Archive contact',
                            exact: true,
                        }),
                    ).toBeVisible();
                }

                await capture(screen, 'client-contact-form', width);
                await page
                    .getByRole('button', {
                        name: 'Archive contact',
                        exact: true,
                    })
                    .click();
                await expect(page.getByRole('alertdialog')).toBeVisible();
                await capture(screen, 'contact-archive-confirmation', width);
                await page
                    .getByRole('alertdialog')
                    .getByRole('button', { name: 'Cancel', exact: true })
                    .click();
                await page
                    .getByRole('button', {
                        name: 'Current client',
                        exact: true,
                    })
                    .click();
                await page
                    .getByRole('button', { name: 'Add client', exact: true })
                    .click();
                await expect(page.getByRole('dialog')).toBeVisible();
                await page
                    .getByRole('dialog')
                    .getByLabel('Name', { exact: true })
                    .fill('SyntheticNewClient'.repeat(8));
                await page
                    .getByRole('dialog')
                    .getByLabel('Billing email', { exact: true })
                    .fill('billing@example.test');
                await capture(screen, 'create-client-dialog', width);
                await page
                    .getByRole('dialog')
                    .getByRole('button', { name: 'Cancel', exact: true })
                    .click();
            }

            if (screen === 'history_index') {
                await expect(page.getByLabel('Search history')).toBeVisible();
                await expect(
                    page.getByRole('link', {
                        name: 'SyntheticHistoricalDiscussion'.repeat(12),
                    }),
                ).toBeVisible();
            }

            if (screen === 'history_discussion') {
                await expect(
                    page.getByRole('heading', { name: 'Related records' }),
                ).toBeVisible();
                await expect(page.locator('main script')).toHaveCount(0);
                await expect(
                    page.getByText('<script>Displayed as text only</script>', {
                        exact: false,
                    }),
                ).toBeVisible();
            }

            if (screen === 'history_invoice') {
                await expect(
                    page.getByRole('heading', { name: 'Invoice items' }),
                ).toBeVisible();
                await expect(
                    page.getByText('0.00400', { exact: true }),
                ).toHaveCount(3);
                await expect(
                    page.getByRole('button', {
                        name: /send|charge|collect|remind/i,
                    }),
                ).toHaveCount(0);
            }

            if (screen === 'proposal_acceptance') {
                await expect(page.locator('#signer-name')).toBeVisible();
                await expect(page.locator('#signer-title')).toBeVisible();
                await expect(
                    page.getByRole('button', { name: 'Accept', exact: true }),
                ).toBeVisible();
                await page
                    .locator('#signer-name')
                    .fill('SyntheticLayoutSigner'.repeat(8));
                await page
                    .locator('#signer-title')
                    .fill('SyntheticLayoutTitle'.repeat(8));
            }

            if (screen !== 'task_editor') {
                await capture(screen, 'initial', width, false);
            }

            if (engagementOnly && screen === 'operations') {
                await page
                    .getByLabel('Proposal title', { exact: true })
                    .first()
                    .fill('SyntheticProposalTitle'.repeat(12));
                await page
                    .getByLabel('Proposal line description', { exact: true })
                    .first()
                    .fill('SyntheticProposalLine'.repeat(12));
                await page
                    .getByLabel('Proposal price', { exact: true })
                    .first()
                    .fill('1234.50');
                await capture(screen, 'engagement-draft', width);
                await page
                    .getByRole('button', { name: 'Activate', exact: true })
                    .first()
                    .click();
                await expect(page.getByRole('dialog')).toBeVisible();
                await capture(screen, 'activation-confirmation', width);
                await page
                    .getByRole('button', { name: 'Cancel', exact: true })
                    .click();
            }

            if (engagementOnly && screen === 'agreement') {
                await page
                    .getByRole('button', {
                        name: 'Rename this agreement',
                        exact: true,
                    })
                    .click();
                await capture(screen, 'rename', width);
                await page
                    .getByRole('button', { name: 'Cancel', exact: true })
                    .click();
                await page
                    .getByRole('button', { name: 'Edit terms', exact: true })
                    .click();
                await capture(screen, 'terms', width);
                await page
                    .getByRole('button', { name: 'Cancel', exact: true })
                    .click();
            }

            if (projectsOnly && screen === 'settings') {
                const form = page
                    .locator('form')
                    .filter({
                        has: page.locator('input[aria-label^="Name of "]'),
                    })
                    .first();
                const name = form.locator('input[aria-label^="Name of "]');
                await name.fill(`Synthetic reconciled project ${width}`);
                await page.route(
                    origin + fixture.project_writes.PATCH[0],
                    (route) => route.abort('failed'),
                    { times: 1 },
                );
                await form
                    .getByRole('button', { name: 'Save project', exact: true })
                    .click();
                await expect(form.getByRole('alert')).toContainText(
                    'could not be reached',
                );
                await expect(name).toBeDisabled();
                await capture(screen, 'uncertain-save', width);
                await form
                    .getByRole('button', {
                        name: 'Check project save',
                        exact: true,
                    })
                    .click();
                await expect(name).toBeEnabled();
                await expect(name).toHaveValue(
                    `Synthetic reconciled project ${width}`,
                );
                await expect(form.getByRole('alert')).toHaveCount(0);
                await capture(screen, 'reconciled-save', width);
            }

            if (projectsOnly && screen === 'settings' && width === 1920) {
                const form = page
                    .locator('form')
                    .filter({
                        has: page.locator('input[aria-label^="Name of "]'),
                    })
                    .first();
                const name = form.locator('input[aria-label^="Name of "]');
                await name.fill('Synthetic browser edited project');
                const saved = page.waitForResponse(
                    (response) =>
                        response.request().method() === 'PATCH' &&
                        fixture.project_writes.PATCH.includes(
                            new URL(response.url()).pathname,
                        ),
                );
                await form
                    .getByRole('button', { name: 'Save project', exact: true })
                    .click();
                expect((await saved).status()).toBe(200);
                await expect(
                    page.getByLabel('Name of Synthetic browser edited project'),
                ).toBeVisible();
                const access = page
                    .getByRole('combobox', {
                        name:
                            'Access for ' + 'SyntheticProjectMember'.repeat(12),
                    })
                    .first();
                const granted = page.waitForResponse(
                    (response) =>
                        response.request().method() === 'PUT' &&
                        fixture.project_writes.PUT.includes(
                            new URL(response.url()).pathname,
                        ),
                );
                await access.selectOption('viewer');
                expect((await granted).status()).toBe(200);
                await expect(access).toHaveValue('viewer');
                await capture(screen, 'project-edited-and-granted', width);
                await page
                    .getByLabel('New project', { exact: true })
                    .fill('Synthetic browser created project');
                const created = page.waitForResponse(
                    (response) =>
                        response.request().method() === 'POST' &&
                        fixture.project_writes.POST.includes(
                            new URL(response.url()).pathname,
                        ),
                );
                await page
                    .getByRole('button', { name: 'Add project', exact: true })
                    .click();
                expect((await created).status()).toBe(201);
                await expect(
                    page.getByLabel(
                        'Name of Synthetic browser created project',
                    ),
                ).toBeVisible();
                await capture(screen, 'project-created', width);
            }

            if (workspacesOnly && screen === 'selector') {
                await page
                    .getByRole('button', { name: 'New workspace', exact: true })
                    .click();
                await page
                    .getByLabel('Name', { exact: true })
                    .fill('SyntheticWorkspaceWithoutBreaks'.repeat(4));
                await capture(screen, 'create-dialog', width);

                if (width === 1920) {
                    await page
                        .getByLabel('Name', { exact: true })
                        .fill('Synthetic browser created workspace');
                    const created = page.waitForResponse(
                        (response) =>
                            response.request().method() === 'POST' &&
                            new URL(response.url()).pathname ===
                                fixture.workspace_create,
                    );
                    await page
                        .getByRole('button', { name: 'Create', exact: true })
                        .click();
                    const result = await created;
                    expect(result.status()).toBe(201);
                    const body = await result.json();
                    await page.waitForURL(body.data.web_url);
                    await expect(
                        page.getByRole('heading', {
                            name: 'Synthetic browser created workspace',
                            exact: true,
                        }),
                    ).toBeVisible();
                    await page.goto(origin + fixture.selector);
                    await expect(
                        page.getByRole('link', {
                            name: /Synthetic browser created workspace/,
                        }),
                    ).toBeVisible();
                    await capture(screen, 'created', width);
                } else {
                    await page
                        .getByRole('button', { name: 'Cancel', exact: true })
                        .click();
                }
            }

            if (screen === 'agreement' && backlogOnly) {
                await page
                    .getByRole('button', { name: 'Edit terms', exact: true })
                    .click();
                await expect(
                    page.getByRole('switch', {
                        name: 'Show deferred backlog to client',
                        exact: true,
                    }),
                ).not.toBeChecked();
                await capture(screen, 'portal-visibility-setting', width);
                await page
                    .getByRole('button', { name: 'Cancel', exact: true })
                    .click();
                await page
                    .getByRole('spinbutton', { name: 'Target hours' })
                    .fill('1');
                await page
                    .getByRole('switch', {
                        name: 'Round up to the next whole entry',
                    })
                    .check();
                await page
                    .getByRole('button', {
                        name: 'Preview deferred work',
                        exact: true,
                    })
                    .click();
                const createDraft = page.getByRole('button', {
                    name: 'Create draft',
                    exact: true,
                });
                await expect(createDraft).toBeEnabled();
                await capture(screen, 'buydown-preview', width);
                const backlogBefore = await page
                    .getByTestId('deferred-backlog-line')
                    .innerText();
                await createDraft.click();
                await expect(
                    page.getByRole('link', { name: 'Open draft', exact: true }),
                ).toBeVisible();

                if (width === 1920) {
                    await expect(
                        page.getByText('No deferred work is waiting', {
                            exact: false,
                        }),
                    ).toBeVisible();
                } else {
                    await expect(
                        page.getByTestId('deferred-backlog-line'),
                    ).not.toHaveText(backlogBefore);
                }

                await capture(screen, 'buydown-created', width);
            }

            if (screen === 'reminder_invoice') {
                await page
                    .getByRole('button', { name: 'Send reminder', exact: true })
                    .click();
                await expect(page.getByRole('alertdialog')).toBeVisible();
                await capture(screen, 'reminder-confirmation', width);
                await page
                    .getByRole('button', { name: 'Cancel', exact: true })
                    .click();
            }

            if (portalOnly) {
                const card = page.locator('[data-slot="card"]').filter({
                    has: page.getByText('Portal access', { exact: true }),
                });
                await expect(
                    card.getByRole('button', {
                        name: 'Save access',
                        exact: true,
                    }),
                ).toHaveCount(2);
                await expect(
                    card.getByRole('link', { name: 'Newer invitations' }),
                ).toBeVisible();
                await expect(
                    card.getByRole('button', {
                        name: 'Retry withdrawal',
                        exact: true,
                    }),
                ).toBeVisible();
                await page
                    .getByRole('combobox', {
                        name: 'Portal access for the new contact',
                        exact: true,
                    })
                    .selectOption('projects');
                await capture(screen, 'portal-project-selection', width);
                await card
                    .getByRole('button', { name: 'Remove', exact: true })
                    .first()
                    .click();
                await expect(page.getByRole('alertdialog')).toBeVisible();
                await capture(screen, 'portal-removal-confirmation', width);
                await page
                    .getByRole('button', { name: 'Cancel', exact: true })
                    .click();
                await expect(
                    card.getByLabel('Invitation link', { exact: true }),
                ).toHaveCount(1);
                await card
                    .getByRole('button', { name: 'Withdraw', exact: true })
                    .click();
                await expect(page.getByRole('alertdialog')).toBeVisible();
                await capture(screen, 'portal-invitation-withdrawal', width);
                await page
                    .getByRole('button', { name: 'Cancel', exact: true })
                    .click();
            }

            if (screen === 'mcp_setup') {
                await page.emulateMedia({ colorScheme: 'dark' });
                await capture(screen, 'dark', width);
                await page.emulateMedia({ colorScheme: 'light' });
            }

            if (screen === 'expense_schedules') {
                await page
                    .getByRole('button', { name: 'Edit schedule', exact: true })
                    .click();
                await capture(screen, 'edit', width);
                await page
                    .getByRole('button', { name: 'Cancel', exact: true })
                    .click();
                await page
                    .getByRole('button', { name: 'New schedule', exact: true })
                    .click();
                await page
                    .getByLabel('Description', { exact: true })
                    .fill('SyntheticUnbrokenRecurringExpense'.repeat(8));
                await capture(screen, 'create', width);
            }

            if (screen === 'expenses') {
                await expect(
                    page
                        .getByRole('link', { name: 'Receipts', exact: true })
                        .first(),
                ).toBeVisible();
                await expect(
                    page.getByRole('link', { name: /^Invoice EXP-SYNTHETIC-/ }),
                ).toBeVisible();
                await expect(
                    page.getByText(/Awaiting an eligible EUR invoice/),
                ).toBeVisible();
                await expect(
                    page
                        .getByText(
                            'Approval is required before this expense can be invoiced.',
                        )
                        .first(),
                ).toBeVisible();
            }

            if (screen === 'draft_time') {
                await page
                    .getByRole('checkbox', {
                        name: /^Select SyntheticUnbrokenWork.* for invoice$/,
                    })
                    .check();
                await page
                    .getByRole('button', {
                        name: 'Review draft invoice',
                        exact: true,
                    })
                    .click();
                await expect(page.getByRole('dialog')).toBeVisible();
                await expect(
                    page.getByText('New draft total: $390.75', { exact: true }),
                ).toBeVisible();
                await capture(screen, 'add-preview', width);
                await page
                    .getByRole('button', { name: 'Cancel', exact: true })
                    .click();
            }

            if (screen === 'time') {
                await page
                    .getByRole('button', { name: 'Log time', exact: true })
                    .click();
                await expect(
                    page.getByLabel('Hourly rate', { exact: true }),
                ).toBeVisible();
                await capture(screen, 'log-entry', width);
                await page
                    .getByLabel('Hourly rate', { exact: true })
                    .fill('123.45');
                await page
                    .getByLabel('Work performed', { exact: true })
                    .fill('Synthetic explicit rate acceptance');
                await page
                    .getByRole('button', { name: 'Log time', exact: true })
                    .last()
                    .scrollIntoViewIfNeeded();
                await page
                    .getByLabel('Duration', { exact: true })
                    .fill('23:59');
                await page
                    .getByLabel('Work performed', { exact: true })
                    .focus();
                const durationText = await page
                    .getByLabel('Duration', { exact: true })
                    .evaluate((input) => {
                        const style = getComputedStyle(input);
                        const context = document
                            .createElement('canvas')
                            .getContext('2d');
                        context.font = `${style.fontWeight} ${style.fontSize} ${style.fontFamily}`;

                        return {
                            available:
                                input.clientWidth -
                                parseFloat(style.paddingLeft) -
                                parseFloat(style.paddingRight),
                            required: Math.ceil(
                                context.measureText(input.value).width,
                            ),
                        };
                    });
                expect(
                    durationText.available,
                    'The duration value must be readable without clipping',
                ).toBeGreaterThanOrEqual(durationText.required);
                await capture(screen, 'log-fields', width);
                await page
                    .getByRole('button', { name: 'Cancel', exact: true })
                    .click();
                await page
                    .getByRole('button', {
                        name: 'Select time to invoice',
                        exact: true,
                    })
                    .click();
                const checkboxes = page.getByRole('checkbox', {
                    name: / for invoice$/,
                });
                await expect(checkboxes).toHaveCount(2);
                await checkboxes.nth(0).check();
                await checkboxes.nth(1).check();
                await capture(screen, 'selection', width);
                await page
                    .getByRole('button', {
                        name: 'Review draft invoice',
                        exact: true,
                    })
                    .click();
                const dialog = page.getByRole('dialog');
                await expect(dialog).toBeVisible();
                await expect(dialog).toHaveCSS('opacity', '1');
                await dialog.evaluate((element) =>
                    Promise.all(
                        element
                            .getAnimations()
                            .map((animation) =>
                                animation.finished.catch(() => {}),
                            ),
                    ),
                );
                await expect(
                    dialog.getByText('Draft total: $280.75', { exact: true }),
                ).toBeVisible();
                await dialog.evaluate((element) => {
                    element.scrollTop = 0;
                });
                await capture(screen, 'invoice-preview', width);
                await page
                    .getByLabel('Invoice number', { exact: true })
                    .fill('SYN-SELECTED-'.repeat(6));
                await page
                    .getByLabel('Notes (optional)', { exact: true })
                    .fill('Synthetic review notes '.repeat(12));
                await page
                    .getByRole('button', {
                        name: 'Create draft invoice',
                        exact: true,
                    })
                    .scrollIntoViewIfNeeded();
                await capture(screen, 'invoice-fields', width);
                // This invoice form is inspected without submitting it.
                await page
                    .getByRole('button', { name: 'Cancel', exact: true })
                    .click();
            }

            if (screen === 'receipts') {
                await page
                    .getByRole('button', { name: 'Remove', exact: true })
                    .click();
                await expect(page.getByRole('alertdialog')).toHaveCSS(
                    'opacity',
                    '1',
                );
                await capture(screen, 'remove-receipt', width);
                await page
                    .getByRole('button', { name: 'Cancel', exact: true })
                    .click();
            }

            if (screen === 'invoice') {
                await expect(
                    page.getByText('Reconciled in finance', { exact: true }),
                ).toBeVisible();
                await expect(
                    page.getByRole('link', {
                        name: 'Finance transaction 2 of 2',
                    }),
                ).toBeVisible();
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
                // The reason the correction now requires (#454), oversized
                // and unbroken, so the row it sits in has to give it room.
                await page
                    .getByLabel('Reason for the correction', { exact: true })
                    .fill('SyntheticCorrectionReasonWithoutBreaks'.repeat(8));
                await capture(screen, 'correction', width);
                await page
                    .getByRole('button', { name: 'Cancel', exact: true })
                    .click();
                const voiding = page.getByRole('button', {
                    name: 'Void',
                    exact: true,
                });

                if ((await voiding.count()) > 0) {
                    await voiding.click();
                    await expect(page.getByRole('alertdialog')).toHaveCSS(
                        'opacity',
                        '1',
                    );
                    await page
                        .getByLabel('Reason', { exact: true })
                        .fill('SyntheticVoidReasonWithoutBreaks'.repeat(20));
                    await capture(screen, 'void', width);
                    await page
                        .getByRole('button', { name: 'Cancel', exact: true })
                        .click();
                }
            }

            if (screen === 'invoice_review') {
                const evidence = page.getByRole('link', {
                    name: 'View correction evidence for revision 2',
                });
                await expect(evidence).toBeVisible();
                const recovered = await page.request.get(
                    origin + (await evidence.getAttribute('href')),
                    { headers },
                );
                expect(recovered.status()).toBe(200);
                expect((await recovered.json()).data.to_revision).toBe(2);

                await page
                    .getByRole('button', {
                        name: 'Correct invoice',
                        exact: true,
                    })
                    .click();
                await expect(
                    page.getByLabel('Reason for correction', { exact: true }),
                ).toBeVisible();
                await page
                    .getByLabel('Description', { exact: true })
                    .fill(
                        'SyntheticCorrectedDescriptionWithoutBreaks'.repeat(10),
                    );
                await capture(screen, 'correction', width);
            }
        }
    }

    if (
        !disputesOnly &&
        !tasksOnly &&
        !portalOnly &&
        !correctionOnly &&
        !squareOnly &&
        !remindersOnly &&
        !backlogOnly &&
        !engagementOnly
    ) {
        await page.setViewportSize({ width: 1440, height: 1000 });
        await page.goto(origin + fixture.project_log);
        await expect(page.getByRole('dialog')).toBeVisible();
        await expect(
            page.getByRole('combobox', { name: 'Project', exact: true }),
        ).toContainText('Synthetic second logging project');
        await page
            .getByLabel('Work performed', { exact: true })
            .fill('Synthetic explicit rate acceptance');
        await page.getByLabel('Hourly rate', { exact: true }).fill('123.45');
        await page.getByLabel('Currency', { exact: true }).fill('USD');
        await page
            .getByRole('button', { name: 'Log time', exact: true })
            .last()
            .click();
        await expect(page.getByRole('dialog')).toHaveCount(0);
        const logged = page
            .getByRole('row')
            .filter({ hasText: 'Synthetic explicit rate acceptance' });
        await logged
            .getByRole('button', { name: 'Approve', exact: true })
            .click();
        await expect(logged).toContainText('Approved');
        await capture('time', 'explicit-rate-approved', 1440);
        run('php', ['scripts/layout/verify-time-log.php'], env);
    }

    if (
        !disputesOnly &&
        !tasksOnly &&
        !portalOnly &&
        !correctionOnly &&
        !squareOnly &&
        !remindersOnly &&
        !timeOnly &&
        !backlogOnly &&
        !engagementOnly
    ) {
        // Full operator correction path, all writes confined to the seeded records.
        await page.setViewportSize({ width: 1440, height: 1000 });
        await page.goto(origin + fixture.draft_invoice);
        await page.getByRole('link', { name: 'Add time', exact: true }).click();
        let correction = page
            .getByRole('row')
            .filter({ hasText: 'Synthetic draft correction' });
        await correction
            .getByRole('button', { name: 'Unapprove', exact: true })
            .click();
        await expect(correction).toContainText('Draft');
        await correction
            .getByRole('button', { name: 'Edit', exact: true })
            .click();
        await page.getByLabel('Duration', { exact: true }).fill('1:30');
        await page
            .getByLabel('Work performed', { exact: true })
            .fill('Synthetic corrected work');
        await page
            .getByRole('button', { name: 'Save changes', exact: true })
            .click();
        correction = page
            .getByRole('row')
            .filter({ hasText: 'Synthetic corrected work' });
        await correction
            .getByRole('button', { name: 'Approve', exact: true })
            .click();
        await expect(correction).toContainText('Approved');
        await correction.getByRole('checkbox').check();
        await page
            .getByRole('button', { name: 'Review draft invoice', exact: true })
            .click();
        await expect(
            page.getByText('New draft total: $245.00', { exact: true }),
        ).toBeVisible();
        await page
            .getByRole('button', { name: 'Add time to draft', exact: true })
            .click();
        await expect(page.getByRole('dialog')).toHaveCount(0);
        await page
            .locator(`a[href="${fixture.draft_invoice}"]`)
            .first()
            .click();
        await expect(page).toHaveURL(origin + fixture.draft_invoice);
        await expect(
            page
                .getByRole('row')
                .filter({ hasText: 'Synthetic preserved fee' }),
        ).toContainText('$5.00');
        await page.getByRole('button', { name: 'Issue', exact: true }).click();
        await expect(
            page.getByRole('button', { name: 'Issue', exact: true }),
        ).toHaveCount(0);
        await page
            .getByRole('button', { name: 'Record payment', exact: true })
            .click();
        await expect(page.getByLabel('Amount', { exact: true })).toHaveValue(
            '245.00',
        );
        await page.getByRole('button', { name: 'Record', exact: true }).click();
        await expect(page.getByLabel('Amount', { exact: true })).toHaveCount(0);
        await capture('draft_invoice', 'paid-after-correction', 1440);
        run('php', ['scripts/layout/verify-draft-time.php'], env);

        // Exercise real saves against the isolated database after layout capture,
        // so the additional schedule cannot change the fixtures used at each width.
        await page.goto(origin + fixture.expense_schedules);
        await page
            .getByRole('button', { name: 'New schedule', exact: true })
            .click();
        await page
            .getByLabel('Description', { exact: true })
            .fill('Synthetic precision journey');
        await page.getByLabel('Amount in minor units').fill('9007199254740993');
        await page.getByLabel('First occurrence').fill(fixture.date);
        await page
            .getByRole('button', { name: 'Save schedule', exact: true })
            .click();
        let card = page.locator('section').filter({
            has: page.getByRole('heading', {
                name: 'Synthetic precision journey',
                exact: true,
            }),
        });
        await expect(card).toBeVisible();
        await card
            .getByRole('button', { name: 'Edit schedule', exact: true })
            .click();
        await expect(page.getByLabel('Amount in minor units')).toHaveValue(
            '9007199254740993',
        );
        await page
            .getByLabel('Description', { exact: true })
            .fill('Synthetic precision journey edited');
        await page
            .getByRole('button', { name: 'Save schedule', exact: true })
            .click();
        card = page.locator('section').filter({
            has: page.getByRole('heading', {
                name: 'Synthetic precision journey edited',
                exact: true,
            }),
        });
        await expect(card).toContainText('90,071,992,547,409.93');
        await card
            .getByRole('button', { name: 'Edit schedule', exact: true })
            .click();
        await expect(page.getByLabel('Amount in minor units')).toHaveValue(
            '9007199254740993',
        );
        await page.getByRole('button', { name: 'Cancel', exact: true }).click();
        await card
            .getByRole('button', { name: 'Generate due drafts', exact: true })
            .click();
        await expect(
            card.getByRole('button', {
                name: 'Generate due drafts',
                exact: true,
            }),
        ).toHaveCount(0);
        await page.goto(origin + fixture.expenses);
        const expense = page
            .getByRole('row')
            .filter({ hasText: 'Synthetic precision journey edited' });
        await expect(expense).toHaveCount(1);
        await expect(expense).toContainText('Draft');
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
            projectsOnly
                ? 'Synthetic project settings with oversized descriptions, repository and member names at every required width; canonical API edit, membership grant and creation verified in the disposable database.'
                : workspacesOnly
                  ? 'Workspace selector and filled creation dialog at all required widths, with oversized fixture names; real session API creation and finished result navigation verified in the disposable database.'
                  : disputesOnly
                    ? 'Synthetic partially disputed operator invoice with a record-payment form and a disputed Square-linked client copy with collection links withheld at every required width.'
                    : clientsOnly
                      ? 'Synthetic client settings, contact restoration through the session-authenticated API, archive confirmation and create-client dialog, plus portal controls at every required width.'
                      : tasksOnly
                        ? 'Synthetic native project-manager paginated task list and project preview, create dialog, editor with ten assignees and stale conflict, and read-only paginated portal tasks at every required width.'
                        : portalOnly
                          ? 'Synthetic portal contacts and invitations on client settings; initial, project selection, removal and invitation withdrawal confirmation states at every required width.'
                          : squareOnly
                            ? 'Synthetic Square explore, linked operator invoice and linked client portal invoice at every required width.'
                            : remindersOnly
                              ? 'Synthetic payment reminders: an operator invoice with its reminder log and send confirmation, and the client cadence switch on settings, at every required width.'
                              : backlogOnly
                                ? 'Synthetic deferred backlog on the operator agreement page, with an unbroken client and agreement name and a shelf priced in the millions, at every required width.'
                                : engagementOnly
                                  ? 'Synthetic proposal draft, agreement activation confirmation, agreement rename and terms at every required width. No provider requests or ledger mutations.'
                                  : 'Synthetic local fixtures only. Invoice review/settings/proposal/time/expenses/operations are asserted.',

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
