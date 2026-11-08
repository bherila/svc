"use client"

import * as React from "react"

import { cn } from "@/lib/utils"

function Table({
  className,
  scrollLabel,
  ...props
}: React.ComponentProps<"table"> & {
  /**
   * Names the box when the table can scroll sideways on a phone, and makes it
   * reachable by keyboard so it can be scrolled without a pointer.
   */
  scrollLabel?: string
}) {
  const container = React.useRef<HTMLDivElement>(null)
  const [overflows, setOverflows] = React.useState(false)

  // A box that scrolls must be reachable by keyboard (WCAG 2.1.1), and only
  // one that scrolls: a tab stop on every table that fits would be noise. So
  // the box becomes focusable while its content is wider than it is, whether
  // or not the caller named it.
  React.useEffect(() => {
    const box = container.current

    if (box === null || typeof ResizeObserver === "undefined") {
      return
    }

    const measure = () => setOverflows(box.scrollWidth > box.clientWidth)
    const observer = new ResizeObserver(measure)

    measure()
    observer.observe(box)

    if (box.firstElementChild !== null) {
      observer.observe(box.firstElementChild)
    }

    return () => observer.disconnect()
  }, [])

  return (
    <div
      ref={container}
      data-slot="table-container"
      className="relative w-full overflow-x-auto"
      {...(scrollLabel === undefined
        ? overflows
          ? { tabIndex: 0 }
          : {}
        : { role: "region", "aria-label": scrollLabel, tabIndex: 0 })}
    >
      <table
        data-slot="table"
        className={cn("w-full caption-bottom text-sm", className)}
        {...props}
      />
    </div>
  )
}

function TableHeader({ className, ...props }: React.ComponentProps<"thead">) {
  return (
    <thead
      data-slot="table-header"
      className={cn("[&_tr]:border-b", className)}
      {...props}
    />
  )
}

function TableBody({ className, ...props }: React.ComponentProps<"tbody">) {
  return (
    <tbody
      data-slot="table-body"
      className={cn("[&_tr:last-child]:border-0", className)}
      {...props}
    />
  )
}

function TableFooter({ className, ...props }: React.ComponentProps<"tfoot">) {
  return (
    <tfoot
      data-slot="table-footer"
      className={cn(
        "border-t bg-muted/50 font-medium [&>tr]:last:border-b-0",
        className
      )}
      {...props}
    />
  )
}

function TableRow({ className, ...props }: React.ComponentProps<"tr">) {
  return (
    <tr
      data-slot="table-row"
      className={cn(
        "border-b transition-colors hover:bg-muted/50 has-aria-expanded:bg-muted/50 data-[state=selected]:bg-muted",
        className
      )}
      {...props}
    />
  )
}

function TableHead({ className, ...props }: React.ComponentProps<"th">) {
  return (
    <th
      data-slot="table-head"
      className={cn(
        "h-10 px-2 text-left align-middle font-medium whitespace-nowrap text-foreground [&:has([role=checkbox])]:pr-0",
        className
      )}
      {...props}
    />
  )
}

function TableCell({ className, ...props }: React.ComponentProps<"td">) {
  return (
    <td
      data-slot="table-cell"
      className={cn(
        "p-2 align-middle whitespace-nowrap [&:has([role=checkbox])]:pr-0",
        className
      )}
      {...props}
    />
  )
}

function TableCaption({
  className,
  ...props
}: React.ComponentProps<"caption">) {
  return (
    <caption
      data-slot="table-caption"
      className={cn("mt-4 text-sm text-muted-foreground", className)}
      {...props}
    />
  )
}

export {
  Table,
  TableHeader,
  TableBody,
  TableFooter,
  TableHead,
  TableRow,
  TableCell,
  TableCaption,
}
