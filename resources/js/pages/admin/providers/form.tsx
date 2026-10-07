import { Head, router, useForm } from '@inertiajs/react';
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
import { check, index, store, update } from '@/routes/admin/providers';

type Driver = { value: string; label: string; base_url: string };

type Props = {
    provider: {
        id: number;
        slug: string;
        driver: string;
        name: string;
        base_url: string | null;
        enabled: boolean;
        masked_key: string | null;
    } | null;
    drivers: Driver[];
};

export default function ProviderForm({ provider, drivers }: Props) {
    const { t } = useTranslation('admin');
    const { t: tCommon } = useTranslation('common');

    const form = useForm({
        slug: provider?.slug ?? '',
        driver: provider?.driver ?? drivers[0].value,
        name: provider?.name ?? '',
        base_url: provider?.base_url ?? '',
        enabled: provider?.enabled ?? true,
        api_key: '',
    });

    const defaultUrl =
        drivers.find((driver) => driver.value === form.data.driver)?.base_url ??
        '';
    // OpenAI-compatible endpoints (OpenRouter, Ollama, vLLM…) have no
    // default address, and local servers need no key.
    const compatible = form.data.driver === 'openai_compatible';
    // Azure OpenAI: the resource's v1 endpoint, always with its key.
    const azure = form.data.driver === 'azure_openai';

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        // The driver is fixed once the provider exists.
        form.transform(({ driver, ...data }) =>
            provider === null ? { ...data, driver } : data,
        );

        if (provider === null) {
            form.post(store.url());
        } else {
            form.put(update.url(provider.id), {
                onSuccess: () => form.reset('api_key'),
            });
        }
    };

    const title =
        provider === null ? t('providers.create') : t('providers.edit');

    return (
        <>
            <Head title={title} />

            <form onSubmit={submit} className="space-y-6">
                <Heading variant="small" title={title} />

                <FormField
                    id="name"
                    label={t('providers.name')}
                    error={form.errors.name}
                >
                    <Input
                        id="name"
                        value={form.data.name}
                        onChange={(event) =>
                            form.setData('name', event.target.value)
                        }
                        required
                    />
                </FormField>

                <FormField
                    id="slug"
                    label={t('providers.slug')}
                    help={t('providers.slugHelp')}
                    error={form.errors.slug}
                >
                    <Input
                        id="slug"
                        className="font-mono"
                        value={form.data.slug}
                        aria-describedby="slug-help"
                        onChange={(event) =>
                            form.setData('slug', event.target.value)
                        }
                        required
                    />
                </FormField>

                <FormField
                    id="driver"
                    label={t('providers.driver')}
                    help={
                        provider === null
                            ? undefined
                            : t('providers.driverLocked')
                    }
                    error={form.errors.driver}
                >
                    <Select
                        value={form.data.driver}
                        disabled={provider !== null}
                        onValueChange={(value) => form.setData('driver', value)}
                    >
                        <SelectTrigger id="driver" className="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {drivers.map((driver) => (
                                <SelectItem
                                    key={driver.value}
                                    value={driver.value}
                                >
                                    {driver.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </FormField>

                <FormField
                    id="base_url"
                    label={t('providers.baseUrl')}
                    help={
                        compatible
                            ? t('providers.baseUrlCompatibleHelp')
                            : azure
                              ? t('providers.baseUrlAzureHelp')
                              : t('providers.baseUrlHelp', { url: defaultUrl })
                    }
                    error={form.errors.base_url}
                >
                    <Input
                        id="base_url"
                        type="url"
                        value={form.data.base_url}
                        placeholder={
                            compatible
                                ? 'https://openrouter.ai/api/v1'
                                : azure
                                  ? 'https://your-resource.openai.azure.com/openai/v1'
                                  : defaultUrl
                        }
                        required={compatible || azure}
                        aria-describedby="base_url-help"
                        onChange={(event) =>
                            form.setData('base_url', event.target.value)
                        }
                    />
                </FormField>

                <FormField
                    id="api_key"
                    label={t('providers.apiKey')}
                    help={
                        compatible
                            ? `${t('providers.apiKeyHelp')} ${t('providers.apiKeyOptional')}`
                            : t('providers.apiKeyHelp')
                    }
                    error={form.errors.api_key}
                >
                    {provider?.masked_key && (
                        <p className="font-mono text-xs text-muted-foreground">
                            {t('providers.apiKeyCurrent', {
                                masked: provider.masked_key,
                            })}
                        </p>
                    )}
                    <Input
                        id="api_key"
                        type="password"
                        autoComplete="off"
                        value={form.data.api_key}
                        aria-describedby="api_key-help"
                        onChange={(event) =>
                            form.setData('api_key', event.target.value)
                        }
                    />
                </FormField>

                <CheckboxField
                    id="enabled"
                    label={t('providers.enabled')}
                    checked={form.data.enabled}
                    onChange={(checked) => form.setData('enabled', checked)}
                />

                <div className="flex flex-wrap gap-2">
                    <Button type="submit" disabled={form.processing}>
                        {tCommon('actions.save')}
                    </Button>
                    {provider !== null && (
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() =>
                                router.post(
                                    check.url(provider.id),
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            {t('providers.check')}
                        </Button>
                    )}
                </div>
            </form>
        </>
    );
}

ProviderForm.layout = {
    breadcrumbs: [{ titleKey: 'admin:providers.title', href: index() }],
};
