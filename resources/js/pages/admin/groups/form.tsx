import { Head, useForm, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import CheckboxField from '@/components/admin/checkbox-field';
import DeleteButton from '@/components/admin/delete-button';
import FormField from '@/components/admin/form-field';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
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
import { formatUsd } from '@/lib/format';
import { destroy, index, store, update } from '@/routes/admin/groups';

type Group = {
    id: number;
    is_default: boolean;
    /** Only a super administrator may change it. */
    reserved: boolean;
    users_count: number;
    name: string;
    description: string | null;
    budget_policy_id: number;
    requests_per_minute: number;
    max_concurrent_streams: number;
    alias_ids: number[];
    idp_groups: string[] | null;
    idp_priority: number;
};

type Props = {
    group: Group | null;
    policies: { id: number; name: string; monthly_limit_usd: string }[];
    aliases: { id: number; name: string; slug: string; enabled: boolean }[];
};

export default function GroupForm({ group, policies, aliases }: Props) {
    const { t } = useTranslation('admin');
    const { t: tCommon } = useTranslation('common');
    const { locale } = usePage().props;

    const form = useForm({
        name: group?.name ?? '',
        description: group?.description ?? '',
        budget_policy_id: String(
            group?.budget_policy_id ?? policies[0]?.id ?? '',
        ),
        requests_per_minute: String(group?.requests_per_minute ?? 20),
        max_concurrent_streams: String(group?.max_concurrent_streams ?? 2),
        alias_ids: group?.alias_ids ?? [],
        idp_groups: (group?.idp_groups ?? []).join('\n'),
        idp_priority: String(group?.idp_priority ?? 100),
        apply_to_current_period: true,
    });

    const errors = form.errors as Record<string, string | undefined>;

    // Errors arrive per value (idp_groups.0, …) or for the whole list.
    const idpErrors = Object.entries(errors)
        .filter(([key]) => key.startsWith('idp_groups'))
        .map(([, message]) => message)
        .filter((message): message is string => Boolean(message));

    const toggleAlias = (id: number, checked: boolean) =>
        form.setData(
            'alias_ids',
            checked
                ? [...form.data.alias_ids, id]
                : form.data.alias_ids.filter((aliasId) => aliasId !== id),
        );

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        if (group === null) {
            form.post(store.url());
        } else {
            form.put(update.url(group.id));
        }
    };

    const title = group === null ? t('groups.create') : t('groups.edit');
    const policyChanged =
        group !== null &&
        form.data.budget_policy_id !== String(group.budget_policy_id);

    return (
        <>
            <Head title={title} />

            <form onSubmit={submit} className="space-y-6">
                <Heading
                    variant="small"
                    title={title}
                    description={
                        group?.is_default ? t('groups.defaultHelp') : undefined
                    }
                />

                {group?.reserved && (
                    <Alert>
                        <AlertDescription>
                            {t('groups.reserved')}
                        </AlertDescription>
                    </Alert>
                )}

                <FormField
                    id="name"
                    label={t('groups.name')}
                    error={form.errors.name}
                >
                    <Input
                        id="name"
                        value={form.data.name}
                        onChange={(event) =>
                            form.setData('name', event.target.value)
                        }
                        maxLength={100}
                        required
                    />
                </FormField>

                <FormField
                    id="description"
                    label={t('groups.descriptionField')}
                    error={form.errors.description}
                >
                    <Textarea
                        id="description"
                        rows={2}
                        value={form.data.description}
                        onChange={(event) =>
                            form.setData('description', event.target.value)
                        }
                        maxLength={500}
                    />
                </FormField>

                <Separator />

                <FormField
                    id="budget_policy_id"
                    label={t('groups.policy')}
                    error={form.errors.budget_policy_id}
                >
                    <Select
                        value={form.data.budget_policy_id}
                        onValueChange={(value) =>
                            form.setData('budget_policy_id', value)
                        }
                    >
                        <SelectTrigger id="budget_policy_id" className="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {policies.map((policy) => (
                                <SelectItem
                                    key={policy.id}
                                    value={String(policy.id)}
                                >
                                    {policy.name} (
                                    {formatUsd(
                                        policy.monthly_limit_usd,
                                        locale.current,
                                    )}
                                    )
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </FormField>

                {policyChanged && (
                    <div className="space-y-1">
                        <CheckboxField
                            id="apply_to_current_period"
                            label={t('groups.applyToCurrentPeriod')}
                            checked={form.data.apply_to_current_period}
                            onChange={(checked) =>
                                form.setData('apply_to_current_period', checked)
                            }
                        />
                        <p className="text-xs text-muted-foreground">
                            {t('groups.applyToCurrentPeriodHelp')}
                        </p>
                    </div>
                )}

                <div className="grid gap-4 sm:grid-cols-2">
                    <FormField
                        id="requests_per_minute"
                        label={t('groups.requestsPerMinute')}
                        help={t('groups.requestsPerMinuteHelp')}
                        error={form.errors.requests_per_minute}
                    >
                        <Input
                            id="requests_per_minute"
                            type="number"
                            min={1}
                            max={1000}
                            value={form.data.requests_per_minute}
                            aria-describedby="requests_per_minute-help"
                            onChange={(event) =>
                                form.setData(
                                    'requests_per_minute',
                                    event.target.value,
                                )
                            }
                            required
                        />
                    </FormField>
                    <FormField
                        id="max_concurrent_streams"
                        label={t('groups.maxConcurrentStreams')}
                        help={t('groups.maxConcurrentStreamsHelp')}
                        error={form.errors.max_concurrent_streams}
                    >
                        <Input
                            id="max_concurrent_streams"
                            type="number"
                            min={1}
                            max={20}
                            value={form.data.max_concurrent_streams}
                            aria-describedby="max_concurrent_streams-help"
                            onChange={(event) =>
                                form.setData(
                                    'max_concurrent_streams',
                                    event.target.value,
                                )
                            }
                            required
                        />
                    </FormField>
                </div>

                <Separator />

                <div className="grid gap-4 sm:grid-cols-[1fr_12rem]">
                    <FormField
                        id="idp_groups"
                        label={t('groups.idpGroups')}
                        help={t('groups.idpGroupsHelp')}
                    >
                        <Textarea
                            id="idp_groups"
                            rows={3}
                            className="font-mono"
                            value={form.data.idp_groups}
                            aria-describedby="idp_groups-help"
                            aria-invalid={idpErrors.length > 0}
                            onChange={(event) =>
                                form.setData('idp_groups', event.target.value)
                            }
                        />
                        {idpErrors.map((message) => (
                            <InputError key={message} message={message} />
                        ))}
                    </FormField>
                    <FormField
                        id="idp_priority"
                        label={t('groups.idpPriority')}
                        help={t('groups.idpPriorityHelp')}
                        error={form.errors.idp_priority}
                    >
                        <Input
                            id="idp_priority"
                            type="number"
                            min={1}
                            max={1000}
                            value={form.data.idp_priority}
                            aria-describedby="idp_priority-help"
                            onChange={(event) =>
                                form.setData('idp_priority', event.target.value)
                            }
                        />
                    </FormField>
                </div>

                <Separator />

                <fieldset className="space-y-3">
                    <legend className="text-sm font-medium">
                        {t('groups.aliasesField')}
                    </legend>
                    <p className="text-sm text-muted-foreground">
                        {t('groups.aliasesHelp')}
                    </p>
                    {aliases.length === 0 && (
                        <p className="text-sm text-muted-foreground">
                            {t('groups.noAliases')}
                        </p>
                    )}
                    {aliases.map((alias) => (
                        <CheckboxField
                            key={alias.id}
                            id={`alias-${alias.id}`}
                            label={
                                alias.enabled
                                    ? alias.name
                                    : `${alias.name} (${t('groups.aliasDisabled')})`
                            }
                            checked={form.data.alias_ids.includes(alias.id)}
                            onChange={(checked) =>
                                toggleAlias(alias.id, checked)
                            }
                        />
                    ))}
                    {errors.alias_ids && (
                        <p className="text-sm text-destructive">
                            {errors.alias_ids}
                        </p>
                    )}
                </fieldset>

                <div className="flex items-center gap-2">
                    <Button
                        type="submit"
                        disabled={form.processing || group?.reserved === true}
                    >
                        {tCommon('actions.save')}
                    </Button>
                    {group !== null &&
                        !group.is_default &&
                        group.users_count === 0 && (
                            <DeleteButton
                                url={destroy.url(group.id)}
                                title={t('groups.deleteTitle')}
                                description={t('groups.deleteConfirm', {
                                    name: group.name,
                                })}
                            />
                        )}
                </div>
            </form>
        </>
    );
}

GroupForm.layout = {
    breadcrumbs: [{ titleKey: 'admin:groups.title', href: index() }],
};
