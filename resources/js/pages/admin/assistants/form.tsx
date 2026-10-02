import { Head, useForm, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import CheckboxField from '@/components/admin/checkbox-field';
import FormField from '@/components/admin/form-field';
import AssistantIcon from '@/components/chat/assistant-icon';
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
import { Textarea } from '@/components/ui/textarea';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { index, store, update } from '@/routes/admin/assistants';
import type { Locale } from '@/types/global';

type Assistant = {
    id: number;
    slug: string;
    name: Record<string, string>;
    description: Record<string, string> | null;
    instructions: string;
    model_alias_id: number;
    starter_prompts: string[];
    icon: string;
    sort_order: number;
    enabled: boolean;
    group_ids: number[];
};

type Props = {
    assistant: Assistant | null;
    aliases: { id: number; name: string; enabled: boolean }[];
    groups: { id: number; name: string; is_default: boolean }[];
    icons: string[];
};

const STARTERS = 4;

export default function AssistantForm({
    assistant,
    aliases,
    groups,
    icons,
}: Props) {
    const { t } = useTranslation('admin');
    const { t: tCommon } = useTranslation('common');
    const { locale } = usePage().props;

    const localized = (values: Record<string, string> | null | undefined) =>
        Object.fromEntries(
            locale.available.map((code) => [code, values?.[code] ?? '']),
        ) as Record<Locale, string>;

    const form = useForm({
        slug: assistant?.slug ?? '',
        name: localized(assistant?.name),
        description: localized(assistant?.description),
        instructions: assistant?.instructions ?? '',
        model_alias_id: String(
            assistant?.model_alias_id ?? aliases[0]?.id ?? '',
        ),
        starter_prompts: Array.from(
            { length: STARTERS },
            (_, position) => assistant?.starter_prompts[position] ?? '',
        ),
        icon: assistant?.icon ?? 'sparkles',
        sort_order: String(assistant?.sort_order ?? 0),
        enabled: assistant?.enabled ?? true,
        group_ids:
            assistant?.group_ids ??
            groups.filter((group) => group.is_default).map((group) => group.id),
    });

    const errors = form.errors as Record<string, string | undefined>;

    const toggleGroup = (id: number, checked: boolean) =>
        form.setData(
            'group_ids',
            checked
                ? [...form.data.group_ids, id]
                : form.data.group_ids.filter((groupId) => groupId !== id),
        );

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        if (assistant === null) {
            form.post(store.url());
        } else {
            form.put(update.url(assistant.id));
        }
    };

    const title =
        assistant === null ? t('assistants.create') : t('assistants.edit');

    if (aliases.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                {t('assistants.noAliases')}
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
                    label={t('assistants.slug')}
                    help={t('assistants.slugHelp')}
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

                <div className="grid gap-4 sm:grid-cols-2">
                    {locale.available.map((code) => (
                        <FormField
                            key={`name-${code}`}
                            id={`name-${code}`}
                            label={t('assistants.nameIn', {
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
                    ))}
                    {locale.available.map((code) => (
                        <FormField
                            key={`description-${code}`}
                            id={`description-${code}`}
                            label={t('assistants.descriptionIn', {
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
                    ))}
                </div>

                <FormField
                    id="instructions"
                    label={t('assistants.instructions')}
                    help={t('assistants.instructionsHelp')}
                    error={form.errors.instructions}
                >
                    <Textarea
                        id="instructions"
                        rows={8}
                        value={form.data.instructions}
                        aria-describedby="instructions-help"
                        onChange={(event) =>
                            form.setData('instructions', event.target.value)
                        }
                        required
                    />
                </FormField>

                <FormField
                    id="model_alias_id"
                    label={t('assistants.alias')}
                    help={t('assistants.aliasHelp')}
                    error={form.errors.model_alias_id}
                >
                    <Select
                        value={form.data.model_alias_id}
                        onValueChange={(value) =>
                            form.setData('model_alias_id', value)
                        }
                    >
                        <SelectTrigger
                            id="model_alias_id"
                            className="w-full"
                            aria-describedby="model_alias_id-help"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {aliases.map((alias) => (
                                <SelectItem
                                    key={alias.id}
                                    value={String(alias.id)}
                                >
                                    {alias.name}
                                    {!alias.enabled &&
                                        ` (${t('common.disabled')})`}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </FormField>

                <fieldset className="space-y-2">
                    <legend className="text-sm font-medium">
                        {t('assistants.starters')}
                    </legend>
                    <p className="text-sm text-muted-foreground">
                        {t('assistants.startersHelp')}
                    </p>
                    {form.data.starter_prompts.map((prompt, position) => (
                        <Input
                            key={position}
                            value={prompt}
                            maxLength={200}
                            aria-label={t('assistants.starter', {
                                number: position + 1,
                            })}
                            onChange={(event) =>
                                form.setData(
                                    'starter_prompts',
                                    form.data.starter_prompts.map(
                                        (value, index) =>
                                            index === position
                                                ? event.target.value
                                                : value,
                                    ),
                                )
                            }
                        />
                    ))}
                    {errors.starter_prompts && (
                        <p className="text-sm text-destructive">
                            {errors.starter_prompts}
                        </p>
                    )}
                </fieldset>

                <fieldset className="space-y-2">
                    <legend className="text-sm font-medium">
                        {t('assistants.icon')}
                    </legend>
                    <ToggleGroup
                        type="single"
                        variant="outline"
                        className="flex-wrap"
                        value={form.data.icon}
                        onValueChange={(value) =>
                            value && form.setData('icon', value)
                        }
                    >
                        {icons.map((icon) => (
                            <ToggleGroupItem
                                key={icon}
                                value={icon}
                                aria-label={icon}
                            >
                                <AssistantIcon name={icon} className="size-4" />
                            </ToggleGroupItem>
                        ))}
                    </ToggleGroup>
                </fieldset>

                <FormField
                    id="sort_order"
                    label={t('assistants.sortOrder')}
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
                    id="enabled"
                    label={t('assistants.enabled')}
                    checked={form.data.enabled}
                    onChange={(checked) => form.setData('enabled', checked)}
                />

                <fieldset className="space-y-3">
                    <legend className="text-sm font-medium">
                        {t('assistants.groups')}
                    </legend>
                    <p className="text-sm text-muted-foreground">
                        {t('assistants.groupsHelp')}
                    </p>
                    {groups.map((group) => (
                        <CheckboxField
                            key={group.id}
                            id={`group-${group.id}`}
                            label={group.name}
                            checked={form.data.group_ids.includes(group.id)}
                            onChange={(checked) =>
                                toggleGroup(group.id, checked)
                            }
                        />
                    ))}
                </fieldset>

                <Button type="submit" disabled={form.processing}>
                    {tCommon('actions.save')}
                </Button>
            </form>
        </>
    );
}

AssistantForm.layout = {
    breadcrumbs: [{ titleKey: 'admin:assistants.title', href: index() }],
};
