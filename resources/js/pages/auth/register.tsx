import { Head } from '@inertiajs/react';
import TextLink from '@/components/text-link';
import AuthLayout from '@/layouts/auth-layout';
import { login } from '@/routes';

export default function Register() {
    return (
        <AuthLayout
            title="Registro deshabilitado"
            description="El alta de usuarios no está disponible en este entorno corporativo"
        >
            <Head title="Registro deshabilitado" />

            <div className="rounded-xl border border-border bg-card p-6 text-sm text-muted-foreground">
                <p>
                    Si necesitas acceso, solicita la creación de tu cuenta a un
                    administrador del sistema.
                </p>
            </div>

            <div className="text-center text-sm text-muted-foreground">
                ¿Ya tienes acceso?{' '}
                <TextLink href={login()} tabIndex={1}>
                    Inicia sesión
                </TextLink>
            </div>
        </AuthLayout>
    );
}
