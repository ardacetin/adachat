import { Head, Link, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import BarChart, { ShareBar } from '@/components/admin/bar-chart';
import Heading from '@/components/heading';
import { Alert, AlertDescription } from '@/components/ui/alert';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { formatDate, formatNumber, formatUsd } from '@/lib/format';
import { index } from '@/routes/admin';
import { index as reports } from '@/routes/admin/reports';
import type { BreakdownRow, Kpis, TimelinePoint } from '@/types/reports';

type Props = {
    kpis: Kpis;
    daily: TimelinePoint[];
    topModels: BreakdownRow[];
    topGroups: BreakdownRow[];
    overshoots: number;
};

function Ranked({ title, rows }: { title: string; rows: BreakdownRow[] }) {
    const { t } = useTranslation('admin');
    const { locale } = usePage().props;
    const max = Math.max(0, ...rows.map((row) => Number(row.cost_usd)));

    return (
        <Card>
            <CardHeader>
                <CardTitle>{title}</CardTitle>
            </CardHeader>
            <CardContent>
                {rows.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('dashboard.noUsage')}
                    </p>
                ) : (
                    <ul className="space-y-3 text-sm">
                        {rows.map((row) => (
                            <li key={row.id ?? row.label} className="space-y-1">
                                <div className="flex justify-between gap-2">
                                    <span className="truncate">
                                        {row.label}
                                    </span>
                                    <span className="tabular-nums">
                                        {formatUsd(
                                            row.cost_usd,
                                            locale.current,
                                        )}
                                    </span>
                                </div>
                                <ShareBar
                                    share={
                                        max === 0
                                            ? 0
                                            : (Number(row.cost_usd) / max) * 100
                                    }
                                />
                            </li>
                        ))}
                    </ul>
                )}
            </CardContent>
        </Card>
    );
}

export default function AdminDashboard({
    kpis,
    daily,
    topModels,
    topGroups,
    overshoots,
}: Props) {
    const { t } = useTranslation('admin');
    const { locale } = usePage().props;
    const lang = locale.current;

    const cards = [
        {
            key: 'spend',
            value: formatUsd(kpis.cost_usd, lang),
            previous: formatUsd(kpis.previous_cost_usd, lang),
        },
        {
            key: 'requests',
            value: formatNumber(kpis.requests, lang),
            previous: formatNumber(kpis.previous_requests, lang),
        },
        {
            key: 'activeUsers',
            value: formatNumber(kpis.active_users, lang),
            previous: formatNumber(kpis.previous_active_users, lang),
        },
        {
            key: 'average',
            value: formatUsd(kpis.average_per_user_usd, lang),
            previous: null,
        },
    ] as const;

    return (
        <>
            <Head title={t('title')} />

            <div className="space-y-6">
                <div className="flex items-start justify-between gap-4">
                    <Heading
                        variant="small"
                        title={t('nav.overview')}
                        description={t('index.intro')}
                    />
                    <Link
                        href={reports()}
                        className="text-sm underline underline-offset-4"
                    >
                        {t('dashboard.allReports')}
                    </Link>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    {cards.map((card) => (
                        <Card key={card.key} data-test={`kpi-${card.key}`}>
                            <CardHeader>
                                <CardDescription>
                                    {t(`dashboard.${card.key}`)}
                                </CardDescription>
                                <CardTitle className="text-2xl tabular-nums">
                                    {card.value}
                                </CardTitle>
                            </CardHeader>
                            {card.previous !== null && (
                                <CardContent className="text-xs text-muted-foreground">
                                    {t('dashboard.previous', {
                                        value: card.previous,
                                    })}
                                </CardContent>
                            )}
                        </Card>
                    ))}
                </div>

                {kpis.users_at_limit > 0 && (
                    <Alert>
                        <AlertDescription>
                            {t('dashboard.atLimit', {
                                count: kpis.users_at_limit,
                            })}
                        </AlertDescription>
                    </Alert>
                )}
                {overshoots > 0 && (
                    <Alert variant="destructive">
                        <AlertDescription>
                            <span>
                                {t('dashboard.overshoots', {
                                    count: overshoots,
                                })}{' '}
                                <Link
                                    href={reports()}
                                    className="underline underline-offset-4"
                                >
                                    {t('dashboard.details')}
                                </Link>
                            </span>
                        </AlertDescription>
                    </Alert>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle>{t('dashboard.daily')}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <BarChart
                            label={t('dashboard.daily')}
                            bars={daily.map((point) => ({
                                key: point.date,
                                label: formatDate(point.date, lang),
                                value: Number(point.cost_usd),
                                title: `${formatDate(point.date, lang)}: ${formatUsd(point.cost_usd, lang)} · ${formatNumber(point.requests, lang)}`,
                            }))}
                        />
                    </CardContent>
                </Card>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Ranked title={t('dashboard.topModels')} rows={topModels} />
                    <Ranked title={t('dashboard.topGroups')} rows={topGroups} />
                </div>
            </div>
        </>
    );
}

AdminDashboard.layout = {
    breadcrumbs: [{ titleKey: 'admin:title', href: index() }],
};
