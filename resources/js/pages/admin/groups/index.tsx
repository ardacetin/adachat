import { Head, Link, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
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
import { create, edit, index } from '@/routes/admin/groups';

type GroupRow = {
    id: number;
    name: string;
    is_default: boolean;
    policy: string;
    monthly_limit_usd: string;
    requests_per_minute: number;
    max_concurrent_streams: number;
    users_count: number;
    aliases_count: number;
    idp_groups_count: number;
};

export default function GroupsIndex({ groups }: { groups: GroupRow[] }) {
    const { t } = useTranslation('admin');
    const { locale } = usePage().props;

    return (
        <>
            <Head title={t('groups.title')} />

            <div className="space-y-6">
                <div className="flex items-start justify-between gap-4">
                    <Heading
                        variant="small"
                        title={t('groups.title')}
                        description={t('groups.description')}
                    />
                    <Button asChild size="sm">
                        <Link href={create()}>{t('groups.add')}</Link>
                    </Button>
                </div>

                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>{t('groups.name')}</TableHead>
                            <TableHead>{t('groups.policy')}</TableHead>
                            <TableHead className="hidden text-right xl:table-cell">
                                {t('groups.requestsPerMinute')}
                            </TableHead>
                            <TableHead className="hidden text-right xl:table-cell">
                                {t('groups.maxConcurrentStreams')}
                            </TableHead>
                            <TableHead className="text-right">
                                {t('groups.aliases')}
                            </TableHead>
                            <TableHead className="text-right">
                                {t('groups.members')}
                            </TableHead>
                            <TableHead className="sr-only">
                                {t('common.actions')}
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {groups.map((group) => (
                            <TableRow key={group.id}>
                                <TableCell className="font-medium">
                                    {group.name}{' '}
                                    {group.is_default && (
                                        <Badge variant="secondary">
                                            {t('groups.default')}
                                        </Badge>
                                    )}{' '}
                                    {group.idp_groups_count > 0 && (
                                        <Badge variant="outline">
                                            {t('groups.mapped')}
                                        </Badge>
                                    )}
                                </TableCell>
                                <TableCell>
                                    {group.policy}
                                    <div className="text-xs text-muted-foreground tabular-nums">
                                        {formatUsd(
                                            group.monthly_limit_usd,
                                            locale.current,
                                        )}
                                    </div>
                                </TableCell>
                                <TableCell className="hidden text-right tabular-nums xl:table-cell">
                                    {group.requests_per_minute}
                                </TableCell>
                                <TableCell className="hidden text-right tabular-nums xl:table-cell">
                                    {group.max_concurrent_streams}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {group.aliases_count}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {group.users_count}
                                </TableCell>
                                <TableCell className="text-right">
                                    <Button asChild variant="ghost" size="sm">
                                        <Link href={edit(group.id)}>
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

GroupsIndex.layout = {
    breadcrumbs: [{ titleKey: 'admin:groups.title', href: index() }],
};
