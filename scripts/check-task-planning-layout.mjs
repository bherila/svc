#!/usr/bin/env node
/* global process */
import { spawnSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import {
    mkdirSync,
    mkdtempSync,
    writeFileSync,
    readFileSync,
    rmSync,
} from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium, expect } from '@playwright/test';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import { createServer } from 'vite';

// A focused presentation check: Vite serves synthetic props directly. No build,
// database, production data, PHPStan, full tests or mutation run is involved.
const integrated = process.argv.includes('--integrated');
const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const artifactsRoot = path.join(root, 'test-results', 'task-planning');
mkdirSync(artifactsRoot, { recursive: true });
const runtime = mkdtempSync(path.join(artifactsRoot, 'browser-'));
const runtimePath =
    '/' + path.relative(root, runtime).split(path.sep).join('/');
const label = (i) => ({
    id: `synthetic-label-${i}`,
    name: `Synthetic label ${i} ${'Üñîçødé'.repeat(8)}`,
    is_archived: i === 0,
    version: 'l'.repeat(64),
});
const contact = (i) => ({
    id: `synthetic-contact-${i}`,
    display_name: `Synthetic contact ${i} ${'Üñîçødé'.repeat(25)}`,
    is_archived: i === 0,
    version: 'c'.repeat(64),
});
const reference = (i) => ({
    id: `synthetic-task-${i}`,
    title: `Synthetic prerequisite ${i} ${'UnbrokenSynthetic'.repeat(12)}`,
    status: i === 0 ? 'completed' : 'in_progress',
    version: 'r'.repeat(64),
    web_url: `/synthetic/reference-${i}`,
});
const page = (data) => ({
    data,
    next_href: '/synthetic/continuation',
    previous_href: null,
});
const details = {
    task_id: 'synthetic-source',
    version: 'v'.repeat(64),
    dependency_summary: {
        blocked: true,
        incomplete_prerequisite_count: 24,
        dependent_count: 30,
    },
    labels: {
        selected: Array.from({ length: 20 }, (_, i) => label(i)),
        options: page([label(21)]),
        options_href: '/synthetic/label-options',
        replace_href: '/synthetic/labels',
    },
    dependencies: {
        data: {
            task_id: 'synthetic-source',
            version: 'v'.repeat(64),
            graph_version: 'g'.repeat(64),
            prerequisites: Array.from({ length: 25 }, (_, i) => reference(i)),
            dependents: [reference(30)],
        },
        read_href: '/synthetic/dependencies',
        next_href: '/synthetic/incoming',
        options: page([reference(31)]),
        options_href: '/synthetic/dependency-options',
        replace_href: '/synthetic/prerequisites',
        unsupported_notice: null,
    },
    contacts: {
        selected: Array.from({ length: 10 }, (_, i) => contact(i)),
        version: 'v'.repeat(64),
        options: page([contact(11)]),
        options_href: '/synthetic/contact-options',
        replace_href: '/synthetic/contacts',
    },
    label_catalog_href: '/synthetic/catalog',
};
const catalog = {
    workspace: { id: 'synthetic-workspace', name: 'Synthetic workspace' },
    catalog: {
        labels: [0, 1].map((i) => ({
            ...label(i),
            update_href: i === 0 ? null : '/synthetic/update',
            archive_href: i === 0 ? null : '/synthetic/archive',
            restore_href: i === 0 ? '/synthetic/restore' : null,
        })),
        filters: { status: 'all', q: '' },
        search_href: '/synthetic/catalog',
        create_href: '/synthetic/create',
        next_href: '/synthetic/next',
        previous_href: null,
    },
};
writeFileSync(
    path.join(runtime, 'index.html'),
    '<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head><body><div id="app"></div><script type="module" src="' +
        runtimePath +
        '/entry.tsx"></script></body></html>',
);
writeFileSync(
    path.join(runtime, 'entry.tsx'),
    `
import React from 'react';
import {createRoot} from 'react-dom/client';
import {createInertiaApp} from '@inertiajs/react';
import {TaskPlanningPanel} from '@/components/tasks/task-planning-panel';
import TaskLabelsPage from '@/pages/clients/task-labels';
import TaskPage from '@/pages/clients/task';
${integrated ? "import controllerPage from './controller-props.json';" : ''}
import WorkspaceShell from '@/layouts/workspace-shell';
import {SHELL_CONTAINER} from '@/lib/layout';
import {sharedPageProps} from '@/test/shared-page-props';
import {workspaceNavigation} from '@/test/workspace-navigation';
import '@/../css/app.css';
const state=new URLSearchParams(location.search).get('state') || 'full';
let details=${JSON.stringify(details)};
if(state==='empty'){details.labels.selected=[];details.labels.options.data=[];details.labels.options.next_href=null;details.dependencies.data.prerequisites=[];details.dependencies.data.dependents=[];details.dependencies.options.data=[];details.dependencies.options.next_href=null;details.dependencies.next_href=null;details.contacts.selected=[];details.contacts.options.data=[];details.contacts.options.next_href=null;details.dependency_summary.blocked=false;}
if(state==='readonly'){delete details.contacts;details.label_catalog_href=null;details.labels.replace_href=null;details.labels.options=null;details.dependencies.replace_href=null;details.dependencies.options=null;}
if(state==='unsupported'){details.dependencies.data=null;details.dependencies.options=null;details.dependencies.replace_href=null;details.dependencies.unsupported_notice='This project exceeds the supported dependency size. Labels and contact responsibility remain available.';}
if(state==='stale'){details.labels.selected=[];details.labels.options.data=[{...details.labels.options.data[0],name:'Synthetic selectable label'}];}
const originalFetch=window.fetch.bind(window);if(!state.startsWith('integrated'))window.fetch=(url,opts)=>String(url).includes('/synthetic/')?Promise.resolve(new Response(JSON.stringify({message:'Synthetic stale revision'}),{status:409,headers:{'Content-Type':'application/json'}})):originalFetch(url,opts);
const props={...sharedPageProps({workspaceNavigation:workspaceNavigation({permissions:{manage_workspace:false,create_client:false,manage_current_client:false,search:false}})}),...${JSON.stringify(catalog)}};
function Page(props){if(state.startsWith('integrated'))return <TaskPage {...props}/>;return state==='catalog'?<TaskLabelsPage {...props}/>:<WorkspaceShell activeModule="tasks"><main className={SHELL_CONTAINER+' grid grid-cols-1 gap-6 py-6'}><h1 className="text-2xl font-semibold">Synthetic task planning</h1><TaskPlanningPanel details={details} onRefresh={async()=>{}}/></main></WorkspaceShell>;}
const initialPage=${integrated ? '{...controllerPage,props:{...props,...controllerPage.props}}' : "{component:'SyntheticPlanning',props,url:location.pathname+location.search,version:null,clearHistory:false,encryptHistory:false}"};
if(state.startsWith('integrated'))history.replaceState(null,'',initialPage.url);
createInertiaApp({page:initialPage,resolve:()=>Page,setup({el,App,props}){createRoot(el).render(<App {...props}/>);}});
`,
);
let server;
let browser;
const results = [];

