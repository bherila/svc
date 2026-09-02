import type { Auth, RelyingApplication } from '@/types/auth';
import type { ClientContext } from '@/types/navigation';

/**
 * The shared props the chrome reads, in one place.
 *
 * `HandleInertiaRequests` shares these on every request, so a page test that
 * mocks `usePage` has to supply them or the layout it renders inside reads
 * `undefined`. Built here rather than restated in each test file because the
 * set grows: when the chrome started naming the signed-in person, every mock
 * that had only `clientContext` broke at once, and each would have been fixed
 * separately. One factory means the next addition is one edit.
 *
 * A page test that cares about a value passes it; everything else takes the
 * default, which is a signed-in operator inside no client.
 */
export function sharedPageProps(
    overrides: {
        clientContext?: ClientContext | null;
        auth?: Auth;
        applications?: RelyingApplication[];
    } = {},
): {
    clientContext: ClientContext | null;
    auth: Auth;
    applications: RelyingApplication[];
} {
    return {
        clientContext: overrides.clientContext ?? null,
        auth: overrides.auth ?? {
            user: {
                id: 1,
                name: 'Synthetic Operator',
                email: 'operator@example.com',
                email_verified_at: '2026-01-01T00:00:00+00:00',
                created_at: '2026-01-01T00:00:00+00:00',
                updated_at: '2026-01-01T00:00:00+00:00',
            },
        },
        applications: overrides.applications ?? [],
    };
}
