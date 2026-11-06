/**
 * Local oxlint plugin: the interface conventions that used to be enforced by eye.
 *
 * Two rules, both for `resources/js/pages/**` (see `.oxlintrc.json`):
 *
 * - `no-hand-rolled-box` - a raw element whose className combines a full border,
 *   a `rounded-*` radius and card-sized padding is a card drawn by hand. Thirty
 *   of them had drifted to four radii and five paddings. Use `Card`, `StatTile`
 *   or `Button` (via `buttonVariants`). Native form controls are not boxes and
 *   are skipped.
 * - `no-button-link-in-card` - a `Link` styled with `buttonVariants` inside a
 *   `CardContent`. In a card, the trailing action of a row is a plain link;
 *   the filled/outlined button look is for header bars and commits-in-place.
 *
 * Class strings are read statically: string literals, template literals without
 * expressions, and string arguments of `cn(...)`/`clsx(...)`. A dynamic class
 * the plugin cannot read is not flagged - the browser check
 * (`uiConsistencyRisks`) is the backstop for what is computed.
 */

const FORM_CONTROLS = new Set(['input', 'select', 'textarea']);
const SIDES =
    /^border-(?:[tblrxyse]|collapse|separate|spacing|solid|dashed|dotted|double|hidden|none|0)(?:-|$)/;
const PADDING =
    /^(?:p|px|py|ps|pe|pt|pb)-(?:\[([\d.]+)(?:px|rem)?\]|([\d.]+))$/;

function stringsIn(node, out) {
    if (!node) {
        return out;
    }

    switch (node.type) {
        case 'Literal':
            if (typeof node.value === 'string') {
                out.push(node.value);
            }

            break;
        case 'TemplateLiteral':
            node.quasis.forEach((quasi) => out.push(quasi.value.cooked ?? ''));
            node.expressions.forEach((expression) =>
                stringsIn(expression, out),
            );
            break;
        case 'JSXExpressionContainer':
            stringsIn(node.expression, out);
            break;
        case 'CallExpression':
            node.arguments.forEach((argument) => stringsIn(argument, out));
            break;
        case 'ConditionalExpression':
            stringsIn(node.consequent, out);
            stringsIn(node.alternate, out);
            break;
        case 'LogicalExpression':
            stringsIn(node.right, out);
            break;
        case 'ArrayExpression':
            node.elements.forEach((element) => stringsIn(element, out));
            break;
        default:
            break;
    }

    return out;
}

function classAttribute(opening) {
    return opening.attributes.find(
        (attribute) =>
            attribute.type === 'JSXAttribute' &&
            attribute.name?.name === 'className',
    );
}

function tokens(attribute) {
    return stringsIn(attribute.value, [])
        .join(' ')
        .split(/\s+/)
        .filter(Boolean)
        .map((token) => token.replace(/^(?:[a-z0-9-]+:)+/, ''));
}

function hasBorder(list) {
    return list.some(
        (token) =>
            (token === 'border' || token.startsWith('border-')) &&
            !SIDES.test(token) &&
            !/^border-(?:\d|\[\d)/.test(token),
    );
}

function hasRadius(list) {
    return list.some(
        (token) =>
            token.startsWith('rounded') &&
            token !== 'rounded-none' &&
            !/^rounded-[tblrse]{1,2}(?:-|$)/.test(token),
    );
}

function hasCardPadding(list) {
    return list.some((token) => {
        const match = PADDING.exec(token);

        if (!match) {
            return false;
        }

        const value = Number(match[1] ?? match[2]);

        // Tailwind steps are quarter-rems; 3 (0.75rem) is the smallest a card
        // uses. Smaller is a pill or a badge, which is not a card.
        return match[1] === undefined ? value >= 3 : value >= 12;
    });
}

function elementName(opening) {
    return opening.name.type === 'JSXIdentifier' ? opening.name.name : null;
}

const noHandRolledBox = {
    meta: {
        type: 'problem',
        messages: {
            box: 'A border, a radius and card padding on a raw <{{tag}}> is a hand-drawn card. Use Card, StatTile or Button (buttonVariants) instead.',
        },
    },
    create(context) {
        return {
            JSXOpeningElement(node) {
                const name = elementName(node);

                if (
                    name === null ||
                    !/^[a-z]/.test(name) ||
                    FORM_CONTROLS.has(name)
                ) {
                    return;
                }

                const attribute = classAttribute(node);

                if (!attribute) {
                    return;
                }

                const list = tokens(attribute);

                if (
                    hasBorder(list) &&
                    hasRadius(list) &&
                    hasCardPadding(list)
                ) {
                    context.report({
                        node,
                        messageId: 'box',
                        data: { tag: name },
                    });
                }
            },
        };
    },
};

function usesButtonVariants(attribute) {
    let found = false;
    const visit = (node) => {
        if (!node || typeof node !== 'object' || found) {
            return;
        }

        if (
            node.type === 'CallExpression' &&
            node.callee?.type === 'Identifier' &&
            node.callee.name === 'buttonVariants'
        ) {
            found = true;

            return;
        }

        for (const key of [
            'expression',
            'arguments',
            'consequent',
            'alternate',
            'left',
            'right',
        ]) {
            const child = node[key];

            if (Array.isArray(child)) {
                child.forEach(visit);
            } else {
                visit(child);
            }
        }
    };
    visit(attribute.value);

    return found;
}

const noButtonLinkInCard = {
    meta: {
        type: 'problem',
        messages: {
            link: 'A Link styled with buttonVariants inside CardContent. The trailing action of a card row is a plain link; use a Button only in the card header or for an in-place commit.',
        },
    },
    create(context) {
        let depth = 0;

        return {
            JSXOpeningElement(node) {
                const name = elementName(node);

                if (name === 'CardContent' && !node.selfClosing) {
                    depth += 1;

                    return;
                }

                if (depth === 0 || (name !== 'Link' && name !== 'a')) {
                    return;
                }

                const attribute = classAttribute(node);

                if (attribute && usesButtonVariants(attribute)) {
                    context.report({ node, messageId: 'link' });
                }
            },
            JSXClosingElement(node) {
                if (elementName(node) === 'CardContent') {
                    depth -= 1;
                }
            },
        };
    },
};

export default {
    meta: { name: 'svc-ui' },
    rules: {
        'no-hand-rolled-box': noHandRolledBox,
        'no-button-link-in-card': noButtonLinkInCard,
    },
};
