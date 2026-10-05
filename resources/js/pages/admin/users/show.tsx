import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import DeleteButton from '@/components/admin/delete-button';
import CheckboxField from '@/components/admin/checkbox-field';
import FormField from '@/components/admin/form-field';
import UsageDetails from '@/components/budget/usage-details';
import type { UsageData } from '@/components/budget/usage-details';
import Heading from '@/components/heading';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatUsd } from '@/lib/format';
import {
    adjustments as adjustmentsRoute,
    budget as budgetRoute,
    destroy,
    group as groupRoute,
    index,
    invitation as invitationRoute,
    role as roleRoute,
    status as statusRoute,
} from '@/routes/admin/users';

type Role = 'super_admin' | 'admin' | 'user';

type Props = {
    user: {
        id: number;
        name: string;
        email: string;
        role: Role;
        status: 'active' | 'disabled';
        group_id: number;
        group: string;
        policy_limit_usd: string;
        override_usd: string | null;
        created_at: string | null;
        last_login_at: string | null;
        last_active_at: string | null;
        invited_at: string | null;
        invitation_pending: boolean;
        is_self: boolean;
    };
    usage: UsageData;
    adjustments: {
        id: string;
        amount_usd: string;
        reason: string | null;
        by: string | null;
        created_at: string;
    }[];
    groups: { id: number; name: string }[];
    permissions: {
        update: boolean;
        changeStatus: boolean;
        changeRole: boolean;
        adjustBudget: boolean;
        removeInvitation: boolean;
        sendInvitation: boolean;
    };
};

