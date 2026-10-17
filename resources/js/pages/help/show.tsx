import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, ArrowRight, BookOpenText, Clock } from 'lucide-react';
import { createElement, useMemo } from 'react';

import { index as helpIndex } from '@/actions/App/Http/Controllers/HelpController';
import { HelpArticleContent } from '@/components/help/help-article-content';
import { PageHeader } from '@/components/system/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { helpCategoryIcon } from '@/lib/system';
import type { HelpShowProps } from '@/types';

function estimateReadTime(content: string): number {
    const words = content.trim().split(/\s+/).length;

    return Math.max(1, Math.round(words / 200));
}

export default function HelpShow({
    article,
    breadcrumbs,
    categoryArticles,
}: HelpShowProps) {
    const readTime = useMemo(
        () => estimateReadTime(article.content),
        [article.content],
    );
    const sidebarIcon = helpCategoryIcon(article.category) ?? BookOpenText;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={article.title} />

            <div className="space-y-6 px-4 py-6 sm:px-6">
                <PageHeader
                    icon={BookOpenText}
                    title={article.title}
                    description={article.summary}
                    actions={
                        <Button asChild variant="outline" size="sm">
                            <Link
                                href={helpIndex.url({
                                    query: { category: article.category },
                                })}
                                prefetch
                            >
                                <ArrowLeft className="size-4" />
                                Volver a {article.category_label}
                            </Link>
                        </Button>
                    }
                />

                <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_320px]">
                    <div className="space-y-6">
                        <Card className="gap-0 py-0">
                            <CardContent className="py-6">
                                <div className="mb-5 flex flex-wrap items-center gap-2">
                                    <Badge variant="secondary">
                                        {article.category_label}
                                    </Badge>
                                    <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
                                        <Clock className="size-3" />
                                        {readTime} min de lectura
                                    </span>
                                </div>
                                <HelpArticleContent content={article.content} />
                            </CardContent>
                        </Card>

                        <div className="grid gap-3 md:grid-cols-2">
                            {article.prev ? (
                                <Button
                                    asChild
                                    variant="outline"
                                    className="h-auto justify-start py-3 text-left"
                                >
                                    <Link href={article.prev.url} prefetch>
                                        <ArrowLeft className="mt-0.5 size-4 shrink-0" />
                                        <span className="space-y-0.5">
                                            <span className="block text-xs text-muted-foreground">
                                                Artículo anterior
                                            </span>
                                            <span className="block text-sm font-medium">
                                                {article.prev.title}
                                            </span>
                                        </span>
                                    </Link>
                                </Button>
                            ) : (
                                <div />
                            )}

                            {article.next ? (
                                <Button
                                    asChild
                                    variant="outline"
                                    className="h-auto justify-end py-3 text-right"
                                >
                                    <Link href={article.next.url} prefetch>
                                        <span className="space-y-0.5">
                                            <span className="block text-xs text-muted-foreground">
                                                Siguiente artículo
                                            </span>
                                            <span className="block text-sm font-medium">
                                                {article.next.title}
                                            </span>
                                        </span>
                                        <ArrowRight className="mt-0.5 size-4 shrink-0" />
                                    </Link>
                                </Button>
                            ) : (
                                <div />
                            )}
                        </div>
                    </div>

                    <Card className="gap-0 py-0 xl:sticky xl:top-6 xl:self-start">
                        <CardHeader className="gap-0.5 border-b py-4">
                            <p className="flex items-center gap-1.5 text-xs font-medium tracking-wider text-muted-foreground uppercase">
                                {createElement(sidebarIcon, {
                                    className: 'size-3.5',
                                })}
                                {article.category_label}
                            </p>
                            <CardTitle className="text-sm">
                                En esta categoría
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-2 py-5">
                            {categoryArticles.map((categoryArticle) => {
                                const isActive =
                                    categoryArticle.url === article.url;

                                return (
                                    <Link
                                        key={categoryArticle.url}
                                        href={categoryArticle.url}
                                        prefetch
                                        aria-current={
                                            isActive ? 'page' : undefined
                                        }
                                        className={
                                            isActive
                                                ? 'rounded-lg border border-primary/30 bg-primary/5 px-3 py-3 text-sm'
                                                : 'rounded-lg border px-3 py-3 text-sm transition-colors hover:border-primary/40 hover:bg-accent/40'
                                        }
                                    >
                                        <p
                                            className={
                                                isActive
                                                    ? 'font-semibold text-primary'
                                                    : 'font-medium text-foreground'
                                            }
                                        >
                                            {categoryArticle.title}
                                        </p>
                                        <p className="mt-1 leading-relaxed text-muted-foreground">
                                            {categoryArticle.summary}
                                        </p>
                                    </Link>
                                );
                            })}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
