// Components
import { Form, Head } from '@inertiajs/react';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import AuthLayout from '@/layouts/auth-layout';
import { verifyEmailContent } from '@/pages/auth/content';
import { logout } from '@/routes';
import { send } from '@/routes/verification';

type Props = {
    status?: string;
};

export default function VerifyEmail({ status }: Props) {
    return (
        <AuthLayout
            title={verifyEmailContent.title}
            description={verifyEmailContent.description}
        >
            <Head title="Verificación de correo" />

            {status === 'verification-link-sent' && (
                <div className="mb-4 text-center text-sm font-medium text-green-600">
                    {verifyEmailContent.statusMessage}
                </div>
            )}

            <Form {...send.form()} className="space-y-6 text-center">
                {({ processing }) => (
                    <>
                        <Button disabled={processing} variant="secondary">
                            {processing && <Spinner />}
                            {verifyEmailContent.primaryActionLabel}
                        </Button>

                        <TextLink
                            href={logout()}
                            className="mx-auto block text-sm"
                        >
                            {verifyEmailContent.secondaryActionLabel}
                        </TextLink>
                    </>
                )}
            </Form>
        </AuthLayout>
    );
}
