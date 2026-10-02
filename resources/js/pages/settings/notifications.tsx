import { Head, useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import CheckboxField from '@/components/admin/checkbox-field';
import Heading from '@/components/heading';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { edit, update } from '@/routes/notifications';

type Props = {
    budgetEmails: boolean;
    budgetEmailsOffered: boolean;
};

export default function Notifications({
    budgetEmails,
    budgetEmailsOffered,
}: Props) {
    const { t } = useTranslation('settings');
    const { t: tCommon } = useTranslation('common');

    const form = useForm({ budget_emails: budgetEmails });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.put(update.url(), { preserveScroll: true });
    };

    return (
        <>
            <Head title={t('notifications.title')} />

            <h1 className="sr-only">{t('notifications.title')}</h1>

            <form onSubmit={submit} className="space-y-6">
                <Heading
                    variant="small"
                    title={t('notifications.title')}
                    description={t('notifications.description')}
                />

                {!budgetEmailsOffered && (
                    <Alert>
                        <AlertDescription>
                            {t('notifications.notOffered')}
                        </AlertDescription>
                    </Alert>
                )}

                <div className="space-y-1">
                    <CheckboxField
                        id="budget_emails"
                        label={t('notifications.budgetEmails')}
                        checked={form.data.budget_emails}
                        onChange={(checked) =>
                            form.setData('budget_emails', checked)
                        }
                    />
                    <p className="text-sm text-muted-foreground">
                        {t('notifications.budgetEmailsHelp')}
                    </p>
                </div>

                <Button type="submit" disabled={form.processing}>
                    {tCommon('actions.save')}
                </Button>
            </form>
        </>
    );
}

Notifications.layout = {
    breadcrumbs: [
        {
            titleKey: 'settings:notifications.title',
            href: edit(),
        },
    ],
};
