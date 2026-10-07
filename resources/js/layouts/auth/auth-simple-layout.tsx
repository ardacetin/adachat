import { usePage } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import { LocaleSwitcher } from '@/components/locale-switcher';
import type { AuthLayoutProps } from '@/types';

export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    const { name, institution } = usePage().props;

    return (
        <div className="relative flex min-h-svh flex-col items-center justify-center gap-6 bg-background p-6 md:p-10">
            <div className="absolute top-4 right-4 md:top-6 md:right-6">
                <LocaleSwitcher />
            </div>
            <div className="w-full max-w-sm">
                <div className="flex flex-col gap-8">
                    <div className="flex flex-col items-center gap-4">
                        {institution.logoUrl ? (
                            <div className="flex h-12 items-center justify-center">
                                <img
                                    src={institution.logoUrl}
                                    alt={institution.name}
                                    className="max-h-12 max-w-48 object-contain dark:hidden"
                                />
                                <img
                                    src={
                                        institution.logoDarkUrl ??
                                        institution.logoUrl
                                    }
                                    alt={institution.name}
                                    className="hidden max-h-12 max-w-48 object-contain dark:block"
                                />
                            </div>
                        ) : (
                            <div
                                className="mb-1 flex h-9 w-9 items-center justify-center rounded-md"
                                role="img"
                                aria-label={name}
                            >
                                <AppLogoIcon className="size-9 fill-current text-foreground" />
                            </div>
                        )}

                        {title && (
                            <div className="space-y-2 text-center">
                                <h1 className="text-xl font-medium">{title}</h1>
                                <p className="text-center text-sm text-muted-foreground">
                                    {description}
                                </p>
                            </div>
                        )}
                    </div>
                    {children}
                </div>
            </div>
        </div>
    );
}
