/* global process */
import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { mkdtempSync, mkdirSync, rmSync, writeFileSync } from 'node:fs';
import http from 'node:http';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';

const verifier = fileURLToPath(
    new URL('../../scripts/deploy/verify-hashed-assets.mjs', import.meta.url),
);
const js = 'assets/app-Ab12Cd34.js';
const css = 'assets/app-Zy98Xw76.css';
const manifest = {
    'resources/js/app.tsx': { file: js, isEntry: true, css: [css] },
    'resources/css/app.css': { file: css, isEntry: true },
};
const page = `<html><link href="/build/${css}"><script src="/build/${js}"></script></html>`;

async function audit({ routes, files = manifest }) {
    const directory = mkdtempSync(path.join(tmpdir(), 'assets-'));
    mkdirSync(directory, { recursive: true });
    const manifestPath = path.join(directory, 'manifest.json');
    writeFileSync(manifestPath, JSON.stringify(files));
    const server = http.createServer((request, response) => {
        const route = routes[request.url] ?? { status: 404, type: 'text/html', body: '<h1>Not found</h1>' };
        response.writeHead(route.status ?? 200, { 'content-type': route.type, ...route.headers });
        response.end(route.body);
    });
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    const { port } = server.address();

    try {
        return await new Promise((resolve) => {
            const child = spawn(process.execPath, [verifier], {
                env: {
                    ...process.env,
                    ASSET_SITE_URL: `http://127.0.0.1:${port}`,
                    ASSET_MANIFEST_PATH: manifestPath,
                    ASSET_TEST_ALLOW_LOOPBACK_HTTP: '1',
                },
            });
            let stderr = '';
            child.stderr.on('data', (chunk) => (stderr += chunk));
            child.on('close', (code) => resolve({ code, stderr }));
        });
    } finally {
        server.close();
        rmSync(directory, { recursive: true, force: true });
    }
}

const healthy = {
    '/': { type: 'text/html; charset=utf-8', body: page },
    [`/build/${js}`]: { type: 'text/javascript', body: 'export {};' },
    [`/build/${css}`]: { type: 'text/css', body: 'body{}' },
};

test('accepts a page whose hashed assets are served with the right types', async () => {
    const result = await audit({ routes: healthy });
    assert.equal(result.code, 0, result.stderr);
});

test('rejects a missing hashed asset served as an HTML 404', async () => {
    const routes = { ...healthy };
    delete routes[`/build/${js}`];
    const result = await audit({ routes });
    assert.equal(result.code, 1);
    assert.match(result.stderr, /HTTP 404/u);
});

test('rejects a wrong MIME type', async () => {
    const result = await audit({
        routes: { ...healthy, [`/build/${css}`]: { type: 'text/html', body: 'body{}' } },
    });
    assert.equal(result.code, 1);
    assert.match(result.stderr, /wrong MIME/u);
});

test('rejects a redirected asset instead of following it', async () => {
    const result = await audit({
        routes: {
            ...healthy,
            [`/build/${js}`]: { status: 302, type: 'text/plain', body: '', headers: { location: '/' } },
        },
    });
    assert.equal(result.code, 1);
    assert.match(result.stderr, /HTTP 302/u);
});

test('rejects identity drift: the page references a different build', async () => {
    const result = await audit({
        routes: { ...healthy, '/': { type: 'text/html', body: page.replaceAll('Ab12Cd34', 'Old00000') } },
    });
    assert.equal(result.code, 1);
    assert.match(result.stderr, /not this release/u);
});

test('rejects a manifest entry that is not content-hashed', async () => {
    const result = await audit({
        routes: healthy,
        files: { 'resources/js/app.tsx': { file: 'assets/app.js', isEntry: true, css: [css] } },
    });
    assert.equal(result.code, 1);
    assert.match(result.stderr, /not a content-hashed/u);
});

test('failure output never echoes response bodies', async () => {
    const result = await audit({
        routes: { ...healthy, [`/build/${js}`]: { status: 500, type: 'text/plain', body: 'SECRET_BODY' } },
    });
    assert.equal(result.code, 1);
    assert.doesNotMatch(result.stderr, /SECRET_BODY/u);
});
