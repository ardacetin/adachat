import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import FormField from '@/components/admin/form-field';
import ImageUploadField from '@/components/admin/image-upload-field';
import Heading from '@/components/heading';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { Textarea } from '@/components/ui/textarea';
import { edit, testMail, update } from '@/routes/admin/institution';
import type { Locale } from '@/types/global';

type Settings = {
    name: string;
    short_name: string | null;
    domain: string | null;
    support_email: string | null;
    default_locale: Locale;
    timezone: string;
    privacy_url: string | null;
    terms_url: string | null;
    primary_color: string | null;
    budget_display: BudgetDisplay;
    monthly_cap_usd: string | null;
    notification_emails: string[];
    has_logo: boolean;
    has_logo_dark: boolean;
    has_favicon: boolean;
};

type Props = {
    settings: Settings;
    timezones: string[];
    mailConfigured: boolean;
};

type InstitutionForm = {
    name: string;
    short_name: string;
    domain: string;
    support_email: string;
    default_locale: Locale;
    timezone: string;
    privacy_url: string;
    terms_url: string;
    primary_color: string;
    budget_display: BudgetDisplay;
    monthly_cap_usd: string;
    notification_emails: string;
    logo: File | null;
    logo_dark: File | null;
    favicon: File | null;
    remove_logo: boolean;
    remove_logo_dark: boolean;
    remove_favicon: boolean;
};

type TextField =
    | 'name'
    | 'short_name'
    | 'domain'
    | 'support_email'
    | 'privacy_url'
    | 'terms_url';

type BudgetDisplay = 'amount' | 'percent';

const DEFAULT_PICKER_COLOR = '#171717';

