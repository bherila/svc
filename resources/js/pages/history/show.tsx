import { Head, Link } from '@inertiajs/react';
import WorkspaceShell from '@/layouts/workspace-shell';
import { SHELL_CONTAINER } from '@/lib/layout';
import { cn } from '@/lib/utils';
import type { HistoryDetail } from '@/types/history';

export default function HistoryShow({ entry }: { entry: HistoryDetail }) {
    return (
        <WorkspaceShell>
            <Head title={entry.title} />
            <main
                className={cn(SHELL_CONTAINER, 'grid grid-cols-1 gap-6 py-8')}
            >
                <div className="min-w-0">
                    <p className="text-sm text-muted-foreground">
                        Historical {entry.kind.toLowerCase()} · Read-only
                    </p>
                    <h1 className="text-2xl font-semibold wrap-anywhere">
                        {entry.title}
                    </h1>
                    <p className="text-sm wrap-anywhere text-muted-foreground">
                        {[entry.occurred_on, entry.author]
                            .filter(Boolean)
                            .join(' · ')}
                    </p>
                    {entry.company && (
                        <Link
                            href={entry.company.href}
                            className="wrap-anywhere underline"
                        >
                            {entry.company.name}
                        </Link>
                    )}
                    {entry.parent && (
                        <p className="min-w-0">
                            In{' '}
                            <Link
                                href={entry.parent.href}
                                className="wrap-anywhere underline"
                            >
                                {entry.parent.title}
                            </Link>
                        </p>
                    )}
                </div>
                {entry.body !== '' && (
                    <section
                        aria-label="Content"
                        className="min-w-0 wrap-anywhere whitespace-pre-wrap"
                    >
                        {entry.body}
                    </section>
                )}
                {entry.fields.length > 0 && (
                    <dl className="grid min-w-0 grid-cols-1 gap-4 rounded-lg border p-4">
                        {entry.fields.map((field, index) => (
                            <div key={index} className="min-w-0">
                                <dt className="text-sm text-muted-foreground">
                                    {field.label}
                                </dt>
                                <dd className="wrap-anywhere whitespace-pre-wrap">
                                    {field.value}
                                </dd>
                            </div>
                        ))}
                    </dl>
                )}
                {entry.tables.map((table, index) => (
                    <section
                        key={index}
                        className="grid min-w-0 grid-cols-1 gap-3"
                    >
                        <h2 className="text-lg font-semibold">{table.label}</h2>
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead>
                                    <tr>
                                        {table.columns.map((column) => (
                                            <th
                                                key={column}
                                                className="min-w-24 border-b p-2"
                                            >
                                                {column}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {table.rows.map((row, rowIndex) => (
                                        <tr key={rowIndex}>
                                            {row.map((value, columnIndex) => (
                                                <td
                                                    key={columnIndex}
                                                    className={cn(
                                                        'border-b p-2 align-top',
                                                        columnIndex === 0
                                                            ? 'max-w-0 min-w-48 wrap-anywhere whitespace-normal'
                                                            : 'whitespace-nowrap',
                                                    )}
                                                >
                                                    {value}
                                                </td>
                                            ))}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>
                ))}
                {entry.download_href && (
                    <a href={entry.download_href} className="underline">
                        Download preserved file
                    </a>
                )}
                {entry.children.length > 0 && (
                    <section className="grid min-w-0 grid-cols-1 gap-3">
                        <h2 className="text-lg font-semibold">
                            Related records
                        </h2>
                        {entry.children.map((child) => (
                            <article
                                key={child.id}
                                className="min-w-0 rounded-lg border p-3"
                            >
                                <p className="text-xs text-muted-foreground">
                                    {child.kind}
                                </p>
                                <Link
                                    href={child.href}
                                    className="wrap-anywhere underline"
                                >
                                    {child.title}
                                </Link>
                                <p className="text-sm wrap-anywhere text-muted-foreground">
                                    {[child.occurred_on, child.author]
                                        .filter(Boolean)
                                        .join(' · ')}
                                </p>
                            </article>
                        ))}
                        {entry.children_href && (
                            <Link
                                href={entry.children_href}
                                className="underline"
                            >
                                All related records
                            </Link>
                        )}
                    </section>
                )}
            </main>
        </WorkspaceShell>
    );
}
