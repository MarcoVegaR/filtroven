import { Transition } from '@headlessui/react';
import { Form, Head } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import { useRef, useState } from 'react';

import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import TwoFactorRecoveryCodes from '@/components/two-factor-recovery-codes';
import TwoFactorSetupModal from '@/components/two-factor-setup-modal';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { useTwoFactorAuth } from '@/hooks/use-two-factor-auth';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { disable, enable } from '@/routes/two-factor';
import type { BreadcrumbItem } from '@/types';

type Props = {
    canManageTwoFactor?: boolean;
    requiresConfirmation?: boolean;
    twoFactorEnabled?: boolean;
};

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Seguridad',
        href: SecurityController.edit(),
    },
];

export default function Security({
    canManageTwoFactor = false,
    requiresConfirmation = false,
    twoFactorEnabled = false,
}: Props) {
    const passwordInput = useRef<HTMLInputElement>(null);
    const currentPasswordInput = useRef<HTMLInputElement>(null);

    const {
        qrCodeSvg,
        hasSetupData,
        manualSetupKey,
        clearSetupData,
        fetchSetupData,
        recoveryCodesList,
        fetchRecoveryCodes,
        errors,
    } = useTwoFactorAuth();
    const [showSetupModal, setShowSetupModal] = useState<boolean>(false);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Seguridad" />

            <h1 className="sr-only">Configuración de seguridad</h1>

            <SettingsLayout>
                <Card>
                    <CardHeader>
                        <CardTitle>Actualizar contraseña</CardTitle>
                        <CardDescription>
                            Usa una contraseña robusta y exclusiva para mantener
                            tu cuenta protegida
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Form
                            {...SecurityController.update.form()}
                            options={{
                                preserveScroll: true,
                            }}
                            resetOnError={[
                                'password',
                                'password_confirmation',
                                'current_password',
                            ]}
                            resetOnSuccess
                            onError={(errors) => {
                                if (errors.password) {
                                    passwordInput.current?.focus();
                                }

                                if (errors.current_password) {
                                    currentPasswordInput.current?.focus();
                                }
                            }}
                            className="space-y-4"
                        >
                            {({ errors, processing, recentlySuccessful }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="current_password">
                                            Contraseña actual
                                        </Label>
                                        <PasswordInput
                                            id="current_password"
                                            ref={currentPasswordInput}
                                            name="current_password"
                                            autoComplete="current-password"
                                            placeholder="Contraseña actual"
                                        />
                                        <InputError
                                            message={errors.current_password}
                                        />
                                    </div>

                                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                        <div className="grid gap-2">
                                            <Label htmlFor="password">
                                                Nueva contraseña
                                            </Label>
                                            <PasswordInput
                                                id="password"
                                                ref={passwordInput}
                                                name="password"
                                                autoComplete="new-password"
                                                placeholder="Nueva contraseña"
                                            />
                                            <InputError
                                                message={errors.password}
                                            />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="password_confirmation">
                                                Confirmar contraseña
                                            </Label>
                                            <PasswordInput
                                                id="password_confirmation"
                                                name="password_confirmation"
                                                autoComplete="new-password"
                                                placeholder="Confirma tu contraseña"
                                            />
                                            <InputError
                                                message={
                                                    errors.password_confirmation
                                                }
                                            />
                                        </div>
                                    </div>

                                    <div className="flex items-center gap-4 pt-2">
                                        <Button
                                            disabled={processing}
                                            data-test="update-password-button"
                                        >
                                            Guardar contraseña
                                        </Button>

                                        <Transition
                                            show={recentlySuccessful}
                                            enter="transition ease-in-out"
                                            enterFrom="opacity-0"
                                            leave="transition ease-in-out"
                                            leaveTo="opacity-0"
                                        >
                                            <p className="text-sm text-emerald-600 dark:text-emerald-400">
                                                Guardado
                                            </p>
                                        </Transition>
                                    </div>
                                </>
                            )}
                        </Form>
                    </CardContent>
                </Card>

                {canManageTwoFactor && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Autenticación en dos pasos</CardTitle>
                            <CardDescription>
                                Administra tu configuración de verificación
                                adicional
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {twoFactorEnabled ? (
                                <div className="flex flex-col items-start space-y-4">
                                    <p className="text-sm text-muted-foreground">
                                        Durante el inicio de sesión se te
                                        solicitará un código temporal generado
                                        por tu aplicación autenticadora.
                                    </p>

                                    <Form {...disable.form()}>
                                        {({ processing }) => (
                                            <Button
                                                variant="destructive"
                                                type="submit"
                                                disabled={processing}
                                            >
                                                Desactivar 2FA
                                            </Button>
                                        )}
                                    </Form>

                                    <TwoFactorRecoveryCodes
                                        recoveryCodesList={recoveryCodesList}
                                        fetchRecoveryCodes={fetchRecoveryCodes}
                                        errors={errors}
                                    />
                                </div>
                            ) : (
                                <div className="flex flex-col items-start space-y-4">
                                    <p className="text-sm text-muted-foreground">
                                        Cuando actives la autenticación en dos
                                        pasos, necesitarás un código temporal
                                        desde una aplicación compatible con
                                        TOTP.
                                    </p>

                                    <div>
                                        {hasSetupData ? (
                                            <Button
                                                onClick={() =>
                                                    setShowSetupModal(true)
                                                }
                                            >
                                                <ShieldCheck className="size-4" />
                                                Continuar configuración
                                            </Button>
                                        ) : (
                                            <Form
                                                {...enable.form()}
                                                onSuccess={() =>
                                                    setShowSetupModal(true)
                                                }
                                            >
                                                {({ processing }) => (
                                                    <Button
                                                        type="submit"
                                                        disabled={processing}
                                                    >
                                                        Activar 2FA
                                                    </Button>
                                                )}
                                            </Form>
                                        )}
                                    </div>
                                </div>
                            )}

                            <TwoFactorSetupModal
                                isOpen={showSetupModal}
                                onClose={() => setShowSetupModal(false)}
                                requiresConfirmation={requiresConfirmation}
                                twoFactorEnabled={twoFactorEnabled}
                                qrCodeSvg={qrCodeSvg}
                                manualSetupKey={manualSetupKey}
                                clearSetupData={clearSetupData}
                                fetchSetupData={fetchSetupData}
                                errors={errors}
                            />
                        </CardContent>
                    </Card>
                )}
            </SettingsLayout>
        </AppLayout>
    );
}
