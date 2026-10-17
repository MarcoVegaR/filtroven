import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { cn } from '@/lib/utils';

type UserAvatarProps = {
    name: string;
    className?: string;
    size?: 'sm' | 'md' | 'lg';
};

const palette = [
    'bg-rose-100 text-rose-700 dark:bg-rose-950/40 dark:text-rose-400',
    'bg-sky-100 text-sky-700 dark:bg-sky-950/40 dark:text-sky-400',
    'bg-amber-100 text-amber-700 dark:bg-amber-950/40 dark:text-amber-400',
    'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400',
    'bg-violet-100 text-violet-700 dark:bg-violet-950/40 dark:text-violet-400',
    'bg-orange-100 text-orange-700 dark:bg-orange-950/40 dark:text-orange-400',
    'bg-teal-100 text-teal-700 dark:bg-teal-950/40 dark:text-teal-400',
    'bg-fuchsia-100 text-fuchsia-700 dark:bg-fuchsia-950/40 dark:text-fuchsia-400',
];

function hashName(name: string): number {
    let hash = 0;

    for (let i = 0; i < name.length; i++) {
        hash = name.charCodeAt(i) + ((hash << 5) - hash);
    }

    return Math.abs(hash);
}

function getInitials(name: string): string {
    const parts = name.trim().split(/\s+/);

    if (parts.length >= 2) {
        return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
    }

    return name.slice(0, 2).toUpperCase();
}

const sizeClasses = {
    sm: 'size-8 text-xs',
    md: 'size-10 text-sm',
    lg: 'size-14 text-lg',
};

function UserAvatar({ name, className, size = 'md' }: UserAvatarProps) {
    const colorClass = palette[hashName(name) % palette.length];
    const initials = getInitials(name);

    return (
        <Avatar
            data-slot="user-avatar"
            className={cn(sizeClasses[size], className)}
        >
            <AvatarFallback className={cn('font-semibold', colorClass)}>
                {initials}
            </AvatarFallback>
        </Avatar>
    );
}

export { UserAvatar };
export type { UserAvatarProps };
