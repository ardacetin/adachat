import { Head, useForm, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import CheckboxField from '@/components/admin/checkbox-field';
import FormField from '@/components/admin/form-field';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Separator } from '@/components/ui/separator';
import { Textarea } from '@/components/ui/textarea';
import { edit, update } from '@/routes/admin/privacy';
import type { Locale } from '@/types/global';

type Settings = {
    acknowledgment_enabled: boolean;
    acknowledgment_text: Partial<Record<Locale, string>>;
    acknowledgment_version: number;
    conversation_retention_days: number | null;
    deleted_conversation_days: number;
    usage_retention_months: number;
};

export default function Privacy({ settings }: { settings: Settings }) {
    const { t } = useTranslation('admin');
    const { t: tCommon } = useTranslation('common');
    const { locale } = usePage().props;

    const form = useForm({
        acknowledgment_enabled: settings.acknowledgment_enabled,
        acknowledgment_text: Object.fromEntries(
            locale.available.map((code) => [
                code,
                settings.acknowledgment_text[code] ?? '',
            ]),
        ) as Record<Locale, string>,
        ask_again: false,
        conversation_retention_days:
            settings.conversation_retention_days?.toString() ?? '',
        deleted_conversation_days: String(settings.deleted_conversation_days),
        usage_retention_months: String(settings.usage_retention_months),
    });

    const errors = form.errors as Record<string, string | undefined>;

    const numberField = (
        field:
            | 'conversation_retention_days'
            | 'deleted_conversation_days'
            | 'usage_retention_months',
        label: string,
        help: string,
    ) => (
        <FormField id={field} label={label} help={help} error={errors[field]}>
            <Input
                id={field}
                type="number"
                min={0}
                className="w-32"
                value={form.data[field]}
                aria-describedby={`${field}-help`}
                onChange={(event) => form.setData(field, event.target.value)}
            />
        </FormField>
    );

    return (
        <>
            <Head title={t('privacy.title')} />

            <form
                className="space-y-8"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.put(update.url(), {
                        preserveScroll: true,
                        onSuccess: () => form.setData('ask_again', false),
                    });
                }}
            >
                <Heading
                    variant="small"
                    title={t('privacy.title')}
                    description={t('privacy.description')}
                />

                <section className="space-y-4">
                    <h3 className="text-sm font-medium">
                        {t('privacy.notice')}
                    </h3>
                    <CheckboxField
                        id="acknowledgment_enabled"
                        label={t('privacy.enabled')}
                        checked={form.data.acknowledgment_enabled}
                        onChange={(checked) =>
                            form.setData('acknowledgment_enabled', checked)
                        }
                    />
                    {locale.available.map((code) => (
                        <FormField
                            key={code}
                            id={`acknowledgment_text-${code}`}
                            label={t('privacy.text', {
                                locale: code.toUpperCase(),
                            })}
                            help={t('privacy.textHelp')}
                            error={errors[`acknowledgment_text.${code}`]}
                        >
                            <Textarea
                                id={`acknowledgment_text-${code}`}
                                rows={5}
                                maxLength={5000}
                                value={form.data.acknowledgment_text[code]}
                                aria-describedby={`acknowledgment_text-${code}-help`}
                                onChange={(event) =>
                                    form.setData('acknowledgment_text', {
                                        ...form.data.acknowledgment_text,
                                        [code]: event.target.value,
                                    })
                                }
                            />
                        </FormField>
                    ))}
                    <div className="space-y-1">
                        <CheckboxField
                            id="ask_again"
                            label={t('privacy.askAgain')}
                            checked={form.data.ask_again}
                            onChange={(checked) =>
                                form.setData('ask_again', checked)
                            }
                        />
                        <p className="text-xs text-muted-foreground">
                            {t('privacy.askAgainHelp', {
                                version: settings.acknowledgment_version,
                            })}
                        </p>
                    </div>
                </section>

                <Separator />

                <section className="space-y-4">
                    <div>
                        <h3 className="text-sm font-medium">
                            {t('privacy.retention')}
                        </h3>
                        <p className="text-sm text-muted-foreground">
                            {t('privacy.retentionHelp')}
                        </p>
                    </div>
                    {numberField(
                        'conversation_retention_days',
                        t('privacy.conversationDays'),
                        t('privacy.conversationDaysHelp'),
                    )}
                    {numberField(
                        'deleted_conversation_days',
                        t('privacy.deletedDays'),
                        t('privacy.deletedDaysHelp'),
                    )}
                    {numberField(
                        'usage_retention_months',
                        t('privacy.usageMonths'),
                        t('privacy.usageMonthsHelp'),
                    )}
                </section>

                <Button type="submit" disabled={form.processing}>
                    {tCommon('actions.save')}
                </Button>
            </form>
        </>
    );
}

Privacy.layout = {
    breadcrumbs: [{ titleKey: 'admin:privacy.title', href: edit() }],
};