export default function Institution({
    settings,
    timezones,
    mailConfigured,
}: Props) {
    const { t } = useTranslation('admin');
    const { t: tCommon } = useTranslation('common');
    const { institution, locale } = usePage().props;

    const form = useForm<InstitutionForm>({
        name: settings.name,
        short_name: settings.short_name ?? '',
        domain: settings.domain ?? '',
        support_email: settings.support_email ?? '',
        default_locale: settings.default_locale,
        timezone: settings.timezone,
        privacy_url: settings.privacy_url ?? '',
        terms_url: settings.terms_url ?? '',
        primary_color: settings.primary_color ?? '',
        budget_display: settings.budget_display,
        monthly_cap_usd: settings.monthly_cap_usd ?? '',
        notification_emails: settings.notification_emails.join('\n'),
        logo: null,
        logo_dark: null,
        favicon: null,
        remove_logo: false,
        remove_logo_dark: false,
        remove_favicon: false,
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.post(update.url(), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () =>
                form.setData((data) => ({
                    ...data,
                    logo: null,
                    logo_dark: null,
                    favicon: null,
                    remove_logo: false,
                    remove_logo_dark: false,
                    remove_favicon: false,
                })),
        });
    };

    const textInput = (field: TextField, type = 'text') => ({
        id: field,
        type,
        value: form.data[field],
        onChange: (event: React.ChangeEvent<HTMLInputElement>) =>
            form.setData(field, event.target.value),
        'aria-invalid': form.errors[field] !== undefined,
    });

    return (
        <>
            <Head title={t('institution.title')} />

            <form onSubmit={submit} className="space-y-8">
                <Heading
                    variant="small"
                    title={t('institution.title')}
                    description={t('institution.description')}
                />

                <section className="space-y-4">
                    <h3 className="text-sm font-medium">
                        {t('institution.general')}
                    </h3>
                    <FormField
                        id="name"
                        label={t('institution.name')}
                        error={form.errors.name}
                    >
                        <Input {...textInput('name')} required />
                    </FormField>
                    <FormField
                        id="short_name"
                        label={t('institution.shortName')}
                        help={t('institution.shortNameHelp')}
                        error={form.errors.short_name}
                    >
                        <Input
                            {...textInput('short_name')}
                            aria-describedby="short_name-help"
                        />
                    </FormField>
                    <FormField
                        id="domain"
                        label={t('institution.domain')}
                        error={form.errors.domain}
                    >
                        <Input {...textInput('domain')} />
                    </FormField>
                    <FormField
                        id="support_email"
                        label={t('institution.supportEmail')}
                        error={form.errors.support_email}
                    >
                        <Input {...textInput('support_email', 'email')} />
                    </FormField>
                    <FormField
                        id="privacy_url"
                        label={t('institution.privacyUrl')}
                        error={form.errors.privacy_url}
                    >
                        <Input {...textInput('privacy_url', 'url')} />
                    </FormField>
                    <FormField
                        id="terms_url"
                        label={t('institution.termsUrl')}
                        error={form.errors.terms_url}
                    >
                        <Input {...textInput('terms_url', 'url')} />
                    </FormField>
                </section>

                <Separator />

                <section className="space-y-4">
                    <h3 className="text-sm font-medium">
                        {t('institution.localization')}
                    </h3>
                    <FormField
                        id="default_locale"
                        label={t('institution.defaultLocale')}
                        error={form.errors.default_locale}
                    >
                        <Select
                            value={form.data.default_locale}
                            onValueChange={(value) =>
                                form.setData('default_locale', value as Locale)
                            }
                        >
                            <SelectTrigger
                                id="default_locale"
                                className="w-full"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {locale.available.map((code) => (
                                    <SelectItem key={code} value={code}>
                                        {tCommon(`locales.${code}`)}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </FormField>
                    <FormField
                        id="timezone"
                        label={t('institution.timezone')}
                        help={t('institution.timezoneHelp')}
                        error={form.errors.timezone}
                    >
                        <Select
                            value={form.data.timezone}
                            onValueChange={(value) =>
                                form.setData('timezone', value)
                            }
                        >
                            <SelectTrigger
                                id="timezone"
                                className="w-full"
                                aria-describedby="timezone-help"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent className="max-h-72">
                                {timezones.map((zone) => (
                                    <SelectItem key={zone} value={zone}>
                                        {zone}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </FormField>
                    <FormField
                        id="budget_display"
                        label={t('institution.budgetDisplay')}
                        help={t('institution.budgetDisplayHelp')}
                        error={form.errors.budget_display}
                    >
                        <Select
                            value={form.data.budget_display}
                            onValueChange={(value) =>
                                form.setData(
                                    'budget_display',
                                    value as BudgetDisplay,
                                )
                            }
                        >
                            <SelectTrigger
                                id="budget_display"
                                className="w-full"
                                aria-describedby="budget_display-help"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="amount">
                                    {t('institution.budgetDisplayAmount')}
                                </SelectItem>
                                <SelectItem value="percent">
                                    {t('institution.budgetDisplayPercent')}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </FormField>
                </section>

                <Separator />

                <section className="space-y-4">
                    <h3 className="text-sm font-medium">
                        {t('institution.spending')}
                    </h3>
                    <FormField
                        id="monthly_cap_usd"
                        label={t('institution.cap')}
                        help={t('institution.capHelp')}
                        error={form.errors.monthly_cap_usd}
                    >
                        <Input
                            id="monthly_cap_usd"
                            inputMode="decimal"
                            className="w-40 tabular-nums"
                            value={form.data.monthly_cap_usd}
                            aria-describedby="monthly_cap_usd-help"
                            aria-invalid={
                                form.errors.monthly_cap_usd !== undefined
                            }
                            onChange={(event) =>
                                form.setData(
                                    'monthly_cap_usd',
                                    event.target.value,
                                )
                            }
                        />
                    </FormField>
                    <FormField
                        id="notification_emails"
                        label={t('institution.notificationEmails')}
                        help={t('institution.notificationEmailsHelp')}
                        error={
                            form.errors.notification_emails ??
                            Object.entries(form.errors).find(([key]) =>
                                key.startsWith('notification_emails.'),
                            )?.[1]
                        }
                    >
                        <Textarea
                            id="notification_emails"
                            rows={3}
                            value={form.data.notification_emails}
                            aria-describedby="notification_emails-help"
                            onChange={(event) =>
                                form.setData(
                                    'notification_emails',
                                    event.target.value,
                                )
                            }
                        />
                    </FormField>
                    {!mailConfigured && (
                        <Alert>
                            <AlertDescription>
                                {t('institution.mailNotConfigured')}
                            </AlertDescription>
                        </Alert>
                    )}
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() =>
                            router.post(
                                testMail.url(),
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        {t('institution.testMail')}
                    </Button>
                </section>

                <Separator />

                <section className="space-y-4">
                    <h3 className="text-sm font-medium">
                        {t('institution.branding')}
                    </h3>
                    <FormField
                        id="primary_color"
                        label={t('institution.primaryColor')}
                        help={t('institution.primaryColorHelp')}
                        error={form.errors.primary_color}
                    >
                        <div className="flex flex-wrap items-center gap-2">
                            <Input
                                id="primary_color"
                                type="color"
                                className="h-9 w-14 p-1"
                                value={
                                    form.data.primary_color ||
                                    DEFAULT_PICKER_COLOR
                                }
                                aria-describedby="primary_color-help"
                                onChange={(event) =>
                                    form.setData(
                                        'primary_color',
                                        event.target.value,
                                    )
                                }
                            />
                            <Input
                                className="w-32 font-mono"
                                value={form.data.primary_color}
                                aria-label={t('institution.primaryColor')}
                                aria-invalid={
                                    form.errors.primary_color !== undefined
                                }
                                placeholder={DEFAULT_PICKER_COLOR}
                                onChange={(event) =>
                                    form.setData(
                                        'primary_color',
                                        event.target.value,
                                    )
                                }
                            />
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={() =>
                                    form.setData('primary_color', '')
                                }
                            >
                                {t('institution.resetColor')}
                            </Button>
                        </div>
                    </FormField>

                    <ImageUploadField
                        id="logo"
                        label={t('institution.logo')}
                        help={t('institution.logoHelp')}
                        accept="image/png,image/jpeg,image/webp"
                        currentUrl={
                            settings.has_logo ? institution.logoUrl : null
                        }
                        error={form.errors.logo}
                        remove={form.data.remove_logo}
                        onFile={(file) => form.setData('logo', file)}
                        onRemove={(remove) =>
                            form.setData('remove_logo', remove)
                        }
                    />
                    <ImageUploadField
                        id="logo_dark"
                        label={t('institution.logoDark')}
                        help={t('institution.logoHelp')}
                        accept="image/png,image/jpeg,image/webp"
                        currentUrl={
                            settings.has_logo_dark
                                ? institution.logoDarkUrl
                                : null
                        }
                        error={form.errors.logo_dark}
                        remove={form.data.remove_logo_dark}
                        onFile={(file) => form.setData('logo_dark', file)}
                        onRemove={(remove) =>
                            form.setData('remove_logo_dark', remove)
                        }
                    />
                    <ImageUploadField
                        id="favicon"
                        label={t('institution.favicon')}
                        help={t('institution.faviconHelp')}
                        accept="image/png"
                        currentUrl={
                            settings.has_favicon ? institution.faviconUrl : null
                        }
                        error={form.errors.favicon}
                        remove={form.data.remove_favicon}
                        onFile={(file) => form.setData('favicon', file)}
                        onRemove={(remove) =>
                            form.setData('remove_favicon', remove)
                        }
                    />
                </section>

                <Button type="submit" disabled={form.processing}>
                    {tCommon('actions.save')}
                </Button>
            </form>
        </>
    );
}

Institution.layout = {
    breadcrumbs: [{ titleKey: 'admin:institution.title', href: edit() }],
};
