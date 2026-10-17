import * as React from "react"

import { cn } from "@/lib/utils"

type ToolbarProps = {
  children?: React.ReactNode
  className?: string
}

function Toolbar({ children, className }: ToolbarProps) {
  return (
    <div
      data-slot="toolbar"
      className={cn(
        "flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between",
        className
      )}
    >
      {children}
    </div>
  )
}

function ToolbarGroup({
  children,
  className,
}: {
  children?: React.ReactNode
  className?: string
}) {
  return (
    <div
      data-slot="toolbar-group"
      className={cn("flex flex-wrap items-center gap-2", className)}
    >
      {children}
    </div>
  )
}

function ToolbarSeparator({ className }: { className?: string }) {
  return (
    <div
      data-slot="toolbar-separator"
      role="separator"
      aria-orientation="vertical"
      className={cn("bg-border hidden h-6 w-px sm:block", className)}
    />
  )
}

export { Toolbar, ToolbarGroup, ToolbarSeparator }
