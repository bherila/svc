#!/usr/bin/env node
/* global process */
import { mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium, expect } from '@playwright/test';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import { createServer } from 'vite';
import { accessibilityFindings } from './layout/a11y.mjs';

// Geometry needs a browser: jsdom cannot prove the popup avoids its trigger.
const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const artifacts = path.join(root, 'test-results', 'select');
mkdirSync(artifacts, { recursive: true });
const runtime = mkdtempSync(path.join(artifacts, 'browser-'));
const runtimePath =
    '/' + path.relative(root, runtime).split(path.sep).join('/');
writeFileSync(
    path.join(runtime, 'index.html'),
    `<!doctype html><html lang="en"><head><title>Synthetic select geometry</title><meta name="viewport" content="width=device-width,initial-scale=1"></head><body><div id="app"></div><script type="module" src="${runtimePath}/entry.tsx"></script></body></html>`,
);
writeFileSync(
    path.join(runtime, 'entry.tsx'),
    `
import React from 'react';
import {createRoot} from 'react-dom/client';
import {createInertiaApp} from '@inertiajs/react';
import WorkspaceShell from '@/layouts/workspace-shell';
import {SHELL_CONTAINER} from '@/lib/layout';
import {sharedPageProps} from '@/test/shared-page-props';
import {workspaceNavigation} from '@/test/workspace-navigation';
import {Button} from '@/components/ui/button';
import {Dialog,DialogTrigger,DialogContent,DialogTitle,DialogDescription} from '@/components/ui/dialog';
import {Select,SelectTrigger,SelectValue,SelectContent,SelectItem} from '@/components/ui/select';
import '@/../css/app.css';
const items=Array.from({length:80},(_,i)=>({value:String(i+1),label:'Synthetic choice '+String(i+1).padStart(2,'0')}));
const props=sharedPageProps({workspaceNavigation:workspaceNavigation({permissions:{manage_workspace:false,create_client:false,manage_current_client:false,search:false}})});
function Page(){return <WorkspaceShell activeModule="tasks"><main className={SHELL_CONTAINER+' grid grid-cols-1 gap-6 py-6'}><h1 className="text-2xl font-semibold">Synthetic select geometry</h1><Dialog><DialogTrigger render={<Button/>}>Open choice dialog</DialogTrigger><DialogContent style={{top:'auto',bottom:16,transform:'none',translate:'-50% 0'}}><DialogTitle>Choose a synthetic item</DialogTitle><DialogDescription>The long list must stay visible beside the field.</DialogDescription><div className="h-20"/><Select items={items} defaultValue="40"><SelectTrigger aria-label="Synthetic choice" className="w-full"><SelectValue/></SelectTrigger><SelectContent>{items.map(item=><SelectItem key={item.value} value={item.value}>{item.label}</SelectItem>)}</SelectContent></Select></DialogContent></Dialog></main></WorkspaceShell>;}
createInertiaApp({page:{component:'SelectGeometry',props,url:location.pathname,version:null,clearHistory:false,encryptHistory:false},resolve:()=>Page,setup({el,App,props}){createRoot(el).render(<App {...props}/>);}});
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
    const origin = `http://127.0.0.1:${server.httpServer.address().port}`;
    browser = await chromium.launch();

    for (const width of [390, 820, 1440, 1920]) {
        for (const theme of ['light', 'dark']) {
            const page = await browser.newPage({
                viewport: { width, height: 800 },
                colorScheme: theme,
            });
            const errors = [];
            page.on('pageerror', (error) => errors.push(error.message));
            await page.goto(origin + runtimePath + '/index.html');
            await page
                .locator('html')
                .evaluate(
                    (html, dark) => html.classList.toggle('dark', dark),
                    theme === 'dark',
                );
            await page
                .getByRole('button', { name: 'Open choice dialog' })
                .click();
            const dialog = page.getByRole('dialog');
            await expect(dialog).toBeVisible();
            const trigger = page.getByRole('combobox', {
                name: 'Synthetic choice',
            });
            await expect(
                trigger.locator('[data-slot="select-value"]'),
            ).toHaveText('Synthetic choice 40');
            // Axe scans the named dialog before and after the transient listbox.
            // Its region rule treats body-portaled options as static page content;
            // the open popup has separate geometry, keyboard and screenshot checks.
            expect(
                await accessibilityFindings(
                    page,
                    `select-dialog/${width}/${theme}`,
                ),
            ).toEqual([]);
            await trigger.click();
            const list = page.getByRole('listbox');
            const popup = page.locator('[data-slot="select-content"]');
            await expect(list).toBeVisible();
            await expect(popup).toHaveAttribute('data-side', 'top');
            await expect(popup).toHaveAttribute('data-align-trigger', 'false');
            await popup.evaluate(async (element) => {
                await Promise.all(
                    element
                        .getAnimations({ subtree: true })
                        .map((animation) => animation.finished),
                );
            });
            const triggerRect = await trigger.boundingBox();
            const popupRect = await popup.boundingBox();
            expect(triggerRect.y).toBeGreaterThan(700);
            expect(popupRect.y).toBeGreaterThanOrEqual(0);
            expect(popupRect.y + popupRect.height).toBeLessThanOrEqual(
                triggerRect.y - 3,
            );
            expect(popupRect.x).toBeGreaterThanOrEqual(0);
            expect(popupRect.x + popupRect.width).toBeLessThanOrEqual(width);
            const geometry = await page.evaluate(() => ({
                documentWidth: document.documentElement.scrollWidth,
                navbarHeight:
                    document
                        .querySelector('header')
                        ?.firstElementChild?.getBoundingClientRect().height ??
                    null,
            }));
            expect(geometry.documentWidth).toBeLessThanOrEqual(width);
            expect(geometry.navbarHeight).toBe(48);
            const screenshot = `select-${width}-${theme}.png`;
            await page.screenshot({
                path: path.join(artifacts, screenshot),
                fullPage: true,
            });
            await page.keyboard.press('Home');
            await page.keyboard.press('ArrowDown');
            await page.keyboard.press('Enter');
            await expect(list).toBeHidden();
            await expect(
                trigger.locator('[data-slot="select-value"]'),
            ).toHaveText('Synthetic choice 02');
            await expect(trigger).toBeFocused();
            await page.keyboard.press('ArrowDown');
            await expect(list).toBeVisible();
            await page.keyboard.press('End');
            await page.keyboard.press('Escape');
            await expect(list).toBeHidden();
            await expect(dialog).toBeVisible();
            expect(
                await accessibilityFindings(
                    page,
                    `select-escaped/${width}/${theme}`,
                ),
            ).toEqual([]);
            await expect(
                trigger.locator('[data-slot="select-value"]'),
            ).toHaveText('Synthetic choice 02');
            await expect(trigger).toBeFocused();
            await page.keyboard.press('Escape');
            await expect(dialog).toBeHidden();
            await expect(
                page.getByRole('button', { name: 'Open choice dialog' }),
            ).toBeFocused();
            expect(errors).toEqual([]);
            results.push({
                width,
                theme,
                ...geometry,
                trigger: triggerRect,
                popup: popupRect,
                screenshot,
                keyboardAndEscape: true,
            });
            await page.close();
        }
    }

    writeFileSync(
        path.join(artifacts, 'measurements.json'),
        JSON.stringify(results, null, 2) + '\n',
    );
    process.stdout.write(
        JSON.stringify({ checks: results.length, passed: true, artifacts }) +
            '\n',
    );
} finally {
    await browser?.close();
    await server?.close();
    rmSync(runtime, { recursive: true, force: true });
}
