import { usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import BudgetMeter from '@/components/budget/budget-meter';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDate, formatMonth, formatNumber, formatUsd } from '@/lib/format';
import type {
    BudgetSummary,
    UsageByDay,
    UsageByModel,
    UsageMonth,
} from '@/types/budget';

export type UsageData = {
    summary: BudgetSummary;
    by_model: UsageByModel[];
    by_day: UsageByDay[];
    months: UsageMonth[];
};

/** This month's budget, usage by model and by day, and previous months. */
export default function UsageDetails({
    summary,
    by_model,
    by_day,
    months,
}: UsageData) {
    const { t } = useTranslation('chat');
    const { locale } = usePage().props;
    const lang = locale.current;
    const amounts = summary.display === 'amount';

    const cost = (row: { cost_usd?: string; percent_of_limit?: number }) =>
        amounts
            ? formatUsd(row.cost_usd ?? '0', lang)
            : `${row.percent_of_limit ?? 0}%`;
    const costLabel = amounts ? t('usage.cost') : t('usage.share');

    return (
        <div className="space-y-8">
            <Card>
                <CardHeader>
                    <CardTitle>{t('usage.thisMonth')}</CardTitle>
                    <CardDescription>
                        {t('budget.renews', {
                            date: formatDate(summary.resets_on, lang),
                        })}
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-4">
                    <div className="flex items-baseline justify-between gap-4">
                        <span className="text-3xl font-semibold tabular-nums">
                            {t('budget.used', {
                                percent: summary.percent_used,
                            })}
                        </span>
                    </div>
                    <BudgetMeter
                        percent={summary.percent_used}
                        label={t('budget.title')}
                        className="h-2"
                    />
                    {amounts && (
                        <dl className="grid grid-cols-3 gap-4 text-sm">
                            {(
                                [
                                    ['spent', summary.spent_usd],
                                    ['remaining', summary.remaining_usd],
                                    ['limit', summary.limit_usd],
                                ] as const
                            ).map(([key, value]) => (
                                <div key={key}>
                                    <dt className="text-muted-foreground">
                                        {t(`usage.${key}`)}
                                    </dt>
                                    <dd className="font-medium tabular-nums">
                                        {formatUsd(value ?? '0', lang)}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    )}
                </CardContent>
            </Card>

            <section className="space-y-3">
                <h2 className="text-lg font-semibold">{t('usage.byModel')}</h2>
                {by_model.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('usage.empty')}
                    </p>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>{t('usage.model')}</TableHead>
                                <TableHead className="text-right">
                                    {t('usage.requests')}
                                </TableHead>
                                <TableHead className="hidden text-right sm:table-cell">
                                    {t('usage.inputTokens')}
                                </TableHead>
                                <TableHead className="hidden text-right sm:table-cell">
                                    {t('usage.outputTokens')}
                                </TableHead>
                                <TableHead className="text-right">
                                    {costLabel}
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {by_model.map((row, index) => (
                                <TableRow key={`${row.alias}-${index}`}>
                                    <TableCell className="font-medium">
                                        {row.alias ?? t('usage.otherModel')}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {formatNumber(row.requests, lang)}
                                    </TableCell>
                                    <TableCell className="hidden text-right tabular-nums sm:table-cell">
                                        {formatNumber(row.input_tokens, lang)}
                                    </TableCell>
                                    <TableCell className="hidden text-right tabular-nums sm:table-cell">
                                        {formatNumber(row.output_tokens, lang)}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {cost(row)}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </section>

            {by_day.length > 0 && (
                <section className="space-y-3">
                    <h2 className="text-lg font-semibold">
                        {t('usage.byDay')}
                    </h2>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>{t('usage.date')}</TableHead>
                                <TableHead className="text-right">
                                    {t('usage.requests')}
                                </TableHead>
                                <TableHead className="text-right">
                                    {costLabel}
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {[...by_day].reverse().map((row) => (
                                <TableRow key={row.date}>
                                    <TableCell>
                                        {formatDate(row.date, lang)}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {formatNumber(row.requests, lang)}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {cost(row)}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </section>
            )}

            {months.length > 0 && (
                <section className="space-y-3">
                    <h2 className="text-lg font-semibold">
                        {t('usage.previousMonths')}
                    </h2>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>{t('usage.month')}</TableHead>
                                <TableHead className="text-right">
                                    {t('usage.used')}
                                </TableHead>
                                {amounts && (
                                    <TableHead className="text-right">
                                        {t('usage.spent')}
                                    </TableHead>
                                )}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {months.map((row) => (
                                <TableRow key={row.month}>
                                    <TableCell>
                                        {formatMonth(row.month, lang)}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {row.percent_used}%
                                    </TableCell>
                                    {amounts && (
                                        <TableCell className="text-right tabular-nums">
                                            {formatUsd(
                                                row.spent_usd ?? '0',
                                                lang,
                                            )}{' '}
                                            /{' '}
                                            {formatUsd(
                                                row.limit_usd ?? '0',
                                                lang,
                                            )}
                                        </TableCell>
                                    )}
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </section>
            )}
        </div>
    );
}
