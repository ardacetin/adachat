import { Head, useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import CheckboxField from '@/components/admin/checkbox-field';
import DeleteButton from '@/components/admin/delete-button';
import FormField from '@/components/admin/form-field';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { destroy, index, store, update } from '@/routes/admin/budget-policies';

type Policy = {
    id: number;
    name: string;
    monthly_limit_usd: string;
    users_count: number;
    groups_count: number;
};

/** "12.5000000000" → "12.5" for the input. */
const plain = (value: string) => String(Number(value));

export default function BudgetPolicyForm({
    policy,
}: {
    policy: Policy | null;
}) {
    const { t } = useTranslation('admin');
    const { t: tCommon } = useTranslation('common');

    const form = useForm({
        name: policy?.name ?? '',
        monthly_limit_usd: policy ? plain(policy.monthly_limit_usd) : '',
        apply_to_current_period: true,
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        if (policy === null) {
            form.post(store.url());
        } else {
            form.put(update.url(policy.id));
        }
    };

    const title =
        policy === null ? t('budgetPolicies.create') : t('budgetPolicies.edit');
    const limitChanged =
        policy !== null &&
        form.data.monthly_limit_usd !== plain(policy.monthly_limit_usd);

    return (
        <>
            <Head title={title} />

            <form onSubmit={submit} className="space-y-6">
                <Heading variant="small" title={title} />

                <FormField
                    id="name"
                    label={t('budgetPolicies.name')}
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
                    id="monthly_limit_usd"
                    label={t('budgetPolicies.monthlyLimit')}
                    help={t('budgetPolicies.monthlyLimitHelp')}
                    error={form.errors.monthly_limit_usd}
                >
                    <Input
                        id="monthly_limit_usd"
                        inputMode="decimal"
                        className="w-40 tabular-nums"
                        value={form.data.monthly_limit_usd}
                        aria-describedby="monthly_limit_usd-help"
                        onChange={(event) =>
                            form.setData(
                                'monthly_limit_usd',
                                event.target.value,
                            )
                        }
                        required
                    />
                </FormField>

                {limitChanged && (
                    <div className="space-y-1">
                        <CheckboxField
                            id="apply_to_current_period"
                            label={t('budgetPolicies.applyToCurrentPeriod')}
                            checked={form.data.apply_to_current_period}
                            onChange={(checked) =>
                                form.setData('apply_to_current_period', checked)
                            }
                        />
                        <p className="text-xs text-muted-foreground">
                            {t('budgetPolicies.applyToCurrentPeriodHelp', {
                                count: policy.users_count,
                            })}
                        </p>
                    </div>
                )}

                <div className="flex items-center gap-2">
                    <Button type="submit" disabled={form.processing}>
                        {tCommon('actions.save')}
                    </Button>
                    {policy !== null && policy.groups_count === 0 && (
                        <DeleteButton
                            url={destroy.url(policy.id)}
                            title={t('budgetPolicies.deleteTitle')}
                            description={t('budgetPolicies.deleteConfirm', {
                                name: policy.name,
                            })}
                        />
                    )}
                </div>
            </form>
        </>
    );
}

BudgetPolicyForm.layout = {
    breadcrumbs: [{ titleKey: 'admin:budgetPolicies.title', href: index() }],
};
