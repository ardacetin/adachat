import { Head, router, usePage } from '@inertiajs/react';
import { Download } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import BarChart, { ShareBar } from '@/components/admin/bar-chart';
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
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
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
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { formatDate, formatMonth, formatNumber, formatUsd } from '@/lib/format';
import { exportMethod, index } from '@/routes/admin/reports';
import type {
    BreakdownRow,
    DeviationRow,
    Overshoots,
    TimelinePoint,
    Totals,
} from '@/types/reports';

type Dimension = 'model' | 'group' | 'provider' | 'user';

type Filters = {
    from: string;
    to: string;
    user_id: number | null;
    group_id: number | null;
    provider_id: number | null;
    ai_model_id: number | null;
    by: Dimension;
    interval: 'day' | 'month';
};

type Option = { id: number; name: string };

type Props = {
    filters: Filters;
    totals: Totals;
    breakdown: BreakdownRow[];
    timeline: TimelinePoint[];
    overshoots: Overshoots;
    deviation: DeviationRow[];
    options: {
        groups: Option[];
        providers: Option[];
        models: { id: number; display_name: string }[];
        user: { id: number; name: string; email: string } | null;
    };
    topRows: number;
};

const ALL = 'all';
const DIMENSIONS: Dimension[] = ['model', 'group', 'provider', 'user'];

