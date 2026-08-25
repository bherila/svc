export type User = {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
    [key: string]: unknown; // This allows for additional properties...
};

export type Auth = {
    user: User | null;
};

/**
 * A sibling application this person can move between, as the identity provider reported it
 * at sign-in. The provider decides what is in the list, so an application someone cannot
 * reach is never named to them.
 */
export type RelyingApplication = {
    key: string;
    name: string;
    url: string;
};
