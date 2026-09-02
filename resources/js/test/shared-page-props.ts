import type { Auth, RelyingApplication } from '@/types/auth';
import type { WorkspaceNavigation } from '@/types/navigation';

/**
 * The shared props the chrome reads, in one place.
 *
 * `HandleInertiaRequests` shares the identity and the sibling applications on
 * every request, and `ResolveWorkspaceNavigation` adds the navbar's own payload
 * on the routes that render one - so a page test that mocks `usePage` has to
 * supply them or the shell it renders inside reads `undefined`. Built here
 * rather than restated in each test file because the set grows: when the chrome
 * started naming the signed-in person, every mock that had only the client
 * context broke at once, and each would have been fixed separately.
 *
 * A page test that cares about a value passes it; everything else takes the
 * default, which is a signed-in operator inside no workspace.
 */
export function sharedPageProps(
    overrides: {
        workspaceNavigation?: WorkspaceNavigation | null;
        auth?: Auth;
        applications?: RelyingApplication[];
    } = {},
): {
    workspaceNavigation: WorkspaceNavigation | null;
    auth: Auth;
    applications: RelyingApplication[];
} {
    return {
        workspaceNavigation: overrides.workspaceNavigation ?? null,
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
