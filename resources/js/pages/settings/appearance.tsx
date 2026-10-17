import { Head, usePage } from '@inertiajs/react';

import AppearanceTabs from '@/components/appearance-tabs';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { edit as editAppearance } from '@/routes/appearance';
import type { BreadcrumbItem, SharedUiProps } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Apariencia',
        href: editAppearance(),
    },
];

export default function Appearance() {
    const { ui } = usePage().props as { ui: SharedUiProps };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Apariencia" />

            <h1 className="sr-only">Configuración de apariencia</h1>

            <SettingsLayout>
                <Card>
                    <CardHeader>
                        <CardTitle>Apariencia</CardTitle>
                        <CardDescription>
                            Elige cómo deseas ver la interfaz corporativa con la
                            paleta violeta predeterminada.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <AppearanceTabs
                            supportedModes={ui.appearance.supportedModes}
                            labels={ui.appearance.labels}
                        />
                    </CardContent>
                </Card>
            </SettingsLayout>
        </AppLayout>
    );
}
