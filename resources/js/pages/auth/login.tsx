import { Form, Head } from '@inertiajs/react';
import { ArrowRight, LockKeyhole, Mail } from 'lucide-react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import AuthLayout from '@/layouts/auth-layout';
import { loginContent } from '@/pages/auth/content';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

type Props = {
    status?: string;
    canResetPassword: boolean;
};

export default function Login({ status, canResetPassword }: Props) {
    return (
        <AuthLayout variant="branded" title={loginContent.title}>
            <Head title="Iniciar sesión" />

            <Form
                {...store.form()}
                resetOnSuccess={['password']}
                className="flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-5">
                            <div className="grid gap-2">
                                <Label htmlFor="email">
                                    Correo electrónico
                                </Label>
                                <div className="relative">
                                    <Mail
                                        aria-hidden="true"
                                        className="pointer-events-none absolute top-1/2 left-4 z-10 size-5 -translate-y-1/2 text-muted-foreground"
                                    />
                                    <Input
                                        id="email"
                                        type="email"
                                        name="email"
                                        required
                                        autoFocus
                                        className="auth-field min-h-12 pl-12 text-base md:text-base"
                                        aria-invalid={!!errors.email}
                                        aria-describedby={
                                            errors.email
                                                ? 'email-error'
                                                : undefined
                                        }
                                        autoComplete="email"
                                        placeholder="nombre@empresa.com"
                                    />
                                </div>
                                <InputError
                                    id="email-error"
                                    role="alert"
                                    message={errors.email}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="password">Contraseña</Label>
                                <div className="relative">
                                    <LockKeyhole
                                        aria-hidden="true"
                                        className="pointer-events-none absolute top-1/2 left-4 z-10 size-5 -translate-y-1/2 text-muted-foreground"
                                    />
                                    <PasswordInput
                                        id="password"
                                        name="password"
                                        required
                                        className="auth-field min-h-12 pr-12 pl-12 text-base md:text-base"
                                        accessibleToggle
                                        showPasswordLabel="Mostrar contraseña"
                                        hidePasswordLabel="Ocultar contraseña"
                                        aria-invalid={!!errors.password}
                                        aria-describedby={
                                            errors.password
                                                ? 'password-error'
                                                : undefined
                                        }
                                        autoComplete="current-password"
                                        placeholder="Ingresa tu contraseña"
                                    />
                                </div>
                                <InputError
                                    id="password-error"
                                    role="alert"
                                    message={errors.password}
                                />
                            </div>

                            <div className="flex flex-col items-start gap-2 min-[375px]:flex-row min-[375px]:flex-wrap min-[375px]:items-center min-[375px]:justify-between min-[375px]:gap-x-3">
                                <div className="flex min-h-11 items-center gap-2">
                                    <Checkbox id="remember" name="remember" />
                                    <Label
                                        htmlFor="remember"
                                        className="flex min-h-11 cursor-pointer items-center text-sm"
                                    >
                                        {loginContent.rememberLabel}
                                    </Label>
                                </div>
                                {canResetPassword && (
                                    <TextLink
                                        href={request()}
                                        className="inline-flex min-h-11 items-center text-sm"
                                    >
                                        {loginContent.forgotPasswordLabel}
                                    </TextLink>
                                )}
                            </div>

                            <Button
                                type="submit"
                                className="min-h-13 w-full gap-3 text-base"
                                disabled={processing}
                                data-test="login-button"
                            >
                                {processing && <Spinner />}
                                {loginContent.primaryActionLabel}
                                {!processing && (
                                    <ArrowRight
                                        aria-hidden="true"
                                        className="size-4"
                                    />
                                )}
                            </Button>
                        </div>
                    </>
                )}
            </Form>

            {status && (
                <div
                    role="status"
                    className="mt-5 text-center text-sm font-medium text-foreground"
                >
                    {status}
                </div>
            )}
        </AuthLayout>
    );
}
