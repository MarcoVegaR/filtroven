import { Link } from "@inertiajs/react"
import { ChevronLeft, ChevronRight, MoreHorizontal } from "lucide-react"
import * as React from "react"

import { cn } from "@/lib/utils"
import type { PaginatorLink } from "@/types/ui"

function Pagination({
  className,
  ...props
}: React.ComponentProps<"nav">) {
  return (
    <nav
      role="navigation"
      aria-label="pagination"
      data-slot="pagination"
      className={cn("mx-auto flex w-full justify-center", className)}
      {...props}
    />
  )
}

function PaginationContent({
  className,
  ...props
}: React.ComponentProps<"ul">) {
  return (
    <ul
      data-slot="pagination-content"
      className={cn("flex flex-row items-center gap-1", className)}
      {...props}
    />
  )
}

function PaginationItem({ className, ...props }: React.ComponentProps<"li">) {
  return (
    <li data-slot="pagination-item" className={cn("", className)} {...props} />
  )
}

type PaginationLinkProps = {
  isActive?: boolean
  disabled?: boolean
} & React.ComponentProps<"a">

function PaginationLink({
  className,
  isActive,
  disabled,
  children,
  href,
  ...props
}: PaginationLinkProps) {
  if (disabled || !href) {
    return (
      <span
        data-slot="pagination-link"
        aria-disabled="true"
        aria-current={isActive ? "page" : undefined}
        className={cn(
          "inline-flex h-9 min-w-9 items-center justify-center rounded-md border px-3 text-sm transition-colors",
          isActive
            ? "bg-primary text-primary-foreground border-primary"
            : "border-input bg-background text-muted-foreground opacity-50 cursor-not-allowed",
          className
        )}
        {...(props as React.ComponentProps<"span">)}
      >
        {children}
      </span>
    )
  }

  return (
    <Link
      data-slot="pagination-link"
      aria-current={isActive ? "page" : undefined}
      href={href}
      className={cn(
        "inline-flex h-9 min-w-9 items-center justify-center rounded-md border px-3 text-sm transition-colors",
        isActive
          ? "bg-primary text-primary-foreground border-primary"
          : "border-input bg-background hover:bg-accent hover:text-accent-foreground",
        className
      )}
    >
      {children}
    </Link>
  )
}

function PaginationPrevious({
  className,
  href,
  disabled,
  ...props
}: { href?: string | null; disabled?: boolean } & Omit<React.ComponentProps<"a">, "href">) {
  const resolvedHref = href ?? undefined
  return (
    <PaginationLink
      data-slot="pagination-previous"
      aria-label="Go to previous page"
      href={resolvedHref}
      disabled={disabled || !resolvedHref}
      className={cn("gap-1 px-2.5", className)}
      {...props}
    >
      <ChevronLeft className="size-4" />
      <span className="hidden sm:inline">Anterior</span>
    </PaginationLink>
  )
}

function PaginationNext({
  className,
  href,
  disabled,
  ...props
}: { href?: string | null; disabled?: boolean } & Omit<React.ComponentProps<"a">, "href">) {
  const resolvedHref = href ?? undefined
  return (
    <PaginationLink
      data-slot="pagination-next"
      aria-label="Go to next page"
      href={resolvedHref}
      disabled={disabled || !resolvedHref}
      className={cn("gap-1 px-2.5", className)}
      {...props}
    >
      <span className="hidden sm:inline">Siguiente</span>
      <ChevronRight className="size-4" />
    </PaginationLink>
  )
}

function PaginationEllipsis({ className, ...props }: React.ComponentProps<"span">) {
  return (
    <span
      data-slot="pagination-ellipsis"
      aria-hidden
      className={cn(
        "inline-flex h-9 w-9 items-center justify-center",
        className
      )}
      {...props}
    >
      <MoreHorizontal className="size-4" />
      <span className="sr-only">Más páginas</span>
    </span>
  )
}

/**
 * Renders a full pagination bar from Laravel's paginator `links` array.
 * Returns null when there is only one page.
 */
function LaravelPagination({
  links,
  from,
  to,
  total,
  className,
}: {
  links: PaginatorLink[]
  from?: number | null
  to?: number | null
  total?: number
  className?: string
}) {
  // Laravel always includes prev + page numbers + next; filter out if only 3 items (1 page)
  const pageLinks = links.slice(1, -1) // strip the "previous" and "next" sentinel items
  if (pageLinks.length <= 1) return null

  const prevLink = links[0]
  const nextLink = links[links.length - 1]

  return (
    <div className={cn("flex flex-col items-center gap-2 sm:flex-row sm:justify-between", className)}>
      {from != null && to != null && total != null ? (
        <p className="text-xs text-muted-foreground tabular-nums">
          Mostrando {from}–{to} de {total} registros
        </p>
      ) : <span />}
      <Pagination className="mx-0 w-auto">
        <PaginationContent>
          <PaginationItem>
            <PaginationPrevious href={prevLink.url ?? undefined} disabled={!prevLink.url} />
          </PaginationItem>

          {pageLinks.map((link, index) => {
            if (link.label === "...") {
              return (
                <PaginationItem key={`ellipsis-${index}`} className="hidden sm:inline-flex">
                  <PaginationEllipsis />
                </PaginationItem>
              )
            }

            return (
              <PaginationItem key={link.label} className="hidden sm:inline-flex">
                <PaginationLink href={link.url ?? undefined} isActive={link.active}>
                  {link.label}
                </PaginationLink>
              </PaginationItem>
            )
          })}

          <PaginationItem>
            <PaginationNext href={nextLink.url ?? undefined} disabled={!nextLink.url} />
          </PaginationItem>
        </PaginationContent>
      </Pagination>
    </div>
  )
}

export {
  LaravelPagination,
  Pagination,
  PaginationContent,
  PaginationEllipsis,
  PaginationItem,
  PaginationLink,
  PaginationNext,
  PaginationPrevious,
}
