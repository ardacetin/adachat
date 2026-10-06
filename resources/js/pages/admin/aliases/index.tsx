import { Head, Link, useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
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
import {
    create,
    defaultMethod as setDefault,
    edit,
    index,
} from '@/routes/admin/aliases';

type AliasRow = {
    id: number;
    slug: string;
    name: string;
    model: string;
    max_output_tokens: number;
    enabled: boolean;
};

const NONE = 'none';

export default function AliasesIndex({
    aliases,
    defaultAliasId,
}: {
    aliases: AliasRow[];
    defaultAliasId: number | null;
}) {
    const { t } = useTranslation('admin');
    const form = useForm<{ default_model_alias_id: number | null }>({
        default_model_alias_id: defaultAliasId,
    });

    const saveDefault = (event: React.FormEvent) => {
        event.preventDefault();
        form.put(setDefault.url(), { preserveScroll: true });
    };

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

                {aliases.some((alias) => alias.enabled) && (
                    <form
                        onSubmit={saveDefault}
                        className="space-y-2 rounded-lg border p-4"
                    >
                        <Label htmlFor="default_model_alias_id">
                            {t('aliases.defaultTitle')}
                        </Label>
                        <p className="text-sm text-muted-foreground">
                            {t('aliases.defaultHelp')}
                        </p>
                        <div className="flex flex-wrap items-center gap-2">
                            <Select
                                value={String(
                                    form.data.default_model_alias_id ?? NONE,
                                )}
                                onValueChange={(value) =>
                                    form.setData(
                                        'default_model_alias_id',
                                        value === NONE ? null : Number(value),
                                    )
                                }
                            >
                                <SelectTrigger
                                    id="default_model_alias_id"
                                    className="w-full sm:w-80"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE}>
                                        {t('aliases.defaultNone')}
                                    </SelectItem>
                                    {aliases
                                        .filter((alias) => alias.enabled)
                                        .map((alias) => (
                                            <SelectItem
                                                key={alias.id}
                                                value={String(alias.id)}
                                            >
                                                {alias.name}
                                            </SelectItem>
                                        ))}
                                </SelectContent>
                            </Select>
                            <Button
                                type="submit"
                                size="sm"
                                disabled={form.processing || !form.isDirty}
                            >
                                {t('aliases.defaultSave')}
                            </Button>
                        </div>
                        {form.errors.default_model_alias_id && (
                            <p className="text-sm text-destructive">
                                {form.errors.default_model_alias_id}
                            </p>
                        )}
                    </form>
                )}

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
                                        {alias.id === defaultAliasId && (
                                            <Badge className="ml-2">
                                                {t('aliases.defaultBadge')}
                                            </Badge>
                                        )}
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
