import type { LucideIcon } from 'lucide-react';
import { Monitor, Moon, Sun } from 'lucide-react';
import type { HTMLAttributes } from 'react';
import type { Appearance } from '@/hooks/use-appearance';
import { useAppearance } from '@/hooks/use-appearance';
import { cn } from '@/lib/utils';

export default function AppearanceToggleTab({
    className = '',
    supportedModes = ['light', 'dark', 'system'],
    labels = {
        light: 'Claro',
        dark: 'Oscuro',
        system: 'Sistema',
    },
    ...props
}: HTMLAttributes<HTMLDivElement> & {
    supportedModes?: Appearance[];
    labels?: Record<Appearance, string>;
}) {
    const { appearance, updateAppearance } = useAppearance();

    const tabIcons: Record<Appearance, LucideIcon> = {
        light: Sun,
        dark: Moon,
        system: Monitor,
    };

    const tabs = supportedModes.map((value) => ({
        value,
        icon: tabIcons[value],
        label: labels[value],
    }));

    return (
        <div
            className={cn(
                'inline-flex gap-1 rounded-lg bg-primary/10 p-1 dark:bg-primary/15',
                className,
            )}
            {...props}
        >
            {tabs.map(({ value, icon: Icon, label }) => (
                <button
                    key={value}
                    onClick={() => updateAppearance(value)}
                    className={cn(
                        'flex items-center rounded-md px-3.5 py-1.5 transition-colors',
                        appearance === value
                            ? 'bg-background text-foreground shadow-xs'
                            : 'text-muted-foreground hover:bg-primary/10 hover:text-foreground',
                    )}
                >
                    <Icon className="-ml-1 h-4 w-4" />
                    <span className="ml-1.5 text-sm">{label}</span>
                </button>
            ))}
        </div>
    );
}
