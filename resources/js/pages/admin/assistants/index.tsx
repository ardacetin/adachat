import { Head, Link } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import AssistantIcon from '@/components/chat/assistant-icon';
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
import { create, edit, index } from '@/routes/admin/assistants';

type AssistantRow = {
    id: number;
    slug: string;
    name: string;
    icon: string;
    alias: string;
    groups_count: number;
    enabled: boolean;
};

export default function AssistantsIndex({
    assistants,
}: {
    assistants: AssistantRow[];
}) {
    const { t } = useTranslation('admin');

    return (
        <>
            <Head title={t('assistants.title')} />

            <div className="space-y-6">
                <div className="flex items-start justify-between gap-4">
                    <Heading
                        variant="small"
                        title={t('assistants.title')}
                        description={t('assistants.description')}
                    />
                    <Button asChild size="sm">
                        <Link href={create()}>{t('assistants.add')}</Link>
                    </Button>
                </div>

                {assistants.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('common.empty')}
                    </p>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>{t('assistants.name')}</TableHead>
                                <TableHead>{t('assistants.alias')}</TableHead>
                                <TableHead className="text-right">
                                    {t('assistants.groups')}
                                </TableHead>
                                <TableHead>{t('assistants.enabled')}</TableHead>
                                <TableHead className="sr-only">
                                    {t('common.actions')}
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {assistants.map((assistant) => (
                                <TableRow key={assistant.id}>
                                    <TableCell className="font-medium">
                                        <span className="flex items-center gap-2">
                                            <AssistantIcon
                                                name={assistant.icon}
                                                className="size-4 text-muted-foreground"
                                            />
                                            {assistant.name}
                                        </span>
                                        <div className="font-mono text-xs text-muted-foreground">
                                            {assistant.slug}
                                        </div>
                                    </TableCell>
                                    <TableCell>{assistant.alias}</TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {assistant.groups_count}
                                    </TableCell>
                                    <TableCell>
                                        <Badge
                                            variant={
                                                assistant.enabled
                                                    ? 'secondary'
                                                    : 'outline'
                                            }
                                        >
                                            {assistant.enabled
                                                ? t('common.enabled')
                                                : t('common.disabled')}
                                        </Badge>
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <Button
                                            asChild
                                            variant="ghost"
                                            size="sm"
                                        >
                                            <Link href={edit(assistant.id)}>
                                                {t('common.edit')}
                                            </Link>
                                        </Button>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </div>
        </>
    );
}

AssistantsIndex.layout = {
    breadcrumbs: [{ titleKey: 'admin:assistants.title', href: index() }],
};
