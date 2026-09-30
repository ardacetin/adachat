import { Head, Link, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatUsd } from '@/lib/format';
import { create, edit, index } from '@/routes/admin/budget-policies';

type PolicyRow = {
    id: number;
    name: string;
    monthly_limit_usd: string;
    groups_count: number;
    users_count: number;
};

export default function BudgetPoliciesIndex({
    policies,
}: {
    policies: PolicyRow[];
}) {
    const { t } = useTranslation('admin');
    const { locale } = usePage().props;

    return (
        <>
            <Head title={t('budgetPolicies.title')} />

            <div className="space-y-6">
                <div className="flex items-start justify-between gap-4">
                    <Heading
                        variant="small"
                        title={t('budgetPolicies.title')}
                        description={t('budgetPolicies.description')}
                    />
                    <Button asChild size="sm">
                        <Link href={create()}>{t('budgetPolicies.add')}</Link>
                    </Button>
                </div>

                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>{t('budgetPolicies.name')}</TableHead>
                            <TableHead className="text-right">
                                {t('budgetPolicies.monthlyLimit')}
                            </TableHead>
                            <TableHead className="text-right">
                                {t('budgetPolicies.groups')}
                            </TableHead>
                            <TableHead className="text-right">
                                {t('budgetPolicies.users')}
                            </TableHead>
                            <TableHead className="sr-only">
                                {t('common.actions')}
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {policies.map((policy) => (
                            <TableRow key={policy.id}>
                                <TableCell className="font-medium">
                                    {policy.name}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {formatUsd(
                                        policy.monthly_limit_usd,
                                        locale.current,
                                    )}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {policy.groups_count}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {policy.users_count}
                                </TableCell>
                                <TableCell className="text-right">
                                    <Button asChild variant="ghost" size="sm">
                                        <Link href={edit(policy.id)}>
                                            {t('common.edit')}
                                        </Link>
                                    </Button>
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>
        </>
    );
}

BudgetPoliciesIndex.layout = {
    breadcrumbs: [{ titleKey: 'admin:budgetPolicies.title', href: index() }],
};
