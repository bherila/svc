// The package entry point eagerly loads unrelated rules that require ESLint's
// SourceCode. Only order is configured here, and its upstream implementation
// needs the rule API supplied by Oxlint rather than the ESLint engine.
import order from 'eslint-plugin-import/lib/rules/order.js';

export default {
    meta: { name: 'svc-import-order' },
    rules: { order },
};
