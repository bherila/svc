#!/usr/bin/env node
/* global process */

import { readFileSync, readdirSync } from 'node:fs';

const manifest = JSON.parse(
    readFileSync(process.argv[2] ?? 'public/build/manifest.json', 'utf8'),
);
const isTest = /(?:^|\/)__tests__\/|\.(?:test|spec)(?:[.-]|$)/u;
const unexpected = Object.entries(manifest).filter(([key, entry]) =>
    [key, entry.src, entry.file, ...(entry.dynamicImports ?? [])].some(
        (value) => typeof value === 'string' && isTest.test(value),
    ),
);

if (!manifest['resources/js/app.tsx']) {
    throw new Error(
        'The production manifest is missing the application entry.',
    );
}

if (unexpected.length > 0) {
    throw new Error(
        `Test modules reached the production manifest: ${unexpected.map(([key]) => key).join(', ')}`,
    );
}

const missing = readdirSync('resources/js/pages', { recursive: true })
    .filter((file) => file.endsWith('.tsx') && !isTest.test(file))
    .filter((file) => !manifest[`resources/js/pages/${file}`]);

if (missing.length > 0) {
    throw new Error(
        `Application pages missing from the production manifest: ${missing.join(', ')}`,
    );
}

console.log(
    'Production page discovery: application pages present, no test modules.',
);
