import { useState } from 'react';

type Fields = Record<string, string | number | boolean>;

/** Keep drafts while rebasing only against freshly unchanged form fields. */
export function useClientDraft<T extends Fields>(incoming: T, version: string) {
    const signature = JSON.stringify(incoming);
    const [state, setState] = useState(() => ({
        data: incoming,
        baseline: signature,
        expectedVersion: version,
        observedVersion: version,
    }));
    let current = state;

    if (version !== state.observedVersion) {
        const clean = JSON.stringify(state.data) === state.baseline;
        const unchanged = signature === state.baseline;
        current = {
            data: clean ? incoming : state.data,
            baseline: clean ? signature : state.baseline,
            // A remote change to this form's baseline keeps its old version:
            // submitting a dirty draft then earns a conflict, not an overwrite.
            expectedVersion:
                clean || unchanged ? version : state.expectedVersion,
            observedVersion: version,
        };
        setState(current);
    }

    function setData<Key extends keyof T>(field: Key, value: T[Key]) {
        setState((previous) => ({
            ...previous,
            data: { ...previous.data, [field]: value },
        }));
    }

    function saved(submitted: T, savedVersion: string) {
        setState((previous) => ({
            ...previous,
            baseline: JSON.stringify(submitted),
            expectedVersion: savedVersion,
        }));
    }

    return {
        data: current.data,
        version: current.expectedVersion,
        setData,
        saved,
    };
}
