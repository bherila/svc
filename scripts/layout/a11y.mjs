/* global document */
import AxeBuilder from '@axe-core/playwright';
import { expect } from '@playwright/test';
import { uiConsistencyRisks } from '../../resources/js/test/ui-consistency.ts';

/**
 * An axe scan of the page, run in the page itself.
 *
 * AxeBuilder's default mode runs `axe.runPartial` in each frame and then
 * opens a new blank page to run `axe.finishRun`, on every scan: about 300ms a
 * scan, most of the layout harness's time. Legacy mode runs `axe.run` in the
 * page and reaches same-origin iframes through axe's own frame messaging; it
 * skips only cross-origin frames, and the app renders no iframe at all. On a
 * page with known violations at the top level, in a dialog and inside an
 * iframe, both modes report the same rules on the same nodes.
 *
 * The harness reads only violations, so the scan asks axe for nodes of
 * those alone; passes and incomplete results keep a single example node.
 */
export function axeScan(page) {
    return new AxeBuilder({ page })
        .setLegacyMode()
        .options({ resultTypes: ['violations'] });
}

async function settle(page) {
    await page.evaluate(() => document.fonts.ready);
    await page.evaluate(
        () =>
            new Promise((resolve) =>
                requestAnimationFrame(() => requestAnimationFrame(resolve)),
            ),
    );
    await page
        .waitForFunction(() =>
            document
                .getAnimations()
                .every((animation) => animation.playState !== 'running'),
        )
        .catch(() => undefined);
}

/**
 * The accessibility gate shared by the focused layout checks.
 *
 * `check-layout.mjs` has its own copy of this inside `capture()`, because it
 * also records every finding in its report; the smaller scripts only need the
 * verdict. Same rules: any axe violation at any impact, and any
 * UI-consistency error, fails - in the light theme and again in the dark one.
 *
 * Returns the findings as strings (empty when clean) for the caller to push
 * onto its own failure list, so a script keeps its existing reporting.
 */
export async function accessibilityFindings(page, where) {
    await settle(page);
    const findings = [];
    const theme = await page.evaluate(() =>
        document.documentElement.classList.contains('dark') ? 'dark' : 'light',
    );
    const risks = (await uiConsistencyRisks(page)).filter(
        (risk) => risk.severity === 'error',
    );

    if (risks.length > 0) {
        findings.push(
            `${where} (${theme}): UI consistency: ${risks
                .slice(0, 5)
                .map((risk) => `${risk.rule} ${risk.detail}`)
                .join('; ')}`,
        );
    }

    const { violations } = await axeScan(page).analyze();

    for (const violation of violations) {
        // axe's `region` rule counts an open menu's popup as content outside a
        // landmark. The popup is a transient widget portaled to <body> by the
        // menu primitive, not page content, so that one pairing is not a finding.
        if (
            violation.id === 'region' &&
            violation.nodes.every((node) => node.html.includes('role="menu"'))
        ) {
            continue;
        }

        findings.push(
            `${where} (${theme}): axe ${violation.id} (${violation.impact}, ${violation.nodes.length} nodes: ${violation.nodes
                .slice(0, 3)
                .map(
                    (node) =>
                        `${node.target.join(' ')} [${node.html.slice(0, 140)}]`,
                )
                .join(', ')})`,
        );
    }

    return findings;
}

/**
 * Run the gate on the page as it stands, then again in the dark theme, then put
 * the light theme back. The theme follows the emulated colour scheme live, so an
 * open dialog stays open across the switch and is checked in both.
 */
export async function accessibilityFindingsBothThemes(page, where) {
    await page.emulateMedia({ colorScheme: 'light' });
    await expect(page.locator('html')).not.toHaveClass(/dark/);
    const findings = await accessibilityFindings(page, where);
    await page.emulateMedia({ colorScheme: 'dark' });

    try {
        await expect(page.locator('html')).toHaveClass(/dark/);
        findings.push(...(await accessibilityFindings(page, where)));
    } finally {
        await page.emulateMedia({ colorScheme: 'light' });
        await expect(page.locator('html')).not.toHaveClass(/dark/);
    }

    return findings;
}
