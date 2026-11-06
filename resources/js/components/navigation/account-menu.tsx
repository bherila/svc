import { Link, usePage } from '@inertiajs/react';
import { SettingsIcon } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { apiRequest } from '@/lib/api';
import { isAppearance, useAppearance } from '@/lib/appearance';
import { DEFAULT_DURATION_STYLE, isDurationStyle } from '@/lib/duration';
import { refreshPageProps } from '@/lib/refresh-page-props';
import type { WorkspaceNavigation } from '@/types/navigation';

/**
 * Everything that is true regardless of which client is selected.
 *
 * The test for what belongs here is exactly that: an item whose meaning changes
 * when the switcher does belongs on the left, in the client's own tabs. So this
 * holds the signed-in person, the workspace's own settings when they may reach
 * them, the sibling applications the identity provider named, and sign-out -
 * and it deliberately holds no workspace name, no client directory, no
 * Operations screen and no client settings. Each of those was a second way to
 * navigate the same things, which is what made the old bar convoluted.
 *
 * Changing workspace is not here either. That is the exit control, at the other
 * end of the row, because it is the only intentional way out of where you are.
 */
export function AccountMenu({
    workspaceSettingsHref,
}: {
    workspaceSettingsHref?: string | null;
}) {
    const page = usePage();
    const [appearance, setAppearance] = useAppearance();
    const auth = page.props.auth;
    const applications = page.props.applications;
    const navigation = page.props
        .workspaceNavigation as WorkspaceNavigation | null;

    const durationStyle = isDurationStyle(auth.user?.duration_style)
        ? auth.user.duration_style
        : DEFAULT_DURATION_STYLE;
    const preferencesUrl = auth.user?.preferences_url;

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon-sm"
                    aria-label="Account and settings"
                >
                    <SettingsIcon aria-hidden="true" className="size-4" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-60">
                {/*
                 * Who is signed in. Nothing else on these screens says so, and
                 * the switcher to the left can name any client in the tenant -
                 * so whose session this is, is exactly the thing the reader
                 * cannot otherwise check.
                 */}
                {auth.user !== null && (
                    <>
                        <DropdownMenuGroup>
                            <DropdownMenuLabel>
                                <span className="block truncate font-medium">
                                    {auth.user.name}
                                </span>
                                <span className="block truncate text-xs font-normal text-muted-foreground">
                                    {auth.user.email}
                                </span>
                            </DropdownMenuLabel>
                        </DropdownMenuGroup>
                        <DropdownMenuSeparator />
                    </>
                )}

                {navigation?.mcp_setup_href && (
                    <DropdownMenuGroup>
                        <DropdownMenuItem asChild>
                            <Link
                                href={navigation.mcp_setup_href}
                                data-test="account-mcp-setup"
                            >
                                MCP setup guide
                            </Link>
                        </DropdownMenuItem>
                    </DropdownMenuGroup>
                )}

                {navigation?.history_href && (
                    <DropdownMenuItem
                        render={<Link href={navigation.history_href} />}
                    >
                        History
                    </DropdownMenuItem>
                )}
                {navigation?.operations_href && (
                    <DropdownMenuItem
                        render={<Link href={navigation.operations_href} />}
                        data-test="account-operations"
                    >
                        Operations (classic tools)
                    </DropdownMenuItem>
                )}
                {navigation?.payments_href && (
                    <DropdownMenuItem
                        render={<Link href={navigation.payments_href} />}
                        data-test="account-payments"
                    >
                        Payments
                    </DropdownMenuItem>
                )}
                {navigation?.shared_projects_href && (
                    <DropdownMenuItem
                        render={<Link href={navigation.shared_projects_href} />}
                        data-test="account-shared-projects"
                    >
                        Shared projects
                    </DropdownMenuItem>
                )}
                {navigation?.reviewers_href && (
                    <DropdownMenuItem
                        render={<Link href={navigation.reviewers_href} />}
                        data-test="account-reviewers"
                    >
                        Reviewers
                    </DropdownMenuItem>
                )}
                {navigation?.approvals_href && (
                    <DropdownMenuItem
                        render={<Link href={navigation.approvals_href} />}
                        data-test="account-approvals"
                    >
                        To approve
                    </DropdownMenuItem>
                )}
                {navigation?.task_labels_href && (
                    <DropdownMenuItem
                        render={<Link href={navigation.task_labels_href} />}
                        data-test="account-task-labels"
                    >
                        Task labels
                    </DropdownMenuItem>
                )}
                {navigation?.square_href && (
                    <DropdownMenuGroup>
                        <DropdownMenuItem asChild>
                            <Link
                                href={navigation.square_href}
                                data-test="account-square"
                            >
                                Square invoices
                            </Link>
                        </DropdownMenuItem>
                    </DropdownMenuGroup>
                )}

                {typeof workspaceSettingsHref === 'string' && (
                    <DropdownMenuGroup>
                        <DropdownMenuItem asChild>
                            <Link href={workspaceSettingsHref}>
                                Workspace settings
                            </Link>
                        </DropdownMenuItem>
                    </DropdownMenuGroup>
                )}

                {/*
                 * Below `sm` the bar has no room for the theme control, so the
                 * same choice lives here. From `sm` up this group is hidden and
                 * the control in the bar is used.
                 */}
                <DropdownMenuSeparator className="sm:hidden" />
                <DropdownMenuGroup className="sm:hidden">
                    <DropdownMenuLabel>Theme</DropdownMenuLabel>
                    <DropdownMenuRadioGroup
                        value={appearance}
                        onValueChange={(value) => {
                            if (isAppearance(value)) {
                                setAppearance(value);
                            }
                        }}
                    >
                        <DropdownMenuRadioItem value="system">
                            System
                        </DropdownMenuRadioItem>
                        <DropdownMenuRadioItem value="light">
                            Light
                        </DropdownMenuRadioItem>
                        <DropdownMenuRadioItem value="dark">
                            Dark
                        </DropdownMenuRadioItem>
                    </DropdownMenuRadioGroup>
                </DropdownMenuGroup>

                {typeof preferencesUrl === 'string' && (
                    <>
                        <DropdownMenuSeparator />
                        <DropdownMenuGroup>
                            <DropdownMenuLabel>Show time as</DropdownMenuLabel>
                            <DropdownMenuRadioGroup
                                value={durationStyle}
                                onValueChange={(value) => {
                                    // A display preference: saved with fetch and
                                    // the shared props reloaded, not an Inertia
                                    // mutation (business writes use /api/v1).
                                    void apiRequest('PATCH', preferencesUrl, {
                                        duration_style: value,
                                    }).then((result) => {
                                        if (result.ok) {
                                            return refreshPageProps({
                                                only: ['auth'],
                                            });
                                        }
                                    });
                                }}
                            >
                                <DropdownMenuRadioItem
                                    value="decimal"
                                    data-test="duration-style-decimal"
                                >
                                    2.5 h
                                </DropdownMenuRadioItem>
                                <DropdownMenuRadioItem
                                    value="clock"
                                    data-test="duration-style-clock"
                                >
                                    2:30
                                </DropdownMenuRadioItem>
                            </DropdownMenuRadioGroup>
                        </DropdownMenuGroup>
                    </>
                )}

                {/*
                 * The sibling applications, as the identity provider reported
                 * them at sign-in. Separated because following one leaves the
                 * site, and because the provider - not this bundle - decides
                 * what is here.
                 */}
                {applications.length > 0 && (
                    <>
                        <DropdownMenuSeparator />
                        <DropdownMenuGroup>
                            <DropdownMenuLabel>Other apps</DropdownMenuLabel>
                            {applications.map((app) => (
                                <DropdownMenuItem key={app.key} asChild>
                                    <a href={app.url}>{app.name}</a>
                                </DropdownMenuItem>
                            ))}
                        </DropdownMenuGroup>
                    </>
                )}

                {auth.user !== null && (
                    <>
                        <DropdownMenuSeparator />
                        {/*
                         * A POST, because signing someone out on a GET means
                         * any image tag on any page can do it.
                         */}
                        <DropdownMenuItem asChild nativeButton>
                            <Link
                                href="/logout"
                                method="post"
                                as="button"
                                className="w-full justify-start"
                            >
                                Sign out
                            </Link>
                        </DropdownMenuItem>
                    </>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
