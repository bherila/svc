import * as React from "react"

import { cn } from "@/lib/utils"

/**
 * A key figure: a label, a number and at most one line of context.
 *
 * Its own component rather than a Card, because the spec gives it its own
 * padding (16 by 18 px) and no title row. Pages never draw one by hand - the
 * `svc-ui/no-hand-rolled-box` lint rule sends them here.
 */
function StatTile({
  label,
  value,
  context,
  className,
  ...props
}: Omit<React.ComponentProps<"div">, "children"> & {
  label: React.ReactNode
  value: React.ReactNode
  context?: React.ReactNode
}) {
  return (
    <div
      data-slot="stat-tile"
      className={cn(
        "min-w-0 rounded-[12px] bg-card px-[18px] py-4 text-card-foreground ring-1 ring-foreground/10",
        className
      )}
      {...props}
    >
      <div className="text-xs text-muted-foreground wrap-anywhere">{label}</div>
      <div className="mt-1 text-2xl font-semibold tabular-nums wrap-anywhere">
        {value}
      </div>
      {context !== undefined && context !== null && (
        <div className="mt-1 text-xs text-muted-foreground wrap-anywhere">
          {context}
        </div>
      )}
    </div>
  )
}

export { StatTile }
