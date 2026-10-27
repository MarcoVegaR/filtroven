import { Head, Link, usePage } from '@inertiajs/react';
import { dashboard, login } from '@/routes';

export default function Welcome() {
    const { auth } = usePage().props;

    return (
        <>
            <Head title="Filtroven">
                <link rel="preconnect" href="https://fonts.bunny.net" />
                <link
                    href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600"
                    rel="stylesheet"
                />
            </Head>
            <div className="flex min-h-screen items-center justify-center bg-background p-6">
                <main className="w-full max-w-3xl rounded-3xl border border-border bg-card p-8 shadow-sm">
                    <div className="flex flex-col gap-6 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <p className="text-sm font-medium text-primary dark:text-accent-foreground">
                                Filtroven
                            </p>
                            <h1 className="mt-2 text-3xl font-semibold tracking-tight">
                                Bienvenido a Filtroven
                            </h1>
                            <p className="mt-3 max-w-xl text-sm text-muted-foreground">
                                Esta pantalla queda como artefacto de
                                referencia. El flujo principal del sistema ahora
                                inicia desde el login y no desde una landing
                                pública.
                            </p>
                        </div>

                        <Link
                            href={auth.user ? dashboard() : login()}
                            className="inline-flex items-center justify-center rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground shadow-sm transition hover:opacity-90"
                        >
                            {auth.user
                                ? 'Ir al panel'
                                : 'Ir al inicio de sesión'}
                        </Link>
                    </div>
                </main>
            </div>
        </>
    );
}
