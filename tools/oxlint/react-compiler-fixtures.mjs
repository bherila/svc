// Synthetic React examples exercise both compilers rather than only rule configuration.
// See https://oxc.rs/docs/guide/usage/linter/rules/react/ for the rule contracts.
export const compilerCases = [
    {
        rule: 'error-boundaries',
        location: '<Child />',
        invalid: `
            export function Component() {
                let el;
                try {
                    el = <Child />;
                } catch {
                    return null;
                }
                return el;
            }
        `,
        valid: `
            export function Component({ Child, ErrorBoundary }) {
                return (
                    <ErrorBoundary fallback={null}>
                        <Child />
                    </ErrorBoundary>
                );
            }
        `,
    },
    {
        rule: 'globals',
        location: 'globalValue = 1',
        invalid: `
            let globalValue = 0;
            export function Component() {
                globalValue = 1;
                return <div>{globalValue}</div>;
            }
        `,
        valid: `
            import { useEffect } from 'react';
            let globalValue = 0;
            export function Component() {
                useEffect(() => {
                    globalValue = 1;
                }, []);
                return <div />;
            }
        `,
    },
    {
        rule: 'immutability',
        location: 'state.a = 1',
        invalid: `
            import { useState } from 'react';
            export function Component() {
                const [state] = useState({ a: 0 });
                state.a = 1;
                return <div>{state.a}</div>;
            }
        `,
        valid: `
            import { useState } from 'react';
            export function Component() {
                const [state, setState] = useState({ a: 0 });
                return (
                    <button onClick={() => setState({ a: state.a + 1 })}>{state.a}</button>
                );
            }
        `,
    },
    {
        rule: 'incompatible-library',
        location: 'const table =',
        invalid: `
            import { useReactTable } from '@tanstack/react-table';
            export function Component({ columns, data }) {
                const table = useReactTable({ columns, data });
                return <div>{table.getRowModel().rows.length}</div>;
            }
        `,
        valid: `
            export function Component({ rows }) {
                return <div>{rows.length}</div>;
            }
        `,
    },
    {
        rule: 'preserve-manual-memoization',
        location: 'return useCallback',
        invalid: `
            import { useCallback } from 'react';
            export function useFoo(props) {
                const values = [];
                useHook();
                values.push(props);
                return useCallback(() => {
                    doSomething();
                    doSomethingElse();
                    return [values];
                }, [values]);
            }
        `,
        valid: `
            import { useMemo } from 'react';
            export function Component({ propA }) {
                return useMemo(() => propA.x, [propA]);
            }
        `,
    },
    {
        rule: 'purity',
        location: 'Math.random',
        invalid: `
            export function Component() {
                const value = Math.random();
                return <div>{value}</div>;
            }
        `,
        valid: `
            import { useState } from 'react';
            export function Component() {
                const [value, setValue] = useState(0);
                return <button onClick={() => setValue(Math.random())}>{value}</button>;
            }
        `,
    },
    {
        rule: 'refs',
        location: 'const value = ref.current',
        invalid: `
            import { useRef } from 'react';
            export function Component() {
                const ref = useRef(null);
                const value = ref.current;
                return <div>{value}</div>;
            }
        `,
        valid: `
            import { useEffect, useRef } from 'react';
            export function Component() {
                const ref = useRef(null);
                useEffect(() => {
                    ref.current?.focus();
                }, []);
                return <input ref={ref} />;
            }
        `,
    },
    {
        rule: 'set-state-in-effect',
        location: 'setState(',
        invalid: `
            import { useEffect, useState } from 'react';
            export function Component() {
                const [state, setState] = useState(0);
                useEffect(() => {
                    setState((s) => s + 1);
                });
                return state;
            }
        `,
        valid: `
            export function Component({ value }) {
                const doubled = value * 2;
                return <div>{doubled}</div>;
            }
        `,
    },
    {
        rule: 'set-state-in-render',
        location: 'setState(state + 1)',
        invalid: `
            import { useState } from 'react';
            export function Component() {
                const [state, setState] = useState(0);
                setState(state + 1);
                return <div>{state}</div>;
            }
        `,
        valid: `
            import { useState } from 'react';
            export function Component() {
                const [state, setState] = useState(0);
                return <button onClick={() => setState(state + 1)}>{state}</button>;
            }
        `,
    },
    {
        rule: 'static-components',
        location: 'return <Inner',
        invalid: `
            export function Component() {
                const Inner = createComponent();
                return <Inner />;
            }
        `,
        valid: `
            function Inner({ text }) {
                return <div>{text}</div>;
            }
            export function Component() {
                return <Inner text="hello" />;
            }
        `,
    },
    {
        rule: 'unsupported-syntax',
        location: "eval('",
        invalid: `
            export function Component(props) {
                eval('props.x = true');
                return <div />;
            }
        `,
        valid: `
            export function Component({ value }) {
                return <div>{value}</div>;
            }
        `,
    },
    {
        rule: 'use-memo',
        location: 'const value = useMemo',
        invalid: `
            import { useMemo } from 'react';
            export function Component({ a }) {
                const value = useMemo(async () => {
                    await a;
                }, [a]);
                return <div>{value}</div>;
            }
        `,
        valid: `
            import { useMemo } from 'react';
            export function Component({ a }) {
                const value = useMemo(() => a + 1, [a]);
                return <div>{value}</div>;
            }
        `,
    },
    {
        rule: 'void-use-memo',
        location: 'useMemo(()',
        invalid: `
            import { useMemo } from 'react';
            export function Component({ a }) {
                useMemo(() => {
                    console.log(a);
                }, [a]);
                return <div>{a}</div>;
            }
        `,
        valid: `
            import { useMemo } from 'react';
            export function Component({ a }) {
                const value = useMemo(() => a + 1, [a]);
                return <div>{value}</div>;
            }
        `,
    },
];
