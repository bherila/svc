export type ExpenseProject = {
    id: string;
    name: string;
};

export type ClientExpenseRow = {
    id: string;
    spent_on: string;
    /** Minor units, as the column holds them. */
    amount: number;
    currency: string;
    description: string;
    status: string;
    project: ExpenseProject | null;
    approved_by: string | null;
    approved_at: string | null;
    /**
     * What may be done to this row, decided on the server.
     *
     * The lifecycle lives in `ExpenseStatus` and is asked there once, rather
     * than re-derived in the browser from a status string — two readings of one
     * lifecycle is how a screen comes to offer a button the server refuses.
     * A viewer who cannot manage the workspace gets all four false and is
     * offered nothing to press.
     */
    can_edit: boolean;
    can_approve: boolean;
    can_unapprove: boolean;
    can_discard: boolean;
    /**
     * Finished URLs for this row's moves, generated after authorization.
     *
     * Sent rather than assembled, so the page never needs the workspace id and
     * cannot build a URL for a client it is not on.
     */
    urls: {
        update: string;
        approve: string;
        unapprove: string;
        discard: string;
    };
};

export type ExpensesPageProps = {
    company: { id: string; name: string };
    permissions: { record: boolean; approve: boolean };
    urls: { store: string };
    projects: ExpenseProject[];
    expenses: ClientExpenseRow[];
};
