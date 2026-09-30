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
import { create, edit, index } from '@/routes/admin/providers';

type ProviderRow = {
    id: number;
    slug: string;
    name: string;
    driver: string;
    enabled: boolean;
    models_count: number;
    masked_key: string | null;
    uses_env_key: boolean;
};

export default function ProvidersIndex({
    providers,
}: {
    providers: ProviderRow[];
}) {
    const { t } = useTranslation('admin');

    return (
        <>
            <Head title={t('providers.title')} />

            <div className="space-y-6">
                <div className="flex items-start justify-between gap-4">
                    <Heading
                        variant="small"
                        title={t('providers.title')}
                        description={t('providers.description')}
                    />
                    <Button asChild size="sm">
                        <Link href={create()}>{t('providers.add')}</Link>
                    </Button>
                </div>

                {providers.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('common.empty')}
                    </p>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>{t('providers.name')}</TableHead>
                                <TableHead>{t('providers.driver')}</TableHead>
                                <TableHead>{t('providers.apiKey')}</TableHead>
                                <TableHead>{t('providers.models')}</TableHead>
                                <TableHead>{t('providers.enabled')}</TableHead>
                                <TableHead className="sr-only">
                                    {t('common.actions')}
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {providers.map((provider) => (
                                <TableRow key={provider.id}>
                                    <TableCell className="font-medium">
                                        {provider.name}
                                        <div className="text-xs text-muted-foreground">
                                            {provider.slug}
                                        </div>
                                    </TableCell>
                                    <TableCell>{provider.driver}</TableCell>
                                    <TableCell className="font-mono text-xs">
                                        {provider.masked_key ??
                                            (provider.uses_env_key
                                                ? t('providers.apiKeyEnv')
                                                : t('providers.apiKeyNone'))}
                                    </TableCell>
                                    <TableCell>
                                        {provider.models_count}
                                    </TableCell>
                                    <TableCell>
                                        <Badge
                                            variant={
                                                provider.enabled
                                                    ? 'secondary'
                                                    : 'outline'
                                            }
                                        >
                                            {provider.enabled
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
                                            <Link href={edit(provider.id)}>
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

ProvidersIndex.layout = {
    breadcrumbs: [{ titleKey: 'admin:providers.title', href: index() }],
};
