import type { BreadcrumbItem } from './navigation';

export type HelpArticleSummary = {
    slug: string;
    category: string;
    category_label: string;
    title: string;
    summary: string;
    order: number;
    url: string;
};

export type HelpCategory = {
    key: string;
    label: string;
    articles: HelpArticleSummary[];
};

export type HelpAdjacentArticle = {
    title: string;
    url: string;
};

export type HelpArticle = HelpArticleSummary & {
    content: string;
    prev: HelpAdjacentArticle | null;
    next: HelpAdjacentArticle | null;
};

export type HelpIndexProps = {
    categories: HelpCategory[];
    filters: {
        category?: string | null;
    };
    breadcrumbs: BreadcrumbItem[];
};

export type HelpShowProps = {
    article: HelpArticle;
    categoryArticles: HelpArticleSummary[];
    breadcrumbs: BreadcrumbItem[];
};
