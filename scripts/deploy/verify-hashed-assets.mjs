#!/usr/bin/env node
/* global process */
// Proves the served public root is the release that was built: the runner's Vite manifest names
// the hashed entry JS and CSS, the rendered home page must reference exactly those files, and each
// file must be fetchable with the right MIME type. A stale or wrong document root, a view cache
// that points at an older build, or an HTML 404 served for a missing asset all fail here.
//
// Output is fixed text: never a response body, URL query or manifest content.
import { Buffer } from 'node:buffer';
import { existsSync, readFileSync } from 'node:fs';

const siteUrl = process.env.ASSET_SITE_URL ?? '';
const manifestPaths = process.env.ASSET_MANIFEST_PATH
    ? [process.env.ASSET_MANIFEST_PATH]
    : ['public/build/manifest.json', 'public/build/.vite/manifest.json'];
const jsEntry = 'resources/js/app.tsx';
const cssEntry = 'resources/css/app.css';
const maxBytes = 8 * 1024 * 1024;
const hashed = /^assets\/[A-Za-z0-9._-]+-[A-Za-z0-9_-]{8,}\.(js|css)$/u;
const mime = {
    js: /^(text|application)\/(javascript|x-javascript|ecmascript)(\s*;|$)/iu,
    css: /^text\/css(\s*;|$)/iu,
};

class AuditFailure extends Error {}

function fail(message) {
    throw new AuditFailure(message);
}

async function get(url, label) {
    let response;

    try {
        // Redirects are never followed: a CDN or vhost rewrite must not mask a missing file.
        response = await fetch(url, {
            redirect: 'manual',
            signal: AbortSignal.timeout(20000),
        });
    } catch {
        fail(`${label} could not be fetched.`);
    }

    if (response.status !== 200) {
        fail(`${label} returned HTTP ${response.status}, expected 200.`);
    }

    const reader = response.body?.getReader();
    const chunks = [];
    let size = 0;

    while (reader) {
        const { done, value } = await reader.read();

        if (done) {
            break;
        }

        size += value.length;

        if (size > maxBytes) {
            await reader.cancel();
            fail(`${label} exceeded the ${maxBytes} byte limit.`);
        }

        chunks.push(value);
    }

    return {
        type: response.headers.get('content-type') ?? '',
        body: Buffer.concat(chunks),
    };
}

function readManifest() {
    const manifestPath = manifestPaths.find((candidate) => existsSync(candidate));

    if (manifestPath === undefined) {
        fail('The frontend build manifest is missing.');
    }

    try {
        return JSON.parse(readFileSync(manifestPath, 'utf8'));
    } catch {
        return fail('The frontend build manifest is not valid JSON.');
    }
}

function expectedAssets(manifest) {
    const entry = manifest?.[jsEntry];

    if (!entry || entry.isEntry !== true || typeof entry.file !== 'string') {
        fail('The manifest has no JavaScript entry.');
    }

    const cssFiles = new Set(Array.isArray(entry.css) ? entry.css : []);
    const css = manifest[cssEntry];

    if (css && typeof css.file === 'string') {
        cssFiles.add(css.file);
    }

    if (cssFiles.size === 0) {
        fail('The manifest has no CSS entry.');
    }

    const assets = [
        { kind: 'js', file: entry.file },
        ...[...cssFiles].map((file) => ({ kind: 'css', file })),
    ];

    for (const { kind, file } of assets) {
        if (typeof file !== 'string' || !hashed.test(file) || !file.endsWith(`.${kind}`)) {
            fail(`A ${kind} manifest entry is not a content-hashed asset path.`);
        }
    }

    return assets;
}

try {
    const base = new URL(siteUrl);
    // Plain HTTP exists only so the contract test can use a loopback server.
    const loopbackTest =
        process.env.ASSET_TEST_ALLOW_LOOPBACK_HTTP === '1' &&
        base.protocol === 'http:' &&
        base.hostname === '127.0.0.1';

    if (
        (base.protocol !== 'https:' && !loopbackTest) ||
        base.pathname !== '/' ||
        base.search !== ''
    ) {
        fail('ASSET_SITE_URL must be an https origin.');
    }

    const assets = expectedAssets(readManifest());
    const home = await get(new URL('/', base), 'The public home page');

    if (!/^text\/html(\s*;|$)/iu.test(home.type)) {
        fail('The public home page did not render HTML.');
    }

    const html = home.body.toString('utf8');

    for (const { kind, file } of assets) {
        if (!html.includes(`/build/${file}`)) {
            fail(`The rendered home page does not reference the built ${kind} asset; the served root is not this release.`);
        }

        const asset = await get(new URL(`/build/${file}`, base), `The built ${kind} asset`);

        if (!mime[kind].test(asset.type)) {
            fail(`The built ${kind} asset was served with the wrong MIME type.`);
        }

        if (asset.body.length === 0) {
            fail(`The built ${kind} asset is empty.`);
        }
    }
} catch (error) {
    // Only fixed messages from this script are printed; anything else is reported generically.
    process.stderr.write(
        `${error instanceof AuditFailure ? error.message : 'Hashed asset verification failed.'}\n`,
    );
    process.exitCode = 1;
}
