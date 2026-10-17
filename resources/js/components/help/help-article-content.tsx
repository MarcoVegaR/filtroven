import { Link } from '@inertiajs/react';
import { Children, isValidElement } from 'react';
import type { ComponentPropsWithoutRef, ReactNode } from 'react';
import ReactMarkdown from 'react-markdown';
import remarkGfm from 'remark-gfm';

import { cn } from '@/lib/utils';

type HelpArticleContentProps = {
    content: string;
    className?: string;
};

function isExternalHref(href?: string): boolean {
    if (!href) {
        return false;
    }

    return /^https?:\/\//i.test(href);
}

function InlineCode({ className, ...props }: ComponentPropsWithoutRef<'code'>) {
    return (
        <code
            className={cn(
                'rounded bg-muted px-1.5 py-0.5 font-mono text-[0.9em] text-foreground',
                className,
            )}
            {...props}
        />
    );
}

function CodeBlock({ children }: { children: ReactNode }) {
    return (
        <pre className="overflow-x-auto rounded-xl border bg-zinc-950 px-4 py-3 text-sm leading-6 text-zinc-100 shadow-sm dark:bg-zinc-900">
            {children}
        </pre>
    );
}

export function HelpArticleContent({
    content,
    className,
}: HelpArticleContentProps) {
    return (
        <div className={cn('text-sm leading-7 text-foreground/90', className)}>
            <ReactMarkdown
                remarkPlugins={[remarkGfm]}
                components={{
                    a: ({ href, children, ...props }) => {
                        if (!href || isExternalHref(href)) {
                            return (
                                <a
                                    href={href}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="font-medium text-primary underline-offset-4 hover:underline"
                                    {...props}
                                >
                                    {children}
                                </a>
                            );
                        }

                        return (
                            <Link
                                href={href}
                                className="font-medium text-primary underline-offset-4 hover:underline"
                            >
                                {children}
                            </Link>
                        );
                    },
                    blockquote: ({ children }) => {
                        let text = '';
                        Children.forEach(children, (child) => {
                            if (text) {
                                return;
                            }

                            if (isValidElement(child)) {
                                const inner = (
                                    child.props as { children?: ReactNode }
                                ).children;
                                text = String(
                                    Array.isArray(inner)
                                        ? (inner[0] ?? '')
                                        : (inner ?? ''),
                                );
                            }
                        });

                        const isWarning = text.startsWith('⚠️');
                        const isTip =
                            text.startsWith('💡') || text.startsWith('✅');

                        if (isWarning) {
                            return (
                                <blockquote className="my-4 rounded-lg border-l-4 border-amber-500/40 bg-amber-500/5 px-4 py-3 text-sm text-foreground/80 dark:border-amber-400/30 dark:bg-amber-400/5 [&>p]:my-0">
                                    {children}
                                </blockquote>
                            );
                        }

                        if (isTip) {
                            return (
                                <blockquote className="my-4 rounded-lg border-l-4 border-emerald-500/40 bg-emerald-500/5 px-4 py-3 text-sm text-foreground/80 dark:border-emerald-400/30 dark:bg-emerald-400/5 [&>p]:my-0">
                                    {children}
                                </blockquote>
                            );
                        }

                        return (
                            <blockquote className="my-4 rounded-lg border-l-4 border-primary/30 bg-primary/5 px-4 py-3 text-sm text-foreground/80 [&>p]:my-0">
                                {children}
                            </blockquote>
                        );
                    },
                    code: ({
                        className: codeClassName,
                        children,
                        ...props
                    }) => {
                        const value = String(children ?? '');
                        const isBlock = value.includes('\n');

                        if (isBlock) {
                            return (
                                <CodeBlock>
                                    <code
                                        className={cn(
                                            'font-mono text-sm',
                                            codeClassName,
                                        )}
                                        {...props}
                                    >
                                        {children}
                                    </code>
                                </CodeBlock>
                            );
                        }

                        return (
                            <InlineCode className={codeClassName} {...props}>
                                {children}
                            </InlineCode>
                        );
                    },
                    h2: ({ children }) => (
                        <h2 className="mt-8 mb-3 scroll-mt-24 border-b border-border pb-2 text-base font-semibold tracking-tight text-foreground">
                            {children}
                        </h2>
                    ),
                    h3: ({ children }) => (
                        <h3 className="mt-6 mb-2 scroll-mt-24 text-sm font-semibold text-foreground">
                            {children}
                        </h3>
                    ),
                    hr: () => <hr className="my-6 border-border" />,
                    li: ({ children }) => (
                        <li className="text-foreground/90">{children}</li>
                    ),
                    ol: ({ children }) => (
                        <ol className="my-4 list-decimal space-y-1.5 pl-6 text-sm">
                            {children}
                        </ol>
                    ),
                    p: ({ children }) => (
                        <p className="my-3 leading-7 text-foreground/90">
                            {children}
                        </p>
                    ),
                    strong: ({ children }) => (
                        <strong className="font-semibold text-foreground">
                            {children}
                        </strong>
                    ),
                    table: ({ children }) => (
                        <div className="my-5 overflow-x-auto rounded-lg border border-border">
                            <table className="w-full border-collapse text-sm">
                                {children}
                            </table>
                        </div>
                    ),
                    tbody: ({ children }) => (
                        <tbody className="divide-y divide-border">
                            {children}
                        </tbody>
                    ),
                    td: ({ children }) => (
                        <td className="px-3 py-2 text-foreground/80">
                            {children}
                        </td>
                    ),
                    th: ({ children }) => (
                        <th className="bg-muted/60 px-3 py-2 text-left text-xs font-medium tracking-wide text-foreground">
                            {children}
                        </th>
                    ),
                    thead: ({ children }) => (
                        <thead className="border-b border-border">
                            {children}
                        </thead>
                    ),
                    tr: ({ children }) => (
                        <tr className="transition-colors hover:bg-muted/30">
                            {children}
                        </tr>
                    ),
                    ul: ({ children }) => (
                        <ul className="my-4 list-disc space-y-1.5 pl-6 text-sm">
                            {children}
                        </ul>
                    ),
                }}
            >
                {content}
            </ReactMarkdown>
        </div>
    );
}

export type { HelpArticleContentProps };
