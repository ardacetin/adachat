import { Head, router, usePage } from '@inertiajs/react';
import { ThumbsDown, ThumbsUp } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { formatNumber } from '@/lib/format';
import { index } from '@/routes/admin/feedback';

type Per = 'alias' | 'model' | 'assistant';

type Reason = 'inaccurate' | 'unhelpful' | 'incomplete' | 'too_long' | 'other';

type Row = {
    id: number | null;
    label: string;
    up: number;
    down: number;
    rate: number | null;
    reasons: Partial<Record<Reason, number>>;
};

type Props = {
    filters: { from: string; to: string; per: Per };
    totals: {
        up: number;
        down: number;
        reasons: Partial<Record<Reason, number>>;
    };
    rows: Row[];
    reasons: Reason[];
    minVotes: number;
};

export default function Feedback({
    filters,
    totals,
    rows,
    reasons,
    minVotes,
}: Props) {
    const { t } = useTranslation('admin');
    const { locale, errors } = usePage().props;
    const lang = locale.current;
    const [range, setRange] = useState({ from: filters.from, to: filters.to });
    const votes = totals.up + totals.down;

    const visit = (changes: Partial<Props['filters']>) =>
        router.get(
            index.url(),
            { ...filters, ...changes },
            { preserveScroll: true, preserveState: true },
        );

    const topReasons = (row: Row) =>
        (Object.entries(row.reasons) as [Reason, number][])
            .sort(([, a], [, b]) => b - a)
            .slice(0, 2)
            .map(([reason, count]) =>
                t('feedback.reasonCount', {
                    reason: t(`feedback.reasons.${reason}`),
                    count,
                }),
            )
            .join(', ');

    return (
        <>
            <Head title={t('feedback.title')} />

            <div className="space-y-6">
                <Heading
                    title={t('feedback.title')}
                    description={t('feedback.description')}
                />

                <form
                    className="grid grid-cols-[1fr_1fr_auto] items-end gap-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        visit(range);
                    }}
                >
                    <div className="grid gap-1">
                        <Label htmlFor="from">{t('reports.from')}</Label>
                        <Input
                            id="from"
                            type="date"
                            value={range.from}
                            onChange={(event) =>
                                setRange({ ...range, from: event.target.value })
                            }
                            required
                        />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="to">{t('reports.to')}</Label>
                        <Input
                            id="to"
                            type="date"
                            value={range.to}
                            onChange={(event) =>
                                setRange({ ...range, to: event.target.value })
                            }
                            required
                        />
                    </div>
                    <Button type="submit" variant="outline">
                        {t('reports.apply')}
                    </Button>
                </form>
                <InputError message={errors.from ?? errors.to} />

                <div className="grid gap-4 sm:grid-cols-3">
                    <Card>
                        <CardHeader>
                            <CardDescription>
                                {t('feedback.votes')}
                            </CardDescription>
                            <CardTitle
                                className="text-2xl tabular-nums"
                                data-test="feedback-votes"
                            >
                                {formatNumber(votes, lang)}
                            </CardTitle>
                        </CardHeader>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardDescription>
                                {t('feedback.satisfaction')}
                            </CardDescription>
                            <CardTitle className="text-2xl tabular-nums">
                                {votes >= minVotes
                                    ? `${Math.round((totals.up * 100) / votes)} %`
                                    : t('feedback.tooFew')}
                            </CardTitle>
                        </CardHeader>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardDescription>
                                {t('feedback.reasonsTitle')}
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <ul className="space-y-1 text-sm">
                                {reasons.map((reason) => (
                                    <li
                                        key={reason}
                                        className="flex justify-between gap-2"
                                    >
                                        <span>
                                            {t(`feedback.reasons.${reason}`)}
                                        </span>
                                        <span className="text-muted-foreground tabular-nums">
                                            {formatNumber(
                                                totals.reasons[reason] ?? 0,
                                                lang,
                                            )}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader className="flex-row items-center justify-between gap-4">
                        <CardTitle>{t('feedback.byTitle')}</CardTitle>
                        <ToggleGroup
                            type="single"
                            size="sm"
                            variant="outline"
                            value={filters.per}
                            onValueChange={(value) =>
                                value && visit({ per: value as Per })
                            }
                        >
                            {(['alias', 'model', 'assistant'] as const).map(
                                (per) => (
                                    <ToggleGroupItem key={per} value={per}>
                                        {t(`feedback.per.${per}`)}
                                    </ToggleGroupItem>
                                ),
                            )}
                        </ToggleGroup>
                    </CardHeader>
                    <CardContent>
                        {rows.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                {t('feedback.none')}
                            </p>
                        ) : (
                            <Table data-test="feedback-rows">
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>
                                            {t('reports.name')}
                                        </TableHead>
                                        <TableHead className="text-right">
                                            <ThumbsUp
                                                className="ml-auto size-4"
                                                aria-label={t('feedback.up')}
                                            />
                                        </TableHead>
                                        <TableHead className="text-right">
                                            <ThumbsDown
                                                className="ml-auto size-4"
                                                aria-label={t('feedback.down')}
                                            />
                                        </TableHead>
                                        <TableHead className="text-right">
                                            {t('feedback.satisfaction')}
                                        </TableHead>
                                        <TableHead className="hidden md:table-cell">
                                            {t('feedback.topReasons')}
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {rows.map((row) => (
                                        <TableRow key={row.id ?? row.label}>
                                            <TableCell className="font-medium">
                                                {row.label}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {formatNumber(row.up, lang)}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {formatNumber(row.down, lang)}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {row.rate === null
                                                    ? t('feedback.tooFew')
                                                    : `${row.rate} %`}
                                            </TableCell>
                                            <TableCell className="hidden text-sm text-muted-foreground md:table-cell">
                                                {topReasons(row) || '—'}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                        <p className="mt-3 text-xs text-muted-foreground">
                            {t('feedback.privacy', { count: minVotes })}
                        </p>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

Feedback.layout = {
    breadcrumbs: [{ titleKey: 'admin:feedback.title', href: index() }],
};