export default function UserShow({
    user,
    usage,
    adjustments,
    groups,
    permissions: can,
}: Props) {
    const { t } = useTranslation('admin');
    const { locale } = usePage().props;
    const lang = locale.current;

    const dateTime = (value: string | null) =>
        value === null
            ? t('common.never')
            : new Intl.DateTimeFormat(lang, {
                  dateStyle: 'medium',
                  timeStyle: 'short',
              }).format(new Date(value));

    const groupForm = useForm({
        group_id: String(user.group_id),
        apply_to_current_period: true,
    });
    const budgetForm = useForm({
        monthly_limit_usd: user.override_usd ?? '',
        apply_to_current_period: true,
    });
    const statusForm = useForm({
        status: user.status === 'active' ? 'disabled' : 'active',
    });
    const roleForm = useForm({ role: user.role });
    const adjustForm = useForm({ amount_usd: '', reason: '' });

    return (
        <>
            <Head title={user.name} />

            <div className="space-y-6">
                <div>
                    <Link
                        href={index()}
                        className="text-sm text-muted-foreground underline-offset-4 hover:underline"
                    >
                        ← {t('users.back')}
                    </Link>
                </div>

                <div className="flex flex-wrap items-start justify-between gap-2">
                    <Heading
                        variant="small"
                        title={user.name}
                        description={user.email}
                    />
                    <div className="flex gap-1">
                        <Badge variant="secondary">
                            {t(`roles.${user.role}`)}
                        </Badge>
                        <Badge
                            variant={
                                user.status === 'active'
                                    ? 'outline'
                                    : 'destructive'
                            }
                        >
                            {t(`statuses.${user.status}`)}
                        </Badge>
                    </div>
                </div>

                <dl className="grid gap-4 text-sm sm:grid-cols-3">
                    {(
                        [
                            ['created', user.created_at],
                            ['lastLogin', user.last_login_at],
                            ['lastActive', user.last_active_at],
                        ] as const
                    ).map(([key, value]) => (
                        <div key={key}>
                            <dt className="text-muted-foreground">
                                {t(`users.${key}`)}
                            </dt>
                            <dd>{dateTime(value)}</dd>
                        </div>
                    ))}
                </dl>

                {user.invitation_pending && (
                    <Alert data-test="invitation-pending">
                        <AlertDescription className="flex flex-wrap items-center justify-between gap-2">
                            <span>
                                {t('users.invitedHelp', {
                                    date: dateTime(user.invited_at),
                                })}
                            </span>
                            <span className="flex flex-wrap gap-2">
                                {can.sendInvitation && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        data-test="send-invitation"
                                        onClick={() =>
                                            router.post(
                                                invitationRoute.url(user.id),
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        {t('users.sendInvitation')}
                                    </Button>
                                )}
                                {can.removeInvitation && (
                                    <DeleteButton
                                        url={destroy.url(user.id)}
                                        title={t('users.removeInvitationTitle')}
                                        description={t(
                                            'users.removeInvitationHelp',
                                        )}
                                    />
                                )}
                            </span>
                        </AlertDescription>
                    </Alert>
                )}

                {user.is_self && (
                    <Alert>
                        <AlertDescription>{t('users.self')}</AlertDescription>
                    </Alert>
                )}
                {!can.update && (
                    <Alert>
                        <AlertDescription>
                            {t('users.protected')}
                        </AlertDescription>
                    </Alert>
                )}

                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>{t('users.group')}</CardTitle>
                            <CardDescription>
                                {t('users.groupHelp')}
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form
                                className="space-y-3"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    groupForm.put(groupRoute.url(user.id), {
                                        preserveScroll: true,
                                    });
                                }}
                            >
                                <Select
                                    value={groupForm.data.group_id}
                                    onValueChange={(value) =>
                                        groupForm.setData('group_id', value)
                                    }
                                    disabled={!can.update}
                                >
                                    <SelectTrigger
                                        aria-label={t('users.group')}
                                        className="w-full"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {groups.map((group) => (
                                            <SelectItem
                                                key={group.id}
                                                value={String(group.id)}
                                            >
                                                {group.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <CheckboxField
                                    id="group-apply"
                                    label={t('users.applyToCurrentPeriod')}
                                    checked={
                                        groupForm.data.apply_to_current_period
                                    }
                                    onChange={(checked) =>
                                        groupForm.setData(
                                            'apply_to_current_period',
                                            checked,
                                        )
                                    }
                                />
                                <Button
                                    type="submit"
                                    size="sm"
                                    disabled={
                                        !can.update ||
                                        groupForm.processing ||
                                        groupForm.data.group_id ===
                                            String(user.group_id)
                                    }
                                >
                                    {t('users.changeGroup')}
                                </Button>
                            </form>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>{t('users.budgetTitle')}</CardTitle>
                            <CardDescription>
                                {t('users.budgetHelp', {
                                    limit: formatUsd(
                                        user.policy_limit_usd,
                                        lang,
                                    ),
                                })}
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form
                                className="space-y-3"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    budgetForm.put(budgetRoute.url(user.id), {
                                        preserveScroll: true,
                                    });
                                }}
                            >
                                <FormField
                                    id="monthly_limit_usd"
                                    label={t('users.monthlyLimit')}
                                    error={budgetForm.errors.monthly_limit_usd}
                                >
                                    <Input
                                        id="monthly_limit_usd"
                                        inputMode="decimal"
                                        className="w-40 tabular-nums"
                                        value={
                                            budgetForm.data.monthly_limit_usd
                                        }
                                        onChange={(event) =>
                                            budgetForm.setData(
                                                'monthly_limit_usd',
                                                event.target.value,
                                            )
                                        }
                                        disabled={!can.update}
                                    />
                                </FormField>
                                <CheckboxField
                                    id="budget-apply"
                                    label={t('users.applyToCurrentPeriod')}
                                    checked={
                                        budgetForm.data.apply_to_current_period
                                    }
                                    onChange={(checked) =>
                                        budgetForm.setData(
                                            'apply_to_current_period',
                                            checked,
                                        )
                                    }
                                />
                                <Button
                                    type="submit"
                                    size="sm"
                                    disabled={
                                        !can.update || budgetForm.processing
                                    }
                                >
                                    {t('users.saveBudget')}
                                </Button>
                            </form>
                        </CardContent>
                    </Card>

                    {can.changeStatus && (
                        <Card>
                            <CardHeader>
                                <CardTitle>{t('users.statusTitle')}</CardTitle>
                                <CardDescription>
                                    {user.status === 'active'
                                        ? t('users.disableHelp')
                                        : t('users.disabledHelp')}
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-2">
                                <Button
                                    type="button"
                                    size="sm"
                                    variant={
                                        user.status === 'active'
                                            ? 'destructive'
                                            : 'default'
                                    }
                                    disabled={statusForm.processing}
                                    onClick={() =>
                                        statusForm.put(
                                            statusRoute.url(user.id),
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    {user.status === 'active'
                                        ? t('users.disable')
                                        : t('users.enable')}
                                </Button>
                                {statusForm.errors.status && (
                                    <p className="text-sm text-destructive">
                                        {statusForm.errors.status}
                                    </p>
                                )}
                            </CardContent>
                        </Card>
                    )}

                    {can.changeRole && (
                        <Card>
                            <CardHeader>
                                <CardTitle>{t('users.roleTitle')}</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <form
                                    className="space-y-3"
                                    onSubmit={(event) => {
                                        event.preventDefault();
                                        roleForm.put(roleRoute.url(user.id), {
                                            preserveScroll: true,
                                        });
                                    }}
                                >
                                    <Select
                                        value={roleForm.data.role}
                                        onValueChange={(value) =>
                                            roleForm.setData(
                                                'role',
                                                value as Role,
                                            )
                                        }
                                    >
                                        <SelectTrigger
                                            aria-label={t('users.roleTitle')}
                                            className="w-full"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {(
                                                [
                                                    'user',
                                                    'admin',
                                                    'super_admin',
                                                ] as const
                                            ).map((role) => (
                                                <SelectItem
                                                    key={role}
                                                    value={role}
                                                >
                                                    {t(`roles.${role}`)}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {roleForm.errors.role && (
                                        <p className="text-sm text-destructive">
                                            {roleForm.errors.role}
                                        </p>
                                    )}
                                    <Button
                                        type="submit"
                                        size="sm"
                                        disabled={
                                            roleForm.processing ||
                                            roleForm.data.role === user.role
                                        }
                                    >
                                        {t('users.changeRole')}
                                    </Button>
                                </form>
                            </CardContent>
                        </Card>
                    )}

                    {can.adjustBudget && (
                        <Card className="lg:col-span-2">
                            <CardHeader>
                                <CardTitle>{t('users.adjustTitle')}</CardTitle>
                                <CardDescription>
                                    {t('users.adjustHelp')}
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <form
                                    className="grid gap-3 sm:grid-cols-[10rem_1fr_auto] sm:items-end"
                                    onSubmit={(event) => {
                                        event.preventDefault();
                                        adjustForm.post(
                                            adjustmentsRoute.url(user.id),
                                            {
                                                preserveScroll: true,
                                                onSuccess: () =>
                                                    adjustForm.reset(),
                                            },
                                        );
                                    }}
                                >
                                    <FormField
                                        id="amount_usd"
                                        label={t('users.amount')}
                                        error={adjustForm.errors.amount_usd}
                                    >
                                        <Input
                                            id="amount_usd"
                                            inputMode="decimal"
                                            className="tabular-nums"
                                            value={adjustForm.data.amount_usd}
                                            onChange={(event) =>
                                                adjustForm.setData(
                                                    'amount_usd',
                                                    event.target.value,
                                                )
                                            }
                                            required
                                        />
                                    </FormField>
                                    <FormField
                                        id="reason"
                                        label={t('users.reason')}
                                        error={adjustForm.errors.reason}
                                    >
                                        <Input
                                            id="reason"
                                            value={adjustForm.data.reason}
                                            maxLength={255}
                                            onChange={(event) =>
                                                adjustForm.setData(
                                                    'reason',
                                                    event.target.value,
                                                )
                                            }
                                            required
                                        />
                                    </FormField>
                                    <Button
                                        type="submit"
                                        disabled={adjustForm.processing}
                                    >
                                        {t('users.adjust')}
                                    </Button>
                                </form>

                                {adjustments.length > 0 && (
                                    <div className="space-y-2">
                                        <h3 className="text-sm font-medium">
                                            {t('users.adjustments')}
                                        </h3>
                                        <ul className="divide-y text-sm">
                                            {adjustments.map((adjustment) => (
                                                <li
                                                    key={adjustment.id}
                                                    className="flex flex-wrap justify-between gap-2 py-2"
                                                >
                                                    <span>
                                                        {adjustment.reason}
                                                        <span className="block text-xs text-muted-foreground">
                                                            {dateTime(
                                                                adjustment.created_at,
                                                            )}
                                                            {adjustment.by &&
                                                                ` · ${t('users.by', { name: adjustment.by })}`}
                                                        </span>
                                                    </span>
                                                    <span className="tabular-nums">
                                                        {formatUsd(
                                                            adjustment.amount_usd,
                                                            lang,
                                                        )}
                                                    </span>
                                                </li>
                                            ))}
                                        </ul>
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    )}
                </div>

                <section className="space-y-4">
                    <h2 className="text-lg font-semibold">
                        {t('users.usage')}
                    </h2>
                    <UsageDetails {...usage} />
                </section>
            </div>
        </>
    );
}

UserShow.layout = {
    breadcrumbs: [{ titleKey: 'admin:users.title', href: index() }],
};
