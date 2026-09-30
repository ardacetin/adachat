import { Head, useForm, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import CheckboxField from '@/components/admin/checkbox-field';
import FormField from '@/components/admin/form-field';
import Heading from '@/components/heading';
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
import { index, store, update } from '@/routes/admin/aliases';
import type { Locale } from '@/types/global';

type Alias = {
    id: number;
    slug: string;
    name: Record<string, string>;
    description: Record<string, string> | null;
    ai_model_id: number;
    max_output_tokens: number | null;
    temperature: string | null;
    system_prompt: string | null;
    show_model_details: boolean;
    sort_order: number;
    enabled: boolean;
};

type Props = {
    alias: Alias | null;
    models: { id: number; label: string; max_output_tokens: number }[];
};

export default function AliasForm({ alias, models }: Props) {
    const { t } = useTranslation('admin');
    const { t: tCommon } = useTranslation('common');
    const { locale } = usePage().props;

    const localized = (values: Record<string, string> | null | undefined) =>
        Object.fromEntries(
            locale.available.map((code) => [code, values?.[code] ?? '']),
        ) as Record<Locale, string>;

    const form = useForm({
        slug: alias?.slug ?? '',
        name: localized(alias?.name),
        description: localized(alias?.description),
        ai_model_id: String(alias?.ai_model_id ?? models[0]?.id ?? ''),
        max_output_tokens: alias?.max_output_tokens?.toString() ?? '',
        temperature: alias?.temperature ?? '',
        system_prompt: alias?.system_prompt ?? '',
        show_model_details: alias?.show_model_details ?? false,
        sort_order: String(alias?.sort_order ?? 0),
        enabled: alias?.enabled ?? true,
    });

    const modelMax =
        models.find((model) => String(model.id) === form.data.ai_model_id)
            ?.max_output_tokens ?? 0;

    const errors = form.errors as Record<string, string | undefined>;

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        if (alias === null) {
            form.post(store.url());
        } else {
            form.put(update.url(alias.id));
        }
    };

    const title = alias === null ? t('aliases.create') : t('aliases.edit');

    if (models.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                {t('aliases.noModels')}
            </p>
        );
    }

    return (
        <>
            <Head title={title} />

            <form onSubmit={submit} className="space-y-6">
                <Heading variant="small" title={title} />

                <FormField
                    id="slug"
                    label={t('aliases.slug')}
                    error={form.errors.slug}
                >
                    <Input
                        id="slug"
                        className="font-mono"
                        value={form.data.slug}
                        onChange={(event) =>
                            form.setData('slug', event.target.value)
                        }
                        required
                    />
                </FormField>

                {locale.available.map((code) => (
                    <div key={code} className="grid gap-4 sm:grid-cols-2">
                        <FormField
                            id={`name-${code}`}
                            label={t('aliases.name', {
                                locale: code.toUpperCase(),
                            })}
                            error={errors[`name.${code}`]}
                        >
                            <Input
                                id={`name-${code}`}
                                value={form.data.name[code]}
                                onChange={(event) =>
                                    form.setData('name', {
                                        ...form.data.name,
                                        [code]: event.target.value,
                                    })
                                }
                                required
                            />
                        </FormField>
                        <FormField
                            id={`description-${code}`}
                            label={t('aliases.descriptionField', {
                                locale: code.toUpperCase(),
                            })}
                            error={errors[`description.${code}`]}
                        >
                            <Input
                                id={`description-${code}`}
                                value={form.data.description[code]}
                                onChange={(event) =>
                                    form.setData('description', {
                                        ...form.data.description,
                                        [code]: event.target.value,
                                    })
                                }
                            />
                        </FormField>
                    </div>
                ))}

                <Separator />

                <FormField
                    id="ai_model_id"
                    label={t('aliases.model')}
                    error={form.errors.ai_model_id}
                >
                    <Select
                        value={form.data.ai_model_id}
                        onValueChange={(value) =>
                            form.setData('ai_model_id', value)
                        }
                    >
                        <SelectTrigger id="ai_model_id" className="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {models.map((model) => (
                                <SelectItem
                                    key={model.id}
                                    value={String(model.id)}
                                >
                                    {model.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </FormField>

                <div className="grid gap-4 sm:grid-cols-2">
                    <FormField
                        id="max_output_tokens"
                        label={t('aliases.maxOutputTokens')}
                        help={t('aliases.maxOutputTokensHelp', {
                            max: modelMax.toLocaleString(),
                        })}
                        error={form.errors.max_output_tokens}
                    >
                        <Input
                            id="max_output_tokens"
                            type="number"
                            min={1}
                            max={modelMax}
                            value={form.data.max_output_tokens}
                            aria-describedby="max_output_tokens-help"
                            onChange={(event) =>
                                form.setData(
                                    'max_output_tokens',
                                    event.target.value,
                                )
                            }
                        />
                    </FormField>
                    <FormField
                        id="temperature"
                        label={t('aliases.temperature')}
                        help={t('aliases.temperatureHelp')}
                        error={form.errors.temperature}
                    >
                        <Input
                            id="temperature"
                            inputMode="decimal"
                            value={form.data.temperature}
                            aria-describedby="temperature-help"
                            onChange={(event) =>
                                form.setData('temperature', event.target.value)
                            }
                        />
                    </FormField>
                </div>

                <FormField
                    id="system_prompt"
                    label={t('aliases.systemPrompt')}
                    help={t('aliases.systemPromptHelp')}
                    error={form.errors.system_prompt}
                >
                    <Textarea
                        id="system_prompt"
                        rows={4}
                        value={form.data.system_prompt}
                        aria-describedby="system_prompt-help"
                        onChange={(event) =>
                            form.setData('system_prompt', event.target.value)
                        }
                    />
                </FormField>

                <FormField
                    id="sort_order"
                    label={t('aliases.sortOrder')}
                    error={form.errors.sort_order}
                >
                    <Input
                        id="sort_order"
                        type="number"
                        className="w-32"
                        value={form.data.sort_order}
                        onChange={(event) =>
                            form.setData('sort_order', event.target.value)
                        }
                    />
                </FormField>

                <CheckboxField
                    id="show_model_details"
                    label={t('aliases.showModelDetails')}
                    checked={form.data.show_model_details}
                    onChange={(checked) =>
                        form.setData('show_model_details', checked)
                    }
                />
                <CheckboxField
                    id="enabled"
                    label={t('aliases.enabled')}
                    checked={form.data.enabled}
                    onChange={(checked) => form.setData('enabled', checked)}
                />

                <Button type="submit" disabled={form.processing}>
                    {tCommon('actions.save')}
                </Button>
            </form>
        </>
    );
}

AliasForm.layout = {
    breadcrumbs: [{ titleKey: 'admin:aliases.title', href: index() }],
};
