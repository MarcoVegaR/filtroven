import { Head, Link, router } from '@inertiajs/react';
import { BookOpenText, Footprints, Search, Sparkles } from 'lucide-react';
import { useMemo, useState } from 'react';

import {
    index as helpIndex,
    show as helpShow,
} from '@/actions/App/Http/Controllers/HelpController';
import { HelpCategoryCard } from '@/components/help/help-category-card';
import { PageHeader } from '@/components/system/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { helpCategoryIcon } from '@/lib/system';
import type { HelpCategory, HelpIndexProps } from '@/types';

function normalize(value: string): string {
    return value.trim().toLocaleLowerCase();
}

export default function HelpIndex({
    breadcrumbs,
    categories,
    filters,
}: HelpIndexProps) {
    const [searchQuery, setSearchQuery] = useState('');

    const visibleCategories = useMemo<HelpCategory[]>(() => {
        const query = normalize(searchQuery);

        if (query === '') {
            return categories;
        }

        return categories
            .map((category) => ({
                ...category,
                articles: category.articles.filter((article) => {
                    const haystack = normalize(
                        `${article.title} ${article.summary}`,
                    );

                    return haystack.includes(query);
                }),
            }))
            .filter((category) => category.articles.length > 0);
    }, [categories, searchQuery]);

    const totalVisibleArticles = visibleCategories.reduce(
        (total, category) => total + category.articles.length,
        0,
    );

    function handleCategoryChange(category?: string) {
        router.get(helpIndex.url(), category ? { category } : {}, {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Ayuda" />

            <div className="space-y-6 px-4 py-6 sm:px-6">
                <PageHeader
                    icon={BookOpenText}
                    title="Centro de ayuda"
                    description="Encuentra guías paso a paso para las tareas más comunes del sistema. Si es tu primera vez, empieza por los primeros pasos."
                    actions={
                        filters.category || searchQuery ? (
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={() => {
                                    setSearchQuery('');
                                    handleCategoryChange();
                                }}
                            >
                                Limpiar vista
                            </Button>
                        ) : undefined
                    }
                />

                {!filters.category && !searchQuery && (
                    <Card className="gap-0 border-primary/20 bg-primary/5 py-0">
                        <CardContent className="flex items-center gap-4 py-5">
                            <div className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary ring-1 ring-primary/20">
                                <Footprints className="size-5" />
                            </div>
                            <div className="flex-1 space-y-0.5">
                                <p className="text-sm font-medium text-foreground">
                                    ¿Primera vez en el sistema?
                                </p>
                                <p className="text-sm text-muted-foreground">
                                    Revisa la guía de primeros pasos para
                                    verificar que tu cuenta esté lista.
                                </p>
                            </div>
                            <Button asChild size="sm" variant="outline">
                                <Link
                                    href={helpShow.url({
                                        category: 'first-steps',
                                        slug: 'getting-started',
                                    })}
                                    prefetch
                                >
                                    Comenzar
                                </Link>
                            </Button>
                        </CardContent>
                    </Card>
                )}

                <Card className="gap-0 py-0">
                    <CardContent className="space-y-5 py-6">
                        <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                            <div className="space-y-1">
                                <p className="text-sm font-medium text-foreground">
                                    Explora por categoría
                                </p>
                                <p className="text-sm text-muted-foreground">
                                    Selecciona una categoría para acotar los
                                    artículos, o busca por título o resumen
                                    directamente.
                                </p>
                            </div>

                            <div className="relative w-full lg:max-w-sm">
                                <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                                <Input
                                    type="search"
                                    value={searchQuery}
                                    onChange={(event) =>
                                        setSearchQuery(event.target.value)
                                    }
                                    placeholder="Buscar por título o resumen..."
                                    className="pl-9"
                                />
                            </div>
                        </div>

                        <div className="flex flex-wrap gap-2">
                            <Button
                                type="button"
                                size="sm"
                                variant={
                                    filters.category ? 'outline' : 'default'
                                }
                                onClick={() => handleCategoryChange()}
                            >
                                Todas
                            </Button>

                            {categories.map((category) => {
                                const CatIcon = helpCategoryIcon(category.key);

                                return (
                                    <Button
                                        key={category.key}
                                        type="button"
                                        size="sm"
                                        variant={
                                            filters.category === category.key
                                                ? 'default'
                                                : 'outline'
                                        }
                                        onClick={() =>
                                            handleCategoryChange(category.key)
                                        }
                                    >
                                        {CatIcon && (
                                            <CatIcon className="size-3.5" />
                                        )}
                                        {category.label}
                                    </Button>
                                );
                            })}
                        </div>

                        <div className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                            <Badge variant="secondary">
                                {totalVisibleArticles} artículo
                                {totalVisibleArticles === 1 ? '' : 's'}
                            </Badge>

                            {filters.category && categories[0] && (
                                <span className="text-muted-foreground">
                                    Categoría: {categories[0].label}
                                </span>
                            )}

                            {!filters.category && !searchQuery && (
                                <span className="text-muted-foreground">
                                    Inventario completo
                                </span>
                            )}
                        </div>
                    </CardContent>
                </Card>

                {visibleCategories.length === 0 ? (
                    <Card className="py-0">
                        <EmptyState
                            icon={Sparkles}
                            title="No encontramos artículos con esos criterios"
                            description="Prueba con otra categoría o cambia las palabras clave del filtro local."
                            action={
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => {
                                        setSearchQuery('');
                                        handleCategoryChange();
                                    }}
                                >
                                    Restablecer filtros
                                </Button>
                            }
                        />
                    </Card>
                ) : (
                    <div className="grid gap-5 xl:grid-cols-2">
                        {visibleCategories.map((category) => (
                            <HelpCategoryCard
                                key={category.key}
                                category={category}
                            />
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
