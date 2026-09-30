import { Form, Head, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { redirect } from '@/routes/auth';

type DevLoginUser = {
    id: number;
    name: string;
    email: string;
    role: string;
};

type Props = {
    providers: { key: string; label: string }[];
    devLoginUsers: DevLoginUser[] | null;
};

// Not a Wayfinder import: this route only exists in local/testing.
const DEV_LOGIN_URL = '/dev/login';

export default function Login({ providers, devLoginUsers }: Props) {
    const { t } = useTranslation('auth');
    const { name, institution, errors } = usePage<{
        errors: Record<string, string>;
    }>().props;

    return (
        <>
            <Head title={t('login.pageTitle')} />

            <div className="flex flex-col gap-6">
                <div className="space-y-2 text-center">
                    <h1 className="text-xl font-medium">
                        {t('login.title', { name })}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {t('login.description', {
                            institution: institution.name,
                        })}
                    </p>
                </div>

                <InputError message={errors.auth} className="text-center" />

                <div className="grid gap-2">
                    {providers.length > 0 ? (
                        providers.map((provider) => (
                            // A full page navigation: the IdP redirect must not be an Inertia visit.
                            <Button
                                key={provider.key}
                                asChild
                                className="w-full"
                            >
                                <a href={redirect.url(provider.key)}>
                                    {t('login.withProvider', {
                                        provider: provider.label,
                                    })}
                                </a>
                            </Button>
                        ))
                    ) : (
                        <p className="text-center text-sm text-muted-foreground">
                            {t('login.notConfigured')}
                        </p>
                    )}
                </div>

                {devLoginUsers !== null && (
                    <>
                        <Separator />

                        <section className="grid gap-3">
                            <div className="space-y-1">
                                <h2 className="text-sm font-medium">
                                    {t('devLogin.title')}
                                </h2>
                                <p className="text-xs text-muted-foreground">
                                    {t('devLogin.description')}
                                </p>
                            </div>

                            {devLoginUsers.length === 0 && (
                                <p className="text-sm text-muted-foreground">
                                    {t('devLogin.noUsers')}
                                </p>
                            )}

                            {devLoginUsers.map((user) => (
                                <Form
                                    key={user.id}
                                    action={DEV_LOGIN_URL}
                                    method="post"
                                >
                                    {({ processing }) => (
                                        <>
                                            <input
                                                type="hidden"
                                                name="user_id"
                                                value={user.id}
                                            />
                                            <Button
                                                type="submit"
                                                variant="outline"
                                                className="w-full justify-between"
                                                disabled={processing}
                                            >
                                                <span>
                                                    {t('devLogin.signInAs', {
                                                        name: user.name,
                                                    })}
                                                </span>
                                                <span className="text-xs text-muted-foreground">
                                                    {user.role}
                                                </span>
                                            </Button>
                                        </>
                                    )}
                                </Form>
                            ))}
                        </section>
                    </>
                )}
            </div>
        </>
    );
}
