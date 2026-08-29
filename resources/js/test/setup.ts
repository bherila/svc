import '@testing-library/jest-dom/vitest';
import { cleanup } from '@testing-library/react';
import { afterEach } from 'vitest';

afterEach(() => cleanup());

class TestResizeObserver implements ResizeObserver {
    disconnect(): void {}

    observe(): void {}

    unobserve(): void {}
}

Object.defineProperty(globalThis, 'ResizeObserver', {
    configurable: true,
    value: TestResizeObserver,
});

Object.defineProperty(window, 'matchMedia', {
    configurable: true,
    value: (query: string): MediaQueryList => ({
        matches: false,
        media: query,
        onchange: null,
        addEventListener: () => undefined,
        removeEventListener: () => undefined,
        addListener: () => undefined,
        removeListener: () => undefined,
        dispatchEvent: () => false,
    }),
});

Object.defineProperties(Element.prototype, {
    hasPointerCapture: {
        configurable: true,
        value: () => false,
    },
    releasePointerCapture: {
        configurable: true,
        value: () => undefined,
    },
    scrollIntoView: {
        configurable: true,
        value: () => undefined,
    },
    setPointerCapture: {
        configurable: true,
        value: () => undefined,
    },
});
