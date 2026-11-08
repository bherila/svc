/**
 * The conventions that used to be checked by eye, checked in a browser.
 *
 * Sits beside `horizontalOverflowRisks` (jsdom, static) and runs in the same
 * layout checks (`scripts/check-layout.mjs`), because radius, padding and font
 * size are computed by the cascade and only a real browser has the cascade.
 *
 * It reports, against the tokens in docs/ux-overhaul.md section 9:
 *
 * - `card-radius`, `card-padding`, `card-title-size`: any `[data-slot=card]`
 *   whose computed radius, padding or title size differs from the token
 *   (12 px / 20 px / 16 px on desktop; 16 px padding and a 15 px title on a
 *   phone, below 768 px);
 * - `filled-buttons`: more than one filled (primary) button on the page (or,
 *   while a modal dialog is open, inside that dialog);
 * - `bordered-link-in-card`: a bordered `<a>` inside a card body - the trailing
 *   action of a card row is a plain link;
 * - `action-button-name`: a button with neither text nor an accessible name;
 * - `action-button-icon` (advisory): a text action button with no icon before
 *   its label.
 *
 * This file is imported by Node as well as by vitest, so it uses only syntax
 * that survives type stripping, and `collectUiConsistencyRisks` is
 * self-contained: Playwright serialises it into the page.
 */

export type UiRisk = {
    rule:
        | 'card-radius'
        | 'card-padding'
        | 'card-title-size'
        | 'filled-buttons'
        | 'bordered-link-in-card'
        | 'action-button-name'
        | 'action-button-icon';
    detail: string;
    /** Advisory risks are reported but do not fail a layout check. */
    severity: 'error' | 'advisory';
};

export function collectUiConsistencyRisks(): UiRisk[] {
    const risks: UiRisk[] = [];
    const phone = window.innerWidth < 768;
    const token = {
        radius: 12,
        padding: phone ? 16 : 20,
        title: phone ? 15 : 16,
    };
    const visible = (element: Element) => {
        const box = element.getBoundingClientRect();
        const style = getComputedStyle(element);

        // jsdom has no layout: treat anything not hidden by style as visible.
        const laidOut =
            box.width > 0 ||
            box.height > 0 ||
            (typeof navigator !== 'undefined' &&
                /jsdom/i.test(navigator.userAgent));

        return (
            laidOut && style.display !== 'none' && style.visibility !== 'hidden'
        );
    };
    const label = (element: Element) =>
        `${element.tagName.toLowerCase()}${
            element.getAttribute('data-test')
                ? `[data-test=${element.getAttribute('data-test')}]`
                : ''
        } "${(element.textContent ?? '').trim().replace(/\s+/g, ' ').slice(0, 40)}"`;

    for (const card of Array.from(
        document.querySelectorAll('[data-slot="card"]'),
    )) {
        if (!visible(card)) {
            continue;
        }

        const style = getComputedStyle(card);
        const radius = parseFloat(style.borderTopLeftRadius);
        const padding = parseFloat(style.paddingTop);
        const startsWithImage = card.firstElementChild?.tagName === 'IMG';

        if (radius !== token.radius) {
            risks.push({
                rule: 'card-radius',
                detail: `${label(card)}: ${radius}px, want ${token.radius}px`,
                severity: 'error',
            });
        }

        if (!startsWithImage && padding !== token.padding) {
            risks.push({
                rule: 'card-padding',
                detail: `${label(card)}: ${padding}px, want ${token.padding}px`,
                severity: 'error',
            });
        }

        const title = card.querySelector('[data-slot="card-title"]');

        if (title) {
            const size = parseFloat(getComputedStyle(title).fontSize);

            if (size !== token.title) {
                risks.push({
                    rule: 'card-title-size',
                    detail: `${label(title)}: ${size}px, want ${token.title}px`,
                    severity: 'error',
                });
            }
        }
    }

    const probe = document.createElement('div');
    probe.style.backgroundColor = 'var(--primary)';
    document.body.appendChild(probe);
    const primary = getComputedStyle(probe).backgroundColor;
    probe.remove();
    const transparent = ['', 'transparent', 'rgba(0, 0, 0, 0)'];
    // A modal dialog that is open makes the page behind it inert, so the one
    // thing that needs doing now is inside it: the dialog's own confirm button
    // is counted alone, and the page's filled button is counted again once the
    // dialog closes.
    const modal = Array.from(
        document.querySelectorAll(
            '[role="dialog"][data-open], [role="alertdialog"][data-open]',
        ),
    )
        .filter((dialog) => visible(dialog))
        .at(-1);
    const filled = transparent.includes(primary)
        ? []
        : Array.from((modal ?? document).querySelectorAll('button, a')).filter(
              (element) =>
                  visible(element) &&
                  getComputedStyle(element).backgroundColor === primary,
          );

    if (filled.length > 1) {
        risks.push({
            rule: 'filled-buttons',
            detail: `${filled.length} filled buttons: ${filled.map(label).join('; ')}`,
            severity: 'error',
        });
    }

    for (const link of Array.from(
        document.querySelectorAll('[data-slot="card-content"] a'),
    )) {
        const style = getComputedStyle(link);

        if (
            visible(link) &&
            style.borderTopStyle !== 'none' &&
            parseFloat(style.borderTopWidth) > 0
        ) {
            risks.push({
                rule: 'bordered-link-in-card',
                detail: label(link),
                severity: 'error',
            });
        }
    }

    for (const button of Array.from(document.querySelectorAll('button'))) {
        if (!visible(button) || button.getAttribute('role') === 'combobox') {
            continue;
        }

        const labelledBy = button.getAttribute('aria-labelledby');
        const referenced = labelledBy
            ? labelledBy
                  .split(/\s+/)
                  .map((id) => document.getElementById(id)?.textContent ?? '')
                  .join(' ')
            : '';
        const name = (
            button.getAttribute('aria-label') ||
            referenced ||
            button.textContent ||
            button.getAttribute('title') ||
            ''
        ).trim();

        if (name === '') {
            risks.push({
                rule: 'action-button-name',
                detail: label(button),
                severity: 'error',
            });

            continue;
        }

        if (
            button.getAttribute('data-slot') === 'button' &&
            (button.textContent ?? '').trim() !== '' &&
            button.querySelector('svg') === null &&
            !button.className.includes('underline')
        ) {
            risks.push({
                rule: 'action-button-icon',
                detail: label(button),
                severity: 'advisory',
            });
        }
    }

    return risks;
}

/** A Playwright page, or anything that can run a function in one. */
export type EvaluatingPage = {
    evaluate: <T>(fn: () => T) => Promise<T>;
};

export function uiConsistencyRisks(page: EvaluatingPage): Promise<UiRisk[]> {
    return page.evaluate(collectUiConsistencyRisks);
}
