import { Head, useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import FormField from '@/components/admin/form-field';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { update } from '@/routes/admin/texts';

/** locale → field → text */
type Texts = Record<string, Record<string, string>>;

type LandingField =
    | 'title'
    | 'lead'
    | 'sign_in_hint'
    | 'models_title'
    | 'models_text'
    | 'budget_title'
    | 'budget_text'
    | 'privacy_title'
    | 'privacy_text'
    | 'footer';

type InvitationField = 'subject' | 'heading' | 'body' | 'sign_in' | 'button';

type Section<F extends string> = {
    fields: F[];
    values: Texts;
    defaults: Texts;
};

type Kind = 'landing' | 'invitation';

type Props = {
    locales: string[];
    landing: Section<LandingField>;
    invitation: Section<InvitationField>;
};

const PARAGRAPHS = new Set([
    'lead',
    'models_text',
    'budget_text',
    'privacy_text',
    'body',
]);

const LOCALE_NAMES: Record<string, string> = { en: 'English', tr: 'Türkçe' };

function initial(locales: string[], section: Section<string>): Texts {
    return Object.fromEntries(
        locales.map((locale) => [
            locale,
            Object.fromEntries(
                section.fields.map((field) => [
                    field,
                    section.values[locale]?.[field] ?? '',
                ]),
            ),
        ]),
    );
}

/**
 * Admin → Texts: the landing page and the invitation e-mail, per language.
 * Ada's own text shows greyed as the placeholder; an empty field keeps it.
 */
export default function TextsPage({ locales, landing, invitation }: Props) {
    const { t } = useTranslation('admin');
    const form = useForm<Record<Kind, Texts>>({
        landing: initial(locales, landing),
        invitation: initial(locales, invitation),
    });
    const errors = form.errors as Record<string, string | undefined>;

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.put(update.url(), { preserveScroll: true });
    };

    const set = (kind: Kind, locale: string, field: string, value: string) =>
        form.setData(kind, {
            ...form.data[kind],
            [locale]: { ...form.data[kind][locale], [field]: value },
        });

    const section = <F extends string>(
        kind: Kind,
        data: Section<F>,
        label: (field: F) => string,
    ) => (
        <section className="space-y-5">
            <Heading
                variant="small"
                title={t(`texts.${kind}.title`)}
                description={t(`texts.${kind}.description`)}
            />
            {data.fields.map((field) => (
                <fieldset
                    key={field}
                    className="grid gap-3 rounded-lg border p-4"
                >
                    <legend className="px-1 text-sm font-medium">
                        {label(field)}
                    </legend>
                    <div className="grid gap-3 md:grid-cols-2">
                        {locales.map((locale) => {
                            const id = `${kind}-${locale}-${field}`;
                            const key = `${kind}.${locale}.${field}`;
                            const value =
                                form.data[kind][locale]?.[field] ?? '';
                            const placeholder = data.defaults[locale]?.[field];

                            return (
                                <FormField
                                    key={locale}
                                    id={id}
                                    label={LOCALE_NAMES[locale] ?? locale}
                                    error={errors[key]}
                                >
                                    {PARAGRAPHS.has(field) ? (
                                        <Textarea
                                            id={id}
                                            rows={3}
                                            value={value}
                                            placeholder={placeholder}
                                            aria-invalid={
                                                errors[key] !== undefined
                                            }
                                            onChange={(event) =>
                                                set(
                                                    kind,
                                                    locale,
                                                    field,
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    ) : (
                                        <Input
                                            id={id}
                                            value={value}
                                            placeholder={placeholder}
                                            aria-invalid={
                                                errors[key] !== undefined
                                            }
                                            onChange={(event) =>
                                                set(
                                                    kind,
                                                    locale,
                                                    field,
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    )}
                                </FormField>
                            );
                        })}
                    </div>
                </fieldset>
            ))}
        </section>
    );

    return (
        <>
            <Head title={t('texts.title')} />

            <form onSubmit={submit} className="space-y-10">
                <Heading
                    title={t('texts.title')}
                    description={t('texts.description')}
                />
                <p className="-mt-6 text-sm text-muted-foreground">
                    {t('texts.placeholders')}
                </p>

                {section('landing', landing, (field) =>
                    t(`texts.landing.fields.${field}`),
                )}
                {section('invitation', invitation, (field) =>
                    t(`texts.invitation.fields.${field}`),
                )}

                <Button type="submit" disabled={form.processing}>
                    {t('texts.save')}
                </Button>
            </form>
        </>
    );
}
