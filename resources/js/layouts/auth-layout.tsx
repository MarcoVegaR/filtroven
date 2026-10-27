import AuthLayoutTemplate from '@/layouts/auth/auth-card-layout';
import type { AuthPresentationProps } from '@/layouts/auth/auth-card-layout';

export default function AuthLayout({
    children,
    title,
    description,
    ...props
}: AuthPresentationProps & {
    children: React.ReactNode;
    title: string;
    description?: string;
}) {
    return (
        <AuthLayoutTemplate title={title} description={description} {...props}>
            {children}
        </AuthLayoutTemplate>
    );
}