export default function Reports({
    filters,
    totals,
    breakdown,
    timeline,
    overshoots,
    deviation,
    options,
    topRows,
}: Props) {
    const { t } = useTranslation('admin');
    const { locale, errors } = usePage<{
        errors: Record<string, string | undefined>;
    }>().props;
    const lang = locale.current;
    const [range, setRange] = useState({ from: filters.from, to: filters.to });

    const visit = (changes: Partial<Filters>) =>
        router.get(
            index.url(),
            Object.fromEntries(
                Object.entries({ ...filters, ...changes }).filter(
                    ([, value]) => value !== null && value !== '',
                ),
            ),
            { preserveState: true, preserveScroll: true, replace: true },
        );

    // The CSV export takes the same filters as the page.
    const exportUrl = (dataset: 'breakdown' | 'timeline') =>
        exportMethod.url({
            query: {
                ...Object.fromEntries(
                    Object.entries(filters).filter(
                        ([, value]) => value !== null && value !== '',
                    ),
                ),
                dataset,
            },
        });

    const pickId = (value: string) => (value === ALL ? null : Number(value));
    const total = Number(totals.cost_usd);
    const pointLabel = (date: string) =>
        filters.interval === 'month'
            ? formatMonth(date, lang)
            : formatDate(date, lang);

    return (
        <>
            <Head title={t('reports.title')} />

            <div className="space-y-6">
                <div className="flex items-start justify-between gap-4">
                    <Heading
                        variant="small"
                        title={t('reports.title')}
                        description={t('reports.description')}
                    />
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button variant="outline" size="sm">
                                <Download aria-hidden />
                                {t('reports.download')}
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuItem asChild>
                                <a href={exportUrl('breakdown')} download>
                                    {t(`reports.download_${filters.by}`)}
                                </a>
                            </DropdownMenuItem>
                            <DropdownMenuItem asChild>
                                <a href={exportUrl('timeline')} download>
                                    {t(
                                        filters.interval === 'month'
                                            ? 'reports.downloadMonthly'
                                            : 'reports.downloadDaily',
                                    )}
                                </a>
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>

                <form
                    className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        visit(range);
                    }}
                >
                    <div className="grid grid-cols-[1fr_1fr_auto] items-end gap-2 sm:col-span-2 lg:col-span-3">
                        <div className="grid gap-1">
                            <Label htmlFor="from">{t('reports.from')}</Label>
                            <Input
                                id="from"
                                type="date"
                                value={range.from}
                                onChange={(event) =>
                                    setRange({
                                        ...range,
                                        from: event.target.value,
                                    })
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
                                    setRange({
                                        ...range,
                                        to: event.target.value,
                                    })
                                }
                                required
                            />
                        </div>
                        <Button type="submit" variant="outline">
                            {t('reports.apply')}
                        </Button>
                    </div>
                    <InputError message={errors.from ?? errors.to} />

                    {(
                        [
                            ['group_id', 'group', options.groups],
                            ['provider_id', 'provider', options.providers],
                            [
                                'ai_model_id',
                                'model',
                                options.models.map((model) => ({
                                    id: model.id,
                                    name: model.display_name,
                                })),
                            ],
                        ] as const
                    ).map(([field, key, list]) => (
                        <Select
                            key={field}
                            value={
                                filters[field] === null
                                    ? ALL
                                    : String(filters[field])
                            }
                            onValueChange={(value) =>
                                visit({ [field]: pickId(value) })
                            }
                        >
                            <SelectTrigger
                                aria-label={t(`reports.${key}`)}
                                className="w-full"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ALL}>
                                    {t(`reports.${key}`)}: {t('common.all')}
                                </SelectItem>
                                {list.map((item) => (
                                    <SelectItem
                                        key={item.id}
                                        value={String(item.id)}
                                    >
                                        {item.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    ))}

                    {options.user && (
                        <div className="flex flex-wrap items-center gap-2 text-sm sm:col-span-2 lg:col-span-3">
                            <span>
                                {t('reports.user')}:{' '}
                                <strong>{options.user.name}</strong> (
                                {options.user.email})
                            </span>
                            <Button
                                type="button"
                                variant="link"
                                size="sm"
                                onClick={() => visit({ user_id: null })}
                            >
                                {t('reports.clearUser')}
                            </Button>
                        </div>
                    )}
                </form>

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <Card data-test="report-spend">
                        <CardHeader>
                            <CardDescription>
                                {t('reports.spend')}
                            </CardDescription>
                            <CardTitle className="text-2xl tabular-nums">
                                {formatUsd(totals.cost_usd, lang)}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="text-xs text-muted-foreground">
                            {t('reports.adjustments')}:{' '}
                            {formatUsd(totals.adjustments_usd, lang)}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardDescription>
                                {t('reports.requests')}
                            </CardDescription>
                            <CardTitle className="text-2xl tabular-nums">
                                {formatNumber(totals.requests, lang)}
                            </CardTitle>
                        </CardHeader>
                        {totals.estimated > 0 && (
                            <CardContent className="text-xs text-muted-foreground">
                                {t('reports.estimated', {
                                    count: totals.estimated,
                                })}
                            </CardContent>
                        )}
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardDescription>
                                {t('reports.activeUsers')}
                            </CardDescription>
                            <CardTitle className="text-2xl tabular-nums">
                                {formatNumber(totals.users, lang)}
                            </CardTitle>
                        </CardHeader>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardDescription>
                                {t('reports.tokens')}
                            </CardDescription>
                            <CardTitle className="text-lg tabular-nums">
                                {formatNumber(totals.input_tokens, lang)} /{' '}
                                {formatNumber(totals.output_tokens, lang)}
                            </CardTitle>
                            {totals.web_searches > 0 && (
                                <p
                                    className="text-xs text-muted-foreground"
                                    data-test="report-web-searches"
                                >
                                    {t('reports.webSearches', {
                                        count: totals.web_searches,
                                        formatted: formatNumber(
                                            totals.web_searches,
                                            lang,
                                        ),
                                        cost: formatUsd(
                                            totals.web_search_usd,
                                            lang,
                                        ),
                                    })}
                                </p>
                            )}
                        </CardHeader>
                    </Card>
                </div>

                <Card>
                    <CardHeader className="flex-row items-center justify-between gap-4">
                        <CardTitle>{t('reports.overTime')}</CardTitle>
                        <ToggleGroup
                            type="single"
                            size="sm"
                            variant="outline"
                            value={filters.interval}
                            onValueChange={(value) =>
                                value &&
                                visit({
                                    interval: value as Filters['interval'],
                                })
                            }
                        >
                            <ToggleGroupItem value="day">
                                {t('reports.perDay')}
                            </ToggleGroupItem>
                            <ToggleGroupItem value="month">
                                {t('reports.perMonth')}
                            </ToggleGroupItem>
                        </ToggleGroup>
                    </CardHeader>
                    <CardContent>
                        <BarChart
                            label={t('reports.overTime')}
                            bars={timeline.map((point) => ({
                                key: point.date,
                                label: pointLabel(point.date),
                                value: Number(point.cost_usd),
                                title: `${pointLabel(point.date)}: ${formatUsd(point.cost_usd, lang)} · ${formatNumber(point.requests, lang)}`,
                            }))}
                        />
                    </CardContent>
                </Card>

                <section className="space-y-3">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h2 className="text-lg font-semibold">
                            {t('reports.breakdown')}
                        </h2>
                        <ToggleGroup
                            type="single"
                            size="sm"
                            variant="outline"
                            value={filters.by}
                            onValueChange={(value) =>
                                value && visit({ by: value as Dimension })
                            }
                        >
                            {DIMENSIONS.map((dimension) => (
                                <ToggleGroupItem
                                    key={dimension}
                                    value={dimension}
                                >
                                    {t(`reports.by_${dimension}`)}
                                </ToggleGroupItem>
                            ))}
                        </ToggleGroup>
                    </div>

                    {breakdown.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            {t('reports.empty')}
                        </p>
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>{t('reports.name')}</TableHead>
                                    <TableHead className="text-right">
                                        {t('reports.requests')}
                                    </TableHead>
                                    <TableHead className="hidden text-right md:table-cell">
                                        {t('reports.input')}
                                    </TableHead>
                                    <TableHead className="hidden text-right md:table-cell">
                                        {t('reports.output')}
                                    </TableHead>
                                    <TableHead className="hidden text-right lg:table-cell">
                                        {t('reports.searches')}
                                    </TableHead>
                                    <TableHead className="text-right">
                                        {t('reports.cost')}
                                    </TableHead>
                                    <TableHead className="hidden w-32 sm:table-cell">
                                        {t('reports.share')}
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {breakdown.map((row) => (
                                    <TableRow key={row.id ?? row.label}>
                                        <TableCell>
                                            {filters.by === 'user' &&
                                            row.id !== null ? (
                                                <button
                                                    type="button"
                                                    className="text-left font-medium underline-offset-4 hover:underline"
                                                    title={t(
                                                        'reports.filterUser',
                                                    )}
                                                    onClick={() =>
                                                        visit({
                                                            user_id: row.id,
                                                        })
                                                    }
                                                >
                                                    {row.label}
                                                </button>
                                            ) : (
                                                <span className="font-medium">
                                                    {row.label}
                                                </span>
                                            )}
                                            {row.detail && (
                                                <div className="text-xs text-muted-foreground">
                                                    {row.detail}
                                                </div>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {formatNumber(row.requests, lang)}
                                        </TableCell>
                                        <TableCell className="hidden text-right tabular-nums md:table-cell">
                                            {formatNumber(
                                                row.input_tokens,
                                                lang,
                                            )}
                                        </TableCell>
                                        <TableCell className="hidden text-right tabular-nums md:table-cell">
                                            {formatNumber(
                                                row.output_tokens,
                                                lang,
                                            )}
                                        </TableCell>
                                        <TableCell className="hidden text-right tabular-nums lg:table-cell">
                                            {formatNumber(
                                                row.web_searches,
                                                lang,
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {formatUsd(row.cost_usd, lang)}
                                        </TableCell>
                                        <TableCell className="hidden sm:table-cell">
                                            <ShareBar
                                                share={
                                                    total === 0
                                                        ? 0
                                                        : (Number(
                                                              row.cost_usd,
                                                          ) /
                                                              total) *
                                                          100
                                                }
                                            />
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                    {breakdown.length >= topRows && (
                        <p className="text-xs text-muted-foreground">
                            {t('reports.topRows', { count: topRows })}
                        </p>
                    )}
                </section>

                <Card>
                    <CardHeader>
                        <CardTitle>{t('reports.overshoots')}</CardTitle>
                        <CardDescription>
                            {t('reports.overshootsHelp')}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {overshoots.count === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                {t('reports.overshootsNone')}
                            </p>
                        ) : (
                            <>
                                <p className="text-sm font-medium">
                                    {t('reports.overshootsSummary', {
                                        count: overshoots.count,
                                        excess: formatUsd(
                                            overshoots.excess_usd,
                                            lang,
                                        ),
                                    })}
                                </p>
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>
                                                {t('reports.when')}
                                            </TableHead>
                                            <TableHead>
                                                {t('reports.user')}
                                            </TableHead>
                                            <TableHead className="hidden md:table-cell">
                                                {t('reports.model')}
                                            </TableHead>
                                            <TableHead className="text-right">
                                                {t('reports.countedInput')} →{' '}
                                                {t('reports.billedInput')}
                                            </TableHead>
                                            <TableHead className="text-right">
                                                {t('reports.reserved')} →{' '}
                                                {t('reports.charged')}
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {overshoots.latest.map((row) => (
                                            <TableRow
                                                key={`${row.created_at}-${row.user}`}
                                            >
                                                <TableCell className="text-xs">
                                                    {new Intl.DateTimeFormat(
                                                        lang,
                                                        {
                                                            dateStyle: 'short',
                                                            timeStyle: 'short',
                                                        },
                                                    ).format(
                                                        new Date(
                                                            row.created_at,
                                                        ),
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-xs">
                                                    {row.user}
                                                </TableCell>
                                                <TableCell className="hidden text-xs md:table-cell">
                                                    {row.model}
                                                </TableCell>
                                                <TableCell className="text-right text-xs tabular-nums">
                                                    {row.reserved_input_tokens ===
                                                    null
                                                        ? '—'
                                                        : formatNumber(
                                                              row.reserved_input_tokens,
                                                              lang,
                                                          )}{' '}
                                                    →{' '}
                                                    {formatNumber(
                                                        row.billed_input_tokens,
                                                        lang,
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-right text-xs tabular-nums">
                                                    ${row.reserved_usd} → $
                                                    {row.charged_usd}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>{t('reports.deviation')}</CardTitle>
                        <CardDescription>
                            {t('reports.deviationHelp')}
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {deviation.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                {t('reports.deviationNone')}
                            </p>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>
                                            {t('reports.model')}
                                        </TableHead>
                                        <TableHead className="hidden md:table-cell">
                                            {t('reports.method')}
                                        </TableHead>
                                        <TableHead className="text-right">
                                            {t('reports.requests')}
                                        </TableHead>
                                        <TableHead className="text-right">
                                            {t('reports.average')}
                                        </TableHead>
                                        <TableHead className="text-right">
                                            {t('reports.worst')}
                                        </TableHead>
                                        <TableHead className="hidden text-right sm:table-cell">
                                            {t('reports.aboveCount')}
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {deviation.map((row) => (
                                        <TableRow
                                            key={`${row.model}-${row.method}`}
                                        >
                                            <TableCell>
                                                {row.model}
                                                <div className="text-xs text-muted-foreground">
                                                    {row.provider}
                                                </div>
                                            </TableCell>
                                            <TableCell className="hidden md:table-cell">
                                                {row.method
                                                    ? t(
                                                          `reports.methods.${row.method}`,
                                                          {
                                                              defaultValue:
                                                                  row.method,
                                                          },
                                                      )
                                                    : '—'}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {formatNumber(
                                                    row.requests,
                                                    lang,
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {row.deviation_percent > 0
                                                    ? '+'
                                                    : ''}
                                                {row.deviation_percent}%
                                            </TableCell>
                                            <TableCell
                                                className={`text-right tabular-nums ${row.worst_percent > 0 ? 'text-destructive' : ''}`}
                                            >
                                                {row.worst_percent > 0
                                                    ? '+'
                                                    : ''}
                                                {row.worst_percent}%
                                            </TableCell>
                                            <TableCell className="hidden text-right tabular-nums sm:table-cell">
                                                {row.above_count}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

Reports.layout = {
    breadcrumbs: [{ titleKey: 'admin:reports.title', href: index() }],
};
