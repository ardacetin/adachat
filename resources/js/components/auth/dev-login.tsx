import { Form } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';

export type DevLoginUser = {
    id: number;
    name: string;
    email: string;
    role: string;
};

// Not a Wayfinder import: this route only exists in local/testing.
const DEV_LOGIN_URL = '/dev/login';

/**
 * Password-less sign-in as any user, only in local development and tests.
 */
export function DevLogin({ users }: { users: DevLoginUser[] }) {
    const { t } = useTranslation('auth');

    return (
        <section
            className="mx-auto grid w-full max-w-sm gap-3 text-left"
            data-test="dev-login"
        >
            <div className="space-y-1">
                <h2 className="text-sm font-medium">{t('devLogin.title')}</h2>
                <p className="text-xs text-muted-foreground">
                    {t('devLogin.description')}
                </p>
            </div>

            {users.length === 0 && (
                <p className="text-sm text-muted-foreground">
                    {t('devLogin.noUsers')}
                </p>
            )}

            {users.map((user) => (
                <Form key={user.id} action={DEV_LOGIN_URL} method="post">
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
    );
}
