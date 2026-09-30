import { Link, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import BudgetMeter from '@/components/budget/budget-meter';
import { formatDate, formatUsd } from '@/lib/format';
import { usage } from '@/routes';

/** The user's monthly budget in the sidebar footer; links to the usage page. */
export default function BudgetIndicator() {
    const { t } = useTranslation('chat');
    const { budget, locale } = usePage().props;

    if (budget === null) {
        return null;
    }

    const amounts =
        budget.display === 'amount' &&
        budget.remaining_usd !== undefined &&
        budget.limit_usd !== undefined;

    return (
        <Link
            href={usage()}
            className="block rounded-md p-2 text-xs transition-colors group-data-[collapsible=icon]:hidden hover:bg-sidebar-accent"
            data-test="budget-indicator"
        >
            <div className="mb-1.5 flex items-baseline justify-between gap-2">
                <span className="font-medium">{t('budget.title')}</span>
                <span className="text-muted-foreground tabular-nums">
                    {t('budget.used', { percent: budget.percent_used })}
                </span>
            </div>
            <BudgetMeter
                percent={budget.percent_used}
                label={t('budget.title')}
            />
            <p className="mt-1.5 text-muted-foreground">
                {amounts
                    ? t('budget.left', {
                          remaining: formatUsd(
                              budget.remaining_usd as string,
                              locale.current,
                          ),
                          limit: formatUsd(
                              budget.limit_usd as string,
                              locale.current,
                          ),
                      })
                    : t('budget.renews', {
                          date: formatDate(budget.resets_on, locale.current),
                      })}
            </p>
        </Link>
    );
}
