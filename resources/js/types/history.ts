export type HistorySummary = {
    id: string;
    title: string;
    kind: string;
    author: string | null;
    occurred_on: string | null;
    href: string;
};

export type HistoryDetail = HistorySummary & {
    body: string;
    fields: { label: string; value: string }[];
    tables: { label: string; columns: string[]; rows: string[][] }[];
    company: { name: string; href: string } | null;
    parent: HistorySummary | null;
    children: HistorySummary[];
    children_href: string | null;
    download_href: string | null;
};
