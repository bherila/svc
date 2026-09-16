/* global process */
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';

const repository = fileURLToPath(new URL('../../', import.meta.url));
const validator = path.join(
    repository,
    'scripts/deploy/verify-oauth-redirect.mjs',
);
const valid = new URL('https://id.bherila.net/oauth/authorize');
const privateValues = [
    'SYNTHETIC_CLIENT_ID',
    'SYNTHETIC_STATE',
    'A'.repeat(43),
];
const parameters = {
    response_type: 'code',
    client_id: privateValues[0],
    redirect_uri: 'https://svc.bherila.net/oauth/callback',
    scope: 'identity:read',
    state: privateValues[1],
    code_challenge: privateValues[2],
    code_challenge_method: 'S256',
};
valid.search = new URLSearchParams(parameters).toString();

function validate(redirect) {
    return spawnSync(process.execPath, [validator], {
        input: redirect,
        encoding: 'utf8',
    });
}

function assertRejected(redirect) {
    const result = validate(redirect);
    assert.equal(result.status, 1);
    assert.equal(result.stdout, '');
    assert.equal(
        result.stderr,
        'OAuth redirect failed the authorization-code and PKCE contract.\n',
    );

    for (const value of privateValues) {
        assert.ok(!`${result.stdout}${result.stderr}`.includes(value));
    }
}

test('valid encoded authorization-code and S256 redirect passes silently', () => {
    const result = validate(valid.href);
    assert.equal(result.status, 0);
    assert.equal(result.stdout, '');
    assert.equal(result.stderr, '');
});

for (const key of Object.keys(parameters)) {
    test(`missing ${key} is rejected`, () => {
        const url = new URL(valid);
        url.searchParams.delete(key);
        assertRejected(url.href);
    });
    test(`empty ${key} is rejected`, () => {
        const url = new URL(valid);
        url.searchParams.set(key, '');
        assertRejected(url.href);
    });
    test(`duplicate ${key} is rejected even with identical values`, () => {
        const url = new URL(valid);
        url.searchParams.append(key, parameters[key]);
        assertRejected(url.href);
    });
}

const invalidParameters = [
    ['response_type', 'token'],
    ['client_id', ' '],
    ['state', ' '],
    ['redirect_uri', 'https://example.test/oauth/callback'],
    ['redirect_uri', 'http://svc.bherila.net/oauth/callback'],
    ['redirect_uri', 'https://svc.bherila.net/oauth/callback/'],
    ['scope', 'billing:write'],
    ['scope', 'identity:read billing:write'],
    ['code_challenge_method', 'plain'],
    ['code_challenge', 'short'],
    ['code_challenge', `${'A'.repeat(42)}=`],
];

for (const [index, [key, value]] of invalidParameters.entries()) {
    test(`incorrect ${key} contract is rejected (${index})`, () => {
        const url = new URL(valid);
        url.searchParams.set(key, value);
        assertRejected(url.href);
    });
}

const malformed = [
    '',
    'not a URL',
    `${valid.href}#fragment`,
    `${valid.href}#`,
    `${valid.href}&invalid=%zz`,
    `${valid.href}\n`,
    valid.href.replace('https:', 'http:'),
    valid.href.replace('id.bherila.net', 'id.bherila.net.example.test'),
    valid.href.replace('/oauth/authorize?', '/oauth/authorize/extra?'),
    valid.href.replace('https://', 'https://SYNTHETIC_USER@'),
    valid.href.replace('id.bherila.net', 'id.bherila.net:444'),
    `${valid.href}&padding=${'A'.repeat(16384)}`,
];

for (const [index, redirect] of malformed.entries()) {
    test(`malformed endpoint or query is rejected (${index})`, () => {
        assertRejected(redirect);
    });
}

test('encoded duplicate query keys cannot bypass uniqueness checks', () => {
    assertRejected(`${valid.href}&%73tate=SYNTHETIC_STATE`);
});

test('live verification rejects an invalid contract before credential issuance', () => {
    const fixture = mkdtempSync(path.join(tmpdir(), 'svc-oauth-redirect-'));
    const eventLog = path.join(fixture, 'events');

    try {
        writeFileSync(
            path.join(fixture, 'curl'),
            '#!/usr/bin/env bash\ncase "${!#}" in\n  */oauth/redirect) printf "%s" "$FIXTURE_REDIRECT" ;;\n  */) printf "200 text/html" ;;\n  *) exit 92 ;;\nesac\n',
            { mode: 0o700 },
        );
        writeFileSync(
            path.join(fixture, 'ssh'),
            '#!/usr/bin/env bash\nprintf "ssh-called\\n" >>"$FIXTURE_EVENTS"\nexit 91\n',
            { mode: 0o700 },
        );
        writeFileSync(eventLog, '');
        const environment = {
            ...process.env,
            PATH: `${fixture}:${process.env.PATH}`,
            DEPLOYMENT_MODE: 'atomic',
            DEPLOY_SSH_TARGET: 'fixture-target',
            DEPLOY_PHP_BINARY: '/fixture/php',
            DEPLOY_STABLE_DIR: 'svc-laravel',
            DEPLOY_SITE_URL: 'https://svc.bherila.net',
            DEPLOY_SOURCE_COMMIT: 'a'.repeat(40),
            DEPLOY_LIVE_COMMIT: 'a'.repeat(40),
            DEPLOY_LIVE_STATE: 'serving',
            FIXTURE_EVENTS: eventLog,
        };
        const invalid = new URL(valid);
        invalid.searchParams.set('code_challenge_method', 'plain');
        const result = spawnSync('bash', ['scripts/deploy/verify-live.sh'], {
            cwd: repository,
            encoding: 'utf8',
            env: { ...environment, FIXTURE_REDIRECT: invalid.href },
        });
        assert.equal(result.status, 1);
        assert.equal(readFileSync(eventLog, 'utf8'), '');

        for (const value of privateValues) {
            assert.ok(!`${result.stdout}${result.stderr}`.includes(value));
        }

        // A valid redirect reaches the SSH credential boundary. The deliberate
        // transport failure also exercises the existing armed revocation trap.
        const accepted = spawnSync('bash', ['scripts/deploy/verify-live.sh'], {
            cwd: repository,
            encoding: 'utf8',
            env: { ...environment, FIXTURE_REDIRECT: valid.href },
        });
        assert.equal(accepted.status, 91);
        assert.equal(
            readFileSync(eventLog, 'utf8'),
            'ssh-called\nssh-called\n',
        );
    } finally {
        rmSync(fixture, { recursive: true, force: true });
    }
});
