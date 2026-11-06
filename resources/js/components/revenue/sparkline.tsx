/**
 * A twelve-month trend line, drawn from the series the server sent.
 *
 * Decoration: the figure beside it carries the meaning, so the line is hidden
 * from assistive technology. It scales to its own range and draws a flat line
 * for a flat (or empty) series rather than dividing by zero.
 */
export function Sparkline({
    series,
}: {
    series: { month: string; amount: number }[];
}) {
    const width = 72;
    const height = 20;
    const amounts = series.map((point) => point.amount);
    const low = Math.min(0, ...amounts);
    const high = Math.max(0, ...amounts);
    const range = high - low;
    const points = amounts.map((amount, index) => {
        const x =
            amounts.length > 1 ? (index / (amounts.length - 1)) * width : 0;
        const y =
            range === 0
                ? height / 2
                : height - 1 - ((amount - low) / range) * (height - 2);

        return `${x.toFixed(1)},${y.toFixed(1)}`;
    });

    return (
        <svg
            data-slot="sparkline"
            aria-hidden="true"
            focusable="false"
            viewBox={`0 0 ${width} ${height}`}
            className="h-5 w-[72px] shrink-0 text-muted-foreground"
        >
            <polyline
                points={points.join(' ')}
                fill="none"
                stroke="currentColor"
                strokeWidth="1.5"
                strokeLinejoin="round"
                strokeLinecap="round"
            />
        </svg>
    );
}
