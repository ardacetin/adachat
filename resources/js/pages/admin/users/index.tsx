import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import AddUsersDialog from '@/components/admin/add-users-dialog';
import UserRowActions from '@/components/admin/user-row-actions';
import Pagination from '@/components/admin/pagination';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatUsd } from '@/lib/format';
import { index, show } from '@/routes/admin/users';
import * as authentication from '@/routes/admin/authentication';
import type { Paginated } from '@/types/pagination';

type Role = 'super_admin' | 'admin' | 'user';
type Status = 'active' | 'disabled';

type UserRow = {
    id: number;
    name: string;
    email: string;
    group: string;
    role: Role;
    status: Status;
    has_override: boolean;
    limit_usd: string;
    spent_usd: string;
    remaining_usd: string;
    last_active_at: string | null;
    invitation_pending: boolean;
    can: { changeStatus: boolean; changeRole: boolean };
};

type Filters = {
    q: string;
    group_id: number | null;
    role: Role | null;
    status: Status | null;
    sort: 'name' | 'last_active' | 'spent';
};

type Props = {
    users: Paginated<UserRow>;
    filters: Filters;
    groups: { id: number; name: string }[];
    access: { auto_provision: boolean; allowed_domains: string[] };
};

const ALL = 'all';

export default function UsersIndex({ users, filters, groups, access }: Props) {
    const { t } = useTranslation('admin');
    const { locale } = usePage().props;
    const [search, setSearch] = useState(filters.q);

    const apply = (changes: Partial<Filters>) => {
        const next = { ...filters, q: search, ...changes };

        router.get(
            index.url(),
            Object.fromEntries(
                Object.entries(next).filter(
                    ([key, value]) =>
                        value !== null &&
                        value !== '' &&
                        !(key === 'sort' && value === 'name'),
                ),
            ),
            { preserveState: true, replace: true },
        );
    };

    const lastActive = (value: string | null) =>
        value === null
            ? t('common.never')
            : new Intl.DateTimeFormat(locale.current, {
                  dateStyle: 'medium',
                  timeStyle: 'short',
              }).format(new Date(value));

    const choice = (value: string | number | null) =>
        value === null ? ALL : String(value);

    return (
        <>
            <Head title={t('users.title')} />

            <div className="space-y-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        variant="small"
                        title={t('users.title')}
                        description={t('users.description')}
                    />
                    <AddUsersDialog groups={groups} />
                </div>

                <p
                    className="rounded-lg border p-3 text-sm text-muted-foreground"
                    data-test="sign-in-access"
                >
                    {access.auto_provision
                        ? t('users.accessDomains', {
                              domains: access.allowed_domains.join(', ') || '—',
                          })
                        : t('users.accessListed')}{' '}
                    <Link
                        href={authentication.edit()}
                        className="underline underline-offset-4"
                    >
                        {t('users.accessChange')}
                    </Link>
                </p>

                <form
                    className="flex flex-wrap gap-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        apply({});
                    }}
                >
                    <Input
                        className="min-w-56 flex-1"
                        type="search"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        placeholder={t('users.searchPlaceholder')}
                        aria-label={t('common.search')}
                    />
                    <Select
                        value={choice(filters.group_id)}
                        onValueChange={(value) =>
                            apply({
                                group_id: value === ALL ? null : Number(value),
                            })
                        }
                    >
                        <SelectTrigger aria-label={t('users.group')}>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>
                                {t('users.group')}: {t('common.all')}
                            </SelectItem>
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
                    <Select
                        value={choice(filters.role)}
                        onValueChange={(value) =>
                            apply({
                                role: value === ALL ? null : (value as Role),
                            })
                        }
                    >
                        <SelectTrigger aria-label={t('users.role')}>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>
                                {t('users.role')}: {t('common.all')}
                            </SelectItem>
                            {(['user', 'admin', 'super_admin'] as const).map(
                                (role) => (
                                    <SelectItem key={role} value={role}>
                                        {t(`roles.${role}`)}
                                    </SelectItem>
                                ),
                            )}
                        </SelectContent>
                    </Select>
                    <Select
                        value={choice(filters.status)}
                        onValueChange={(value) =>
                            apply({
                                status:
                                    value === ALL ? null : (value as Status),
                            })
                        }
                    >
                        <SelectTrigger aria-label={t('users.status')}>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>
                                {t('users.status')}: {t('common.all')}
                            </SelectItem>
                            {(['active', 'disabled'] as const).map((status) => (
                                <SelectItem key={status} value={status}>
                                    {t(`statuses.${status}`)}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Select
                        value={filters.sort}
                        onValueChange={(value) =>
                            apply({ sort: value as Filters['sort'] })
                        }
                    >
                        <SelectTrigger aria-label={t('users.sort')}>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="name">
                                {t('users.sortName')}
                            </SelectItem>
                            <SelectItem value="last_active">
                                {t('users.sortLastActive')}
                            </SelectItem>
                            <SelectItem value="spent">
                                {t('users.sortSpent')}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <Button type="submit" className="sr-only">
                        {t('common.search')}
                    </Button>
                </form>

                <p className="text-sm text-muted-foreground">
                    {t('users.count', { count: users.total })}
                </p>

                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>{t('users.name')}</TableHead>
                            <TableHead className="hidden lg:table-cell">
                                {t('users.group')}
                            </TableHead>
                            <TableHead className="text-right">
                                {t('users.budget')}
                            </TableHead>
                            <TableHead className="hidden xl:table-cell">
                                {t('users.lastActive')}
                            </TableHead>
                            <TableHead className="w-10">
                                <span className="sr-only">
                                    {t('common.actions')}
                                </span>
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {users.data.map((user) => (
                            <TableRow key={user.id}>
                                <TableCell>
                                    <Link
                                        href={show(user.id)}
                                        className="font-medium underline-offset-4 hover:underline"
                                    >
                                        {user.name}
                                    </Link>
                                    <div className="text-xs text-muted-foreground">
                                        {user.email}
                                    </div>
                                    <div className="mt-1 flex flex-wrap gap-1">
                                        {user.role !== 'user' && (
                                            <Badge variant="secondary">
                                                {t(`roles.${user.role}`)}
                                            </Badge>
                                        )}
                                        {user.invitation_pending && (
                                            <Badge variant="outline">
                                                {t('users.invited')}
                                            </Badge>
                                        )}
                                        {user.status === 'disabled' && (
                                            <Badge variant="destructive">
                                                {t('statuses.disabled')}
                                            </Badge>
                                        )}
                                    </div>
                                </TableCell>
                                <TableCell className="hidden lg:table-cell">
                                    {user.group}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {formatUsd(user.spent_usd, locale.current)}{' '}
                                    /{' '}
                                    {formatUsd(user.limit_usd, locale.current)}
                                    <div className="text-xs text-muted-foreground">
                                        {user.has_override &&
                                            `${t('users.individual')} · `}
                                        {t('users.remaining')}{' '}
                                        {formatUsd(
                                            user.remaining_usd,
                                            locale.current,
                                        )}
                                    </div>
                                </TableCell>
                                <TableCell className="hidden text-sm xl:table-cell">
                                    {lastActive(user.last_active_at)}
                                </TableCell>
                                <TableCell className="text-right">
                                    <UserRowActions user={user} />
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>

                <Pagination page={users} />
            </div>
        </>
    );
}

UsersIndex.layout = {
    breadcrumbs: [{ titleKey: 'admin:users.title', href: index() }],
};
