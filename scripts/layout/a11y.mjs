/* global document */
import AxeBuilder from '@axe-core/playwright';
import { expect } from '@playwright/test';
import { uiConsistencyRisks } from '../../resources/js/test/ui-consistency.ts';

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

    const { violations } = await new AxeBuilder({ page }).analyze();

    for (const violation of violations) {
        findings.push(
            `${where} (${theme}): axe ${violation.id} (${violation.impact}, ${violation.nodes.length} nodes: ${violation.nodes
                .slice(0, 3)
                .map((node) => node.target.join(' '))
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
