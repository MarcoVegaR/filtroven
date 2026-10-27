import { Link } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import type { PropsWithChildren } from 'react';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { home } from '@/routes';

export type AuthPresentationProps = {
    variant?: 'default' | 'branded';
    welcomeTitle?: string;
    welcomeAccent?: string;
    welcomeDescription?: string;
};

export default function AuthCardLayout({
    children,
    title,
    description,
    variant = 'default',
    welcomeTitle,
    welcomeAccent,
    welcomeDescription,
}: PropsWithChildren<
    AuthPresentationProps & {
        name?: string;
        title?: string;
        description?: string;
    }
>) {
    const hasWelcomeContent = Boolean(
        welcomeTitle || welcomeAccent || welcomeDescription,
    );
    const TitleHeading = welcomeTitle || welcomeAccent ? 'h2' : 'h1';

    if (variant === 'branded') {
        return (
            <div className="auth-branded relative isolate flex min-h-svh flex-col bg-background">
                <header className="auth-masthead relative z-10 bg-black text-white">
                    <div className="auth-masthead-inner flex items-center justify-between gap-4 py-3">
                        <Link
                            href={home()}
                            className="flex shrink-0 items-center gap-3 rounded-md outline-none focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-4 focus-visible:ring-offset-black sm:gap-4"
                            aria-label="Grupo Filtroven — inicio"
                        >
                            <img
                                src="/branding/filtroven-logo-flat-288.png"
                                alt="Filtroven"
                                width={288}
                                height={260}
                                className="auth-masthead-logo h-auto"
                            />
                        </Link>
                        <div className="hidden shrink-0 items-center gap-4 sm:flex">
                            <span
                                aria-hidden="true"
                                className="h-0.5 w-7 bg-[#b94b4b]"
                            />
                            <span className="text-xs font-medium tracking-[0.2em] whitespace-nowrap text-[#f5f3f0]">
                                SOLUCIONES EN FILTRACIÓN
                            </span>
                        </div>
                    </div>
                </header>

                <div className="auth-stage relative isolate flex flex-1">
                    <div
                        aria-hidden="true"
                        className="auth-stage-scene pointer-events-none absolute inset-0 -z-10 overflow-hidden"
                    >
                        <img
                            src="/branding/auth-scene-diagonal.webp"
                            alt=""
                            width={1672}
                            height={941}
                            className="h-full w-full object-cover"
                        />
                    </div>

                    <main className="auth-stage-grid relative mx-auto grid w-full content-center items-center">
                        {hasWelcomeContent && (
                            <div className="auth-welcome relative z-10">
                                {(welcomeTitle || welcomeAccent) && (
                                    <h1 className="auth-welcome-title font-semibold tracking-tight">
                                        {welcomeTitle}
                                        {welcomeAccent && (
                                            <span className="text-primary min-[1100px]:block">
                                                {' '}
                                                {welcomeAccent}
                                            </span>
                                        )}
                                    </h1>
                                )}
                                {welcomeDescription && (
                                    <p className="mt-4 text-base leading-relaxed text-muted-foreground">
                                        {welcomeDescription}
                                    </p>
                                )}
                            </div>
                        )}

                        <Card className="auth-login-card relative z-10 mx-auto w-full gap-7 rounded-2xl border-border/80 shadow-[0_20px_60px_-20px_#20202050] dark:shadow-[0_20px_60px_-20px_#00000090]">
                            <CardHeader className="items-center gap-2 px-0 text-center">
                                <div
                                    className="auth-access-detail mb-3"
                                    aria-hidden="true"
                                >
                                    <ShieldCheck
                                        className="size-8 sm:size-10"
                                        strokeWidth={1.5}
                                    />
                                </div>
                                <CardTitle className="text-[28px] leading-tight font-semibold tracking-tight sm:text-[30px]">
                                    <TitleHeading>{title}</TitleHeading>
                                </CardTitle>
                                {description && (
                                    <CardDescription className="text-base leading-relaxed">
                                        {description}
                                    </CardDescription>
                                )}
                            </CardHeader>
                            <CardContent className="px-0">
                                {children}
                            </CardContent>
                        </Card>
                    </main>
                </div>
            </div>
        );
    }

    return (
        <div className="flex min-h-svh flex-col items-center justify-center gap-6 bg-background p-6 md:p-10">
            <div className="flex w-full max-w-md flex-col gap-6">
                <Link
                    href={home()}
                    className="flex items-center self-center rounded-md outline-none focus-visible:ring-3 focus-visible:ring-ring focus-visible:ring-offset-4 focus-visible:ring-offset-background"
                    aria-label="Filtroven — inicio"
                >
                    <img
                        src="/branding/filtroven-logo-flat-288.png"
                        alt="Filtroven"
                        width={288}
                        height={260}
                        className="h-auto w-32"
                    />
                </Link>

                <div className="flex flex-col gap-6">
                    <Card className="rounded-xl">
                        <CardHeader className="px-10 pt-8 pb-0 text-center">
                            <CardTitle className="text-xl">{title}</CardTitle>
                            <CardDescription>{description}</CardDescription>
                        </CardHeader>
                        <CardContent className="px-10 py-8">
                            {children}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </div>
    );
}
