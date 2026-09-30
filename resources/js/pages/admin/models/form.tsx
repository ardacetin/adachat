import { Head, useForm } from '@inertiajs/react';
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
import { index, store, update } from '@/routes/admin/models';

type AiModel = {
    id: number;
    provider_id: number;
    provider_model_id: string;
    display_name: string;
    description: string | null;
    input_price_per_million: string;
    output_price_per_million: string;
    cached_input_price_per_million: string | null;
    cache_write_price_per_million: string | null;
    context_window: number;
    max_output_tokens: number;
    supports_vision: boolean;
    supports_files: boolean;
    supports_tools: boolean;
    supports_reasoning: boolean;
    enabled: boolean;
};

type Props = {
    model: AiModel | null;
    providers: { id: number; name: string }[];
};

type PriceField =
    | 'input_price_per_million'
    | 'output_price_per_million'
    | 'cached_input_price_per_million'
    | 'cache_write_price_per_million';

type CapabilityField =
    | 'supports_vision'
    | 'supports_files'
    | 'supports_tools'
    | 'supports_reasoning';

export default function ModelForm({ model, providers }: Props) {
    const { t } = useTranslation('admin');
    const { t: tCommon } = useTranslation('common');

    const form = useForm({
        provider_id: String(model?.provider_id ?? providers[0]?.id ?? ''),
        provider_model_id: model?.provider_model_id ?? '',
        display_name: model?.display_name ?? '',
        description: model?.description ?? '',
        input_price_per_million: model?.input_price_per_million ?? '',
        output_price_per_million: model?.output_price_per_million ?? '',
        cached_input_price_per_million:
            model?.cached_input_price_per_million ?? '',
        cache_write_price_per_million:
            model?.cache_write_price_per_million ?? '',
        context_window: String(model?.context_window ?? ''),
        max_output_tokens: String(model?.max_output_tokens ?? ''),
        supports_vision: model?.supports_vision ?? false,
        supports_files: model?.supports_files ?? false,
        supports_tools: model?.supports_tools ?? false,
        supports_reasoning: model?.supports_reasoning ?? false,
        enabled: model?.enabled ?? true,
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        if (model === null) {
            form.post(store.url());
        } else {
            form.put(update.url(model.id));
        }
    };

    const priceInput = (field: PriceField, label: string, help?: string) => (
        <FormField
            id={field}
            label={label}
            help={help}
            error={form.errors[field]}
        >
            <Input
                id={field}
                inputMode="decimal"
                className="tabular-nums"
                value={form.data[field]}
                aria-describedby={help ? `${field}-help` : undefined}
                onChange={(event) => form.setData(field, event.target.value)}
            />
        </FormField>
    );

    const capabilities: { field: CapabilityField; label: string }[] = [
        { field: 'supports_vision', label: t('models.vision') },
        { field: 'supports_files', label: t('models.files') },
        { field: 'supports_tools', label: t('models.tools') },
        { field: 'supports_reasoning', label: t('models.reasoning') },
    ];

    const title = model === null ? t('models.create') : t('models.edit');

    if (providers.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                {t('models.noProviders')}
            </p>
        );
    }

    return (
        <>
            <Head title={title} />

            <form onSubmit={submit} className="space-y-6">
                <Heading variant="small" title={title} />

                <FormField
                    id="provider_id"
                    label={t('models.provider')}
                    error={form.errors.provider_id}
                >
                    <Select
                        value={form.data.provider_id}
                        onValueChange={(value) =>
                            form.setData('provider_id', value)
                        }
                    >
                        <SelectTrigger id="provider_id" className="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {providers.map((provider) => (
                                <SelectItem
                                    key={provider.id}
                                    value={String(provider.id)}
                                >
                                    {provider.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </FormField>

                <FormField
                    id="provider_model_id"
                    label={t('models.providerModelId')}
                    help={t('models.providerModelIdHelp')}
                    error={form.errors.provider_model_id}
                >
                    <Input
                        id="provider_model_id"
                        className="font-mono"
                        value={form.data.provider_model_id}
                        aria-describedby="provider_model_id-help"
                        onChange={(event) =>
                            form.setData(
                                'provider_model_id',
                                event.target.value,
                            )
                        }
                        required
                    />
                </FormField>

                <FormField
                    id="display_name"
                    label={t('models.displayName')}
                    error={form.errors.display_name}
                >
                    <Input
                        id="display_name"
                        value={form.data.display_name}
                        onChange={(event) =>
                            form.setData('display_name', event.target.value)
                        }
                        required
                    />
                </FormField>

                <FormField
                    id="description"
                    label={t('models.descriptionField')}
                    error={form.errors.description}
                >
                    <Textarea
                        id="description"
                        rows={2}
                        value={form.data.description}
                        onChange={(event) =>
                            form.setData('description', event.target.value)
                        }
                    />
                </FormField>

                <Separator />

                <section className="space-y-4">
                    <h3 className="text-sm font-medium">
                        {t('models.pricing')}
                    </h3>
                    <div className="grid gap-4 sm:grid-cols-2">
                        {priceInput(
                            'input_price_per_million',
                            t('models.inputPrice'),
                        )}
                        {priceInput(
                            'output_price_per_million',
                            t('models.outputPrice'),
                        )}
                        {priceInput(
                            'cached_input_price_per_million',
                            t('models.cachedInputPrice'),
                            t('models.optionalPriceHelp'),
                        )}
                        {priceInput(
                            'cache_write_price_per_million',
                            t('models.cacheWritePrice'),
                            t('models.optionalPriceHelp'),
                        )}
                    </div>
                </section>

                <Separator />

                <section className="space-y-4">
                    <h3 className="text-sm font-medium">
                        {t('models.limits')}
                    </h3>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <FormField
                            id="context_window"
                            label={t('models.contextWindow')}
                            error={form.errors.context_window}
                        >
                            <Input
                                id="context_window"
                                type="number"
                                min={1}
                                value={form.data.context_window}
                                onChange={(event) =>
                                    form.setData(
                                        'context_window',
                                        event.target.value,
                                    )
                                }
                                required
                            />
                        </FormField>
                        <FormField
                            id="max_output_tokens"
                            label={t('models.maxOutputTokens')}
                            error={form.errors.max_output_tokens}
                        >
                            <Input
                                id="max_output_tokens"
                                type="number"
                                min={1}
                                value={form.data.max_output_tokens}
                                onChange={(event) =>
                                    form.setData(
                                        'max_output_tokens',
                                        event.target.value,
                                    )
                                }
                                required
                            />
                        </FormField>
                    </div>
                </section>

                <Separator />

                <section className="space-y-3">
                    <h3 className="text-sm font-medium">
                        {t('models.capabilities')}
                    </h3>
                    {capabilities.map(({ field, label }) => (
                        <CheckboxField
                            key={field}
                            id={field}
                            label={label}
                            checked={form.data[field]}
                            onChange={(checked) => form.setData(field, checked)}
                        />
                    ))}
                </section>

                <CheckboxField
                    id="enabled"
                    label={t('models.enabled')}
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

ModelForm.layout = {
    breadcrumbs: [{ titleKey: 'admin:models.title', href: index() }],
};
