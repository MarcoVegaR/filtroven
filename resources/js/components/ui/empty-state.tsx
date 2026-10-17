import * as React from "react"

import { cn } from "@/lib/utils"

type EmptyStateProps = {
  icon?: React.ComponentType<{ className?: string }>
  title: string
  description?: string
  action?: React.ReactNode
  className?: string
}

function EmptyState({
  icon: Icon,
  title,
  description,
  action,
  className,
}: EmptyStateProps) {
  return (
    <div
      data-slot="empty-state"
      className={cn(
        "flex flex-col items-center justify-center gap-4 py-16 text-center",
        className
      )}
    >
      {Icon && (
        <div className="bg-muted flex size-14 items-center justify-center rounded-full">
          <Icon className="text-muted-foreground size-7" />
        </div>
      )}

      <div className="flex flex-col gap-1">
        <p className="text-foreground text-base font-semibold">{title}</p>
        {description && (
          <p className="text-muted-foreground text-sm">{description}</p>
        )}
      </div>

      {action && <div>{action}</div>}
    </div>
  )
}

export { EmptyState }
export type { EmptyStateProps }
