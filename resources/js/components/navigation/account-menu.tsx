import { Link, usePage } from '@inertiajs/react';
import { SettingsIcon } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

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
 * Changing workspace is not here either. That is the SVC wordmark, at the other
 * end of the row, because it is the only intentional way out of where you are.
 */
export function AccountMenu({
    workspaceSettingsHref,
}: {
    workspaceSettingsHref?: string | null;
}) {
    const page = usePage();
    const auth = page.props.auth;
    const applications = page.props.applications;

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
                        <DropdownMenuItem asChild>
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