try {
    server = await createServer({
        configFile: false,
        root,
        plugins: [react(), tailwindcss()],
        resolve: { alias: { '@': path.join(root, 'resources/js') } },
        server: { host: '127.0.0.1', port: 0, strictPort: false },
        logLevel: 'error',
    });
    await server.listen();
    const address = server.httpServer.address();
    const origin = `http://127.0.0.1:${address.port}`;
    let controllerPage;

    if (integrated) {
        for (const folder of [
            'framework/views',
            'framework/sessions',
            'framework/cache/data',
            'logs',
        ]) {
            mkdirSync(path.join(runtime, 'storage', folder), {
                recursive: true,
            });
        }

        writeFileSync(
            path.join(runtime, 'harness.json'),
            JSON.stringify({
                host: address.address + ':' + address.port,
                token: 'synthetic-browser-only',
            }),
            { mode: 0o600 },
        );
        writeFileSync(path.join(runtime, 'database.sqlite'), '', {
            mode: 0o600,
        });
        writeFileSync(path.join(runtime, '.env'), '', { mode: 0o600 });
        const seeded = spawnSync(
            'php',
            ['scripts/layout/task-planning-props.php'],
            {
                cwd: root,
                env: {
                    PATH: process.env.PATH,
                    HOME: process.env.HOME,
                    APP_ENV: 'testing',
                    APP_DEBUG: 'true',
                    APP_KEY: 'base64:' + randomBytes(32).toString('base64'),
                    APP_URL: origin,
                    APP_CONFIG_CACHE: path.join(runtime, 'config.php'),
                    DB_CONNECTION: 'sqlite',
                    DB_DATABASE: path.join(runtime, 'database.sqlite'),
                    SESSION_DRIVER: 'array',
                    CACHE_STORE: 'array',
                    MAIL_MAILER: 'array',
                    QUEUE_CONNECTION: 'sync',
                    TASK_PLANNING_DETAILS_ENABLED: 'true',
                    SVC_LAYOUT_RUNTIME: runtime,
                },
                encoding: 'utf8',
            },
        );

        if (seeded.status !== 0) {
            throw new Error(
                'Synthetic controller props failed: ' +
                    seeded.stdout +
                    seeded.stderr,
            );
        }

        controllerPage = JSON.parse(
            readFileSync(path.join(runtime, 'controller-props.json'), 'utf8'),
        );
    }

    browser = await chromium.launch({ headless: true });

    for (const width of [390, 820, 1440, 1920]) {
        const context = await browser.newContext({
            viewport: { width, height: 1000 },
        });

        for (const state of integrated
            ? ['integrated', 'integrated-saved']
            : [
                  'full',
                  'empty',
                  'readonly',
                  'unsupported',
                  'stale',
                  'catalog',
              ]) {
            const browserPage = await context.newPage();
            const errors = [];
            browserPage.on('pageerror', (error) => errors.push(error.message));
            const captured = [];
            let refreshed = false;

            if (integrated) {
                const task = controllerPage.props.task;
                const planning = controllerPage.props.planning_details;
                await browserPage.route('**/workspaces/**', async (route) => {
                    const request = route.request();
                    captured.push({
                        method: request.method(),
                        url: request.url(),
                        partial: request.headers()['x-inertia-partial-data'],
                        body: request.postDataJSON(),
                    });

                    if (
                        request.method() === 'PUT' &&
                        request.url() === planning.labels.replace_href
                    ) {
                        expect(request.postDataJSON()).toEqual({
                            expected_version: planning.version,
                            label_ids: [],
                        });
                        await route.fulfill({
                            status: 200,
                            json: { data: { id: task.id } },
                        });
                    } else if (
                        request.method() === 'GET' &&
                        request.headers()['x-inertia-partial-data'] ===
                            'planning_details'
                    ) {
                        refreshed = true;
                        await route.fulfill({
                            status: 200,
                            headers: { 'X-Inertia': 'true' },
                            json: {
                                ...controllerPage,
                                props: {
                                    planning_details: {
                                        ...planning,
                                        version: 'n'.repeat(64),
                                        labels: {
                                            ...planning.labels,
                                            selected: [],
                                        },
                                    },
                                },
                            },
                        });
                    } else if (
                        request.method() === 'PATCH' &&
                        request.url() === task.update_action
                    ) {
                        expect(request.postDataJSON().expected_version).toBe(
                            task.version,
                        );
                        expect(request.postDataJSON().title).toBe(
                            'Synthetic unsaved task-body draft',
                        );
                        await route.fulfill({
                            status: 200,
                            headers: { 'X-Inertia': 'true' },
                            json: {
                                ...controllerPage,
                                props: {
                                    ...controllerPage.props,
                                    planning_details: {
                                        ...planning,
                                        version: 'n'.repeat(64),
                                        labels: {
                                            ...planning.labels,
                                            selected: [],
                                        },
                                    },
                                    errors: {
                                        expected_version:
                                            'Synthetic task version remains captured.',
                                    },
                                },
                            },
                        });
                    } else {
                        await route.abort();
                    }
                });
            }

            await browserPage.goto(
                origin + runtimePath + '/index.html?state=' + state,
            );
            await expect(browserPage.locator('main')).toBeVisible();
            await browserPage.evaluate(() => document.fonts.ready);

            if (integrated) {
                await expect(
                    browserPage.getByLabel('Title', { exact: true }),
                ).toHaveValue(controllerPage.props.task.title);
                await expect(
                    browserPage.getByText('Task labels', { exact: true }),
                ).toBeVisible();
                await browserPage.waitForTimeout(250);
                expect(captured).toEqual([]);

                if (state === 'integrated-saved') {
                    await browserPage
                        .getByLabel('Title', { exact: true })
                        .fill('Synthetic unsaved task-body draft');
                    await browserPage
                        .getByRole('button', {
                            name: 'Remove Synthetic controller label',
                        })
                        .click();
                    await browserPage
                        .getByRole('button', {
                            name: 'Save labels',
                            exact: true,
                        })
                        .click();
                    await expect.poll(() => refreshed).toBe(true);
                    await expect(
                        browserPage.getByRole('button', {
                            name: 'Save labels',
                            exact: true,
                        }),
                    ).toBeDisabled();
                    await expect(
                        browserPage.getByLabel('Title', { exact: true }),
                    ).toHaveValue('Synthetic unsaved task-body draft');
                    await browserPage
                        .getByRole('button', { name: 'Save task', exact: true })
                        .click();
                    await expect
                        .poll(() =>
                            captured.some(
                                (request) => request.method === 'PATCH',
                            ),
                        )
                        .toBe(true);
                }
            }

            if (state === 'stale') {
                await browserPage
                    .getByRole('checkbox', {
                        name: 'Synthetic selectable label',
                    })
                    .check();
                await browserPage
                    .getByRole('button', { name: 'Save labels', exact: true })
                    .click();
                await expect(browserPage.getByRole('alert')).toContainText(
                    'Your choices are preserved',
                );
            }

            await browserPage.evaluate(() => scrollTo(0, 0));
            const dimensions = await browserPage.evaluate(() => ({
                document: document.documentElement.scrollWidth,
                viewport: innerWidth,
                navbar: document
                    .querySelector('header')
                    ?.getBoundingClientRect().height,
            }));
            expect(dimensions.document).toBeLessThanOrEqual(width);
            expect(dimensions.navbar).toBeGreaterThanOrEqual(48);
            expect(dimensions.navbar).toBeLessThanOrEqual(50);
            expect(errors).toEqual([]);
            const screenshot = path.join(runtime, `${state}-${width}.png`);
            await browserPage.screenshot({ path: screenshot, fullPage: true });
            results.push({
                state,
                width,
                ...dimensions,
                screenshot,
                ...(integrated
                    ? {
                          captured,
                          controller_component: controllerPage.component,
                      }
                    : {}),
            });
            await browserPage.close();
        }

        await context.close();
    }

    writeFileSync(
        path.join(runtime, 'results.json'),
        JSON.stringify(results, null, 2) + '\n',
    );
    process.stdout.write(
        JSON.stringify({
            result: 'passed',
            screenshots: results.length,
            artifacts: runtime,
        }) + '\n',
    );
} finally {
    await browser?.close();
    await server?.close();
    rmSync(path.join(runtime, 'index.html'), { force: true });
    rmSync(path.join(runtime, 'entry.tsx'), { force: true });
}
