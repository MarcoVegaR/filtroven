import { Head } from '@inertiajs/react';
import { PlaceholderPattern } from '@/components/ui/placeholder-pattern';
import AppLayout from '@/layouts/app-layout';
import { dashboard } from '@/routes';
import type { BreadcrumbItem } from '@/types';

const dashboardContent = {
    eyebrow: 'Filtroven',
    title: 'Bienvenido al panel de Filtroven',
    description:
        'Esta base ya está preparada para construir módulos internos con autenticación endurecida, experiencia en español y una identidad visual violeta consistente.',
    summaryCards: [
        {
            title: 'Acceso seguro',
            description:
                'Fortify, verificación y 2FA listos para futuras iteraciones.',
        },
        {
            title: 'Identidad de la aplicación',
            description:
                'Filtroven conserva la interfaz en español y el selector de apariencia.',
        },
        {
            title: 'Entorno local',
            description:
                'Configura los servicios y el acceso inicial antes de operar.',
        },
    ],
    nextStepTitle: 'Siguiente paso recomendado',
    nextStepDescription:
        'Usa este dashboard como punto de partida para los futuros módulos internos. La base ya conserva el selector de apariencia, la seguridad y el shell compartido del producto.',
    checklistTitle: 'Checklist base',
    checklistItems: [
        'Ingreso centrado en login',
        'Registro público deshabilitado',
        'Mensajes backend en español',
        'Identidad de Filtroven activa',
    ],
} as const;

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Panel',
        href: dashboard(),
    },
];

export default function Dashboard() {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Panel" />
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
                <section className="rounded-2xl border border-sidebar-border/70 bg-card p-6 shadow-sm dark:border-sidebar-border">
                    <p className="text-sm font-medium text-primary">
                        {dashboardContent.eyebrow}
                    </p>
                    <h1 className="mt-2 text-2xl font-semibold tracking-tight">
                        {dashboardContent.title}
                    </h1>
                    <p className="mt-3 max-w-2xl text-sm text-muted-foreground">
                        {dashboardContent.description}
                    </p>
                </section>

                <div className="grid auto-rows-min gap-4 md:grid-cols-3">
                    {dashboardContent.summaryCards.map((card) => (
                        <div
                            key={card.title}
                            className="relative aspect-video overflow-hidden rounded-xl border border-sidebar-border/70 bg-card p-5 dark:border-sidebar-border"
                        >
                            <PlaceholderPattern className="absolute inset-0 size-full stroke-primary/10" />
                            <div className="relative flex h-full flex-col justify-between">
                                <div>
                                    <h2 className="text-base font-semibold">
                                        {card.title}
                                    </h2>
                                    <p className="mt-2 text-sm text-muted-foreground">
                                        {card.description}
                                    </p>
                                </div>
                                <span className="text-xs font-medium tracking-[0.2em] text-primary/80 uppercase">
                                    Filtroven
                                </span>
                            </div>
                        </div>
                    ))}
                </div>
                <div className="relative min-h-[100vh] flex-1 overflow-hidden rounded-xl border border-sidebar-border/70 bg-card p-6 md:min-h-min dark:border-sidebar-border">
                    <PlaceholderPattern className="absolute inset-0 size-full stroke-primary/10" />
                    <div className="relative grid gap-4 lg:grid-cols-[1.4fr_1fr]">
                        <div>
                            <h2 className="text-lg font-semibold">
                                {dashboardContent.nextStepTitle}
                            </h2>
                            <p className="mt-2 text-sm text-muted-foreground">
                                {dashboardContent.nextStepDescription}
                            </p>
                        </div>
                        <div className="rounded-xl border border-primary/15 bg-primary/5 p-4">
                            <p className="text-sm font-medium text-primary">
                                {dashboardContent.checklistTitle}
                            </p>
                            <ul className="mt-3 space-y-2 text-sm text-muted-foreground">
                                {dashboardContent.checklistItems.map((item) => (
                                    <li key={item}>• {item}</li>
                                ))}
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
