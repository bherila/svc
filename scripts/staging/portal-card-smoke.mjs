#!/usr/bin/env node
/* global process */

// Staging browser smoke: pay one seeded invoice in the portal with Stripe's TEST card.
//
//   STAGING_URL=https://<staging-host> \
//   STAGING_PORTAL_INVOICE_PATH=/portal/<company>/invoices/<invoice> \
//   STAGING_STORAGE_STATE=./staging-portal-state.json \
//   node scripts/staging/portal-card-smoke.mjs
//
// Sign-in goes through the identity provider, which a script cannot drive generically, so the
// portal session is supplied as a Playwright storage-state file captured once by hand:
//   pnpm exec playwright codegen --save-storage=staging-portal-state.json $STAGING_URL/login
// Keep that file out of git. Use a synthetic portal user (docs/runbooks/staging.md), never a real client.
//
// Skips cleanly (exit 0) when STAGING_URL is unset. It refuses a URL that is not https, and only ever
// types Stripe's documented test card 4242 4242 4242 4242 (override with STAGING_TEST_CARD for 3DS or decline numbers).
// Stripe's hosted Payment Element is an iframe whose inner markup is Stripe's: if Stripe changes it,
// adjust the field selectors below.

import { chromium } from '@playwright/test';

const url = process.env.STAGING_URL?.trim();

if (!url) {
    console.log('STAGING_URL is not set; skipping the staging card smoke.');
    process.exit(0);
}

const base = new URL(url);
const invoicePath = process.env.STAGING_PORTAL_INVOICE_PATH?.trim();
const storageState = process.env.STAGING_STORAGE_STATE?.trim();
const card = process.env.STAGING_TEST_CARD?.trim() || '4242'.repeat(4);

if (base.protocol !== 'https:') {
    throw new Error('STAGING_URL must be https; Stripe webhooks and the Payment Element need it.');
}

if (!invoicePath?.startsWith('/portal/') || !storageState) {
    throw new Error(
        'Set STAGING_PORTAL_INVOICE_PATH (/portal/<company>/invoices/<invoice>) and STAGING_STORAGE_STATE.',
    );
}

const browser = await chromium.launch();
const context = await browser.newContext({ storageState });
const page = await context.newPage();
const cspViolations = [];

page.on('console', (message) => {
    if (/content security policy/i.test(message.text())) {
        cspViolations.push(message.text());
    }
});

try {
    await page.goto(new URL(invoicePath, base).toString());
    await page.getByRole('button', { name: 'Pay by card' }).click();

    // The Payment Element renders in a Stripe iframe inside the "Card details" mount point.
    const frame = page
        .frameLocator('[aria-label="Card details"] iframe')
        .first();

    await frame.locator('input[name="number"]').fill(card);
    await frame.locator('input[name="expiry"]').fill('12 / 34');
    await frame.locator('input[name="cvc"]').fill('123');

    const postal = frame.locator('input[name="postalCode"]');

    if (await postal.isVisible().catch(() => false)) {
        await postal.fill('12345');
    }

    await page.getByRole('button', { name: 'Pay now' }).click();
    await page
        .getByRole('status')
        .filter({ hasText: 'Payment submitted' })
        .waitFor({ timeout: 60_000 });

    // The invoice turns paid when the webhook lands; reload until it does.
    const deadline = Date.now() + 120_000;
    let paid = false;

    while (!paid && Date.now() < deadline) {
        await page.reload();
        paid = await page
            .getByText('Paid', { exact: true })
            .isVisible()
            .catch(() => false);

        if (!paid) {
            await page.waitForTimeout(3_000);
        }
    }

    if (!paid) {
        throw new Error(
            'The invoice did not show Paid within 120 seconds: check the webhook health page and the Stripe dashboard event deliveries.',
        );
    }

    console.log('Invoice paid with the Stripe test card; webhook applied.');

    if (cspViolations.length > 0) {
        console.warn(
            `${cspViolations.length} CSP report-only message(s) in the console:\n${cspViolations.join('\n')}`,
        );
        process.exitCode = 1;
    }
} finally {
    await browser.close();
}
