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
import { create, edit, index } from '@/routes/admin/models';

type ModelRow = {
    id: number;
    provider: string;
    provider_model_id: string;
    display_name: string;
    input_price_per_million: string;
    output_price_per_million: string;
    context_window: number;
    enabled: boolean;
};

/** Prices arrive as decimal strings; format for display only. */
const price = (value: string) =>
    `$${Number(value).toLocaleString(undefined, { maximumFractionDigits: 6 })}`;

export default function ModelsIndex({ models }: { models: ModelRow[] }) {
    const { t } = useTranslation('admin');

    return (
        <>
            <Head title={t('models.title')} />

            <div className="space-y-6">
                <div className="flex items-start justify-between gap-4">
                    <Heading
                        variant="small"
                        title={t('models.title')}
                        description={t('models.description')}
                    />
                    <Button asChild size="sm">
                        <Link href={create()}>{t('models.add')}</Link>
                    </Button>
                </div>

                {models.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('common.empty')}
                    </p>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>{t('models.displayName')}</TableHead>
                                <TableHead>{t('models.provider')}</TableHead>
                                <TableHead className="text-right">
                                    {t('models.inputPrice')}
                                </TableHead>
                                <TableHead className="text-right">
                                    {t('models.outputPrice')}
                                </TableHead>
                                <TableHead>{t('models.enabled')}</TableHead>
                                <TableHead className="sr-only">
                                    {t('common.actions')}
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {models.map((model) => (
                                <TableRow key={model.id}>
                                    <TableCell className="font-medium">
                                        {model.display_name}
                                        <div className="font-mono text-xs text-muted-foreground">
                                            {model.provider_model_id}
                                        </div>
                                    </TableCell>
                                    <TableCell>{model.provider}</TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {price(model.input_price_per_million)}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {price(model.output_price_per_million)}
                                    </TableCell>
                                    <TableCell>
                                        <Badge
                                            variant={
                                                model.enabled
                                                    ? 'secondary'
                                                    : 'outline'
                                            }
                                        >
                                            {model.enabled
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
                                            <Link href={edit(model.id)}>
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

ModelsIndex.layout = {
    breadcrumbs: [{ titleKey: 'admin:models.title', href: index() }],
};
