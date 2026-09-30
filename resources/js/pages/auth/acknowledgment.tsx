import { Form, Head, Link, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { logout } from '@/routes';
import { store } from '@/routes/acknowledgment';

const POINTS = ['point1', 'point2', 'point3', 'point4'] as const;

/**
 * The usage notice shown on the first sign-in (and again when an
 * administrator asks everyone to). A custom notice is plain text: blank
 * lines separate paragraphs; nothing is interpreted as HTML.
 */
export default function Acknowledgment({ text }: { text: string | null }) {
    const { t } = useTranslation('auth');
    const { institution } = usePage().props;

    return (
        <>
            <Head title={t('acknowledgment.pageTitle')} />

            <div className="flex flex-col gap-6">
                <h1 className="text-center text-xl font-medium">
                    {t('acknowledgment.title')}
                </h1>

                {text === null ? (
                    <div className="space-y-3 text-sm">
                        <p>
                            {t('acknowledgment.intro', {
                                institution: institution.name,
                            })}
                        </p>
                        <ul className="list-disc space-y-2 pl-5">
                            {POINTS.map((point) => (
                                <li key={point}>
                                    {t(`acknowledgment.${point}`)}
                                </li>
                            ))}
                        </ul>
                    </div>
                ) : (
                    <div
                        className="space-y-3 text-sm"
                        data-test="custom-notice"
                    >
                        <p className="font-medium">
                            {t('acknowledgment.custom', {
                                institution: institution.name,
                            })}
                        </p>
                        {text.split(/\n\s*\n/).map((paragraph, index) => (
                            <p key={index} className="whitespace-pre-line">
                                {paragraph}
                            </p>
                        ))}
                    </div>
                )}

                {(institution.privacyUrl || institution.termsUrl) && (
                    <p className="flex flex-wrap justify-center gap-4 text-sm">
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
                    </p>
                )}

                <Form {...store.form()} className="grid gap-2">
                    {({ processing }) => (
                        <Button type="submit" disabled={processing}>
                            {t('acknowledgment.accept')}
                        </Button>
                    )}
                </Form>

                <Link
                    href={logout()}
                    as="button"
                    className="text-center text-sm text-muted-foreground underline-offset-4 hover:underline"
                >
                    {t('acknowledgment.logout')}
                </Link>
            </div>
        </>
    );
}
