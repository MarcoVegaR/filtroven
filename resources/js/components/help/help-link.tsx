import { Link } from '@inertiajs/react';
import { CircleHelp } from 'lucide-react';

import { show } from '@/actions/App/Http/Controllers/HelpController';
import { Button } from '@/components/ui/button';

type HelpLinkProps = {
    category: string;
    slug: string;
    label?: string;
};

export function HelpLink({ category, slug, label = 'Ayuda' }: HelpLinkProps) {
    return (
        <Button asChild variant="outline" size="sm">
            <Link
                href={show.url({ category, slug })}
                prefetch
                data-test={`help-link-${category}-${slug}`}
            >
                <CircleHelp className="size-4" />
                {label}
            </Link>
        </Button>
    );
}

export type { HelpLinkProps };
