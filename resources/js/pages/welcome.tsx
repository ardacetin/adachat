import { Head, Link, usePage } from '@inertiajs/react';
import { Bot, Lock, Wallet } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import AppLogoIcon from '@/components/app-logo-icon';
import { LocaleSwitcher } from '@/components/locale-switcher';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { login } from '@/routes';

type Props = {
    /** The wording, in the visitor's language, with placeholders filled in (Admin → Texts). */
    texts: Record<string, string>;
};

/**
 * What guests see at "/": who runs Ada, what it offers, and the way in.
 * Sign-in itself (providers, dev login) stays on /login.
 */
export default function Welcome({ texts }: Props) {
    const { t } = useTranslation('auth');
    const { name, institution } = usePage().props;

    const features = [
        { icon: Bot, title: texts.models_title, text: texts.models_text },
        { icon: Wallet, title: texts.budget_title, text: texts.budget_text },
        { icon: Lock, title: texts.privacy_title, text: texts.privacy_text },
    ];

    return (
        <>
            <Head title={t('landing.pageTitle')} />

            <header className="mx-auto flex w-full max-w-5xl items-center justify-between gap-4 px-6 py-5">
                {institution.logoUrl ? (
                    <>
                        <img
                            src={institution.logoUrl}
                            alt={institution.name}
                            className="max-h-10 max-w-44 object-contain dark:hidden"
                        />
                        <img
                            src={institution.logoDarkUrl ?? institution.logoUrl}
                            alt={institution.name}
                            className="hidden max-h-10 max-w-44 object-contain dark:block"
                        />
                    </>
                ) : (
                    <span className="flex items-center gap-2 font-medium">
                        <AppLogoIcon className="size-7 fill-current" />
                        {name}
                    </span>
                )}

                <div className="flex items-center gap-2">
                    <LocaleSwitcher />
                    <Button asChild variant="outline" size="sm">
                        <Link href={login.url()}>{t('landing.signIn')}</Link>
                    </Button>
                </div>
            </header>

            <main className="mx-auto w-full max-w-5xl flex-1 px-6">
                <section className="py-16 text-center md:py-24">
                    <p className="text-sm font-medium text-primary">
                        {institution.name}
                    </p>
                    <h1 className="mt-3 text-4xl font-semibold tracking-tight text-balance md:text-5xl">
                        {texts.title}
                    </h1>
                    <p className="mx-auto mt-5 max-w-2xl text-lg text-pretty text-muted-foreground">
                        {texts.lead}
                    </p>
                    <div className="mt-8 flex flex-col items-center gap-3">
                        <Button asChild size="lg">
                            <Link
                                href={login.url()}
                                data-test="landing-sign-in"
                            >
                                {t('landing.signIn')}
                            </Link>
                        </Button>
                        <p className="text-sm text-muted-foreground">
                            {texts.sign_in_hint}
                        </p>
                    </div>
                </section>

                <section
                    aria-labelledby="landing-features"
                    className="grid gap-4 pb-16 md:grid-cols-3"
                >
                    <h2 id="landing-features" className="sr-only">
                        {t('landing.featuresHeading', { name })}
                    </h2>
                    {features.map((feature) => (
                        <Card key={feature.title}>
                            <CardHeader>
                                <feature.icon
                                    className="mb-2 size-6 text-primary"
                                    aria-hidden="true"
                                />
                                <CardTitle>{feature.title}</CardTitle>
                                <CardDescription className="text-base leading-relaxed">
                                    {feature.text}
                                </CardDescription>
                            </CardHeader>
                        </Card>
                    ))}
                </section>
            </main>

            <footer className="mx-auto flex w-full max-w-5xl flex-wrap items-center justify-between gap-3 border-t px-6 py-6 text-sm text-muted-foreground">
                <span>{texts.footer}</span>
                {(institution.privacyUrl || institution.termsUrl) && (
                    <span className="flex gap-4">
                        {institution.privacyUrl && (
                            <a
                                href={institution.privacyUrl}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="underline underline-offset-4"
                            >
                                {t('acknowledgment.privacy')}
                            </a>
                        )}
                        {institution.termsUrl && (
                            <a
                                href={institution.termsUrl}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="underline underline-offset-4"
                            >
                                {t('acknowledgment.terms')}
                            </a>
                        )}
                    </span>
                )}
            </footer>
        </>
    );
}
