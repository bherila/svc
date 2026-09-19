#!/usr/bin/env node
/* global process */
// Read the redirect from stdin, not argv or logs: state/client/PKCE values are private.
import { readFileSync } from 'node:fs';

try {
    const redirect = readFileSync(0, 'utf8');

    if (
        redirect.length === 0 ||
        redirect.length > 16384 ||
        [...redirect].some(
            (character) =>
                character.codePointAt(0) <= 32 ||
                character.codePointAt(0) === 127,
        ) ||
        /%(?![\da-f]{2})/iu.test(redirect) ||
        redirect.includes('#')
    ) {
        throw new Error();
    }

    const url = new URL(redirect);
    const parameters = url.searchParams;
    const required = [
        'response_type',
        'client_id',
        'redirect_uri',
        'scope',
        'state',
        'code_challenge',
        'code_challenge_method',
    ];

    if (
        url.origin !== 'https://id.bherila.net' ||
        url.pathname !== '/oauth/authorize' ||
        url.username !== '' ||
        url.password !== '' ||
        required.some((key) => parameters.getAll(key).length !== 1) ||
        parameters.get('response_type') !== 'code' ||
        parameters.get('redirect_uri') !==
            'https://svc.bherila.net/oauth/callback' ||
        parameters.get('scope') !== 'identity:read' ||
        parameters.get('client_id').trim() === '' ||
        parameters.get('state').trim() === '' ||
        !/^[A-Za-z0-9_-]{43}$/u.test(parameters.get('code_challenge')) ||
        parameters.get('code_challenge_method') !== 'S256'
    ) {
        throw new Error();
    }
} catch {
    // Never log the URL, query values or an exception that could contain them.
    process.stderr.write(
        'OAuth redirect failed the authorization-code and PKCE contract.\n',
    );
    process.exitCode = 1;
}
