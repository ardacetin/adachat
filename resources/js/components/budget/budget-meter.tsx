import { cn } from '@/lib/utils';

/** A horizontal bar for the share of the budget used. */
export default function BudgetMeter({
    percent,
    label,
    className,
}: {
    percent: number;
    label: string;
    className?: string;
}) {
    const value = Math.max(0, Math.min(100, percent));

    return (
        <div
            role="progressbar"
            aria-label={label}
            aria-valuemin={0}
            aria-valuemax={100}
            aria-valuenow={value}
            className={cn(
                'h-1.5 w-full overflow-hidden rounded-full bg-muted',
                className,
            )}
        >
            <div
                className={cn(
                    'h-full rounded-full transition-[width]',
                    value >= 90 ? 'bg-destructive' : 'bg-primary',
                )}
                style={{ width: `${value}%` }}
            />
        </div>
    );
}
