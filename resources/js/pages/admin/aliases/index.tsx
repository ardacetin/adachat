import { Head, Link } from '@inertiajs/react';
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
import { create, edit, index } from '@/routes/admin/aliases';

type AliasRow = {
    id: number;
    slug: string;
    name: string;
    model: string;
    max_output_tokens: number;
    enabled: boolean;
};

export default function AliasesIndex({ aliases }: { aliases: AliasRow[] }) {
    const { t } = useTranslation('admin');

    return (
        <>
            <Head title={t('aliases.title')} />

            <div className="space-y-6">
                <div className="flex items-start justify-between gap-4">
                    <Heading
                        variant="small"
                        title={t('aliases.title')}
                        description={t('aliases.description')}
                    />
                    <Button asChild size="sm">
                        <Link href={create()}>{t('aliases.add')}</Link>
                    </Button>
                </div>

                {aliases.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('common.empty')}
                    </p>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>{t('aliases.slug')}</TableHead>
                                <TableHead>{t('aliases.model')}</TableHead>
                                <TableHead className="text-right">
                                    {t('aliases.maxOutputTokens')}
                                </TableHead>
                                <TableHead>{t('aliases.enabled')}</TableHead>
                                <TableHead className="sr-only">
                                    {t('common.actions')}
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {aliases.map((alias) => (
                                <TableRow key={alias.id}>
                                    <TableCell className="font-medium">
                                        {alias.name}
                                        <div className="font-mono text-xs text-muted-foreground">
                                            {alias.slug}
                                        </div>
                                    </TableCell>
                                    <TableCell>{alias.model}</TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {alias.max_output_tokens.toLocaleString()}
                                    </TableCell>
                                    <TableCell>
                                        <Badge
                                            variant={
                                                alias.enabled
                                                    ? 'secondary'
                                                    : 'outline'
                                            }
                                        >
                                            {alias.enabled
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
                                            <Link href={edit(alias.id)}>
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

AliasesIndex.layout = {
    breadcrumbs: [{ titleKey: 'admin:aliases.title', href: index() }],
};
