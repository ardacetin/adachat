import { cn } from '@/lib/utils';

type Bar = { key: string; label: string; value: number; title: string };

/**
 * A dependency-free vertical bar chart. Every bar has a title (hover) and
 * the same figures are in the table next to or below it, so nothing is
 * conveyed by the chart alone.
 */
export default function BarChart({
    bars,
    label,
    className,
}: {
    bars: Bar[];
    label: string;
    className?: string;
}) {
    const max = Math.max(0, ...bars.map((bar) => bar.value));

    return (
        <figure className={cn('space-y-1', className)} aria-label={label}>
            <div className="flex h-40 items-end gap-px" aria-hidden>
                {bars.map((bar) => (
                    <div
                        key={bar.key}
                        title={bar.title}
                        className="flex h-full flex-1 items-end"
                    >
                        <div
                            className="w-full rounded-t-sm bg-primary/80 transition-[height] hover:bg-primary"
                            style={{
                                height:
                                    max === 0
                                        ? '0%'
                                        : `${Math.max(bar.value > 0 ? 2 : 0, (bar.value / max) * 100)}%`,
                            }}
                        />
                    </div>
                ))}
            </div>
            {bars.length > 0 && (
                <figcaption className="flex justify-between text-xs text-muted-foreground">
                    <span>{bars[0].label}</span>
                    <span>{bars[bars.length - 1].label}</span>
                </figcaption>
            )}
        </figure>
    );
}

/** Horizontal share bars for a ranked list. */
export function ShareBar({ share }: { share: number }) {
    return (
        <div className="h-1.5 w-full overflow-hidden rounded-full bg-muted">
            <div
                className="h-full rounded-full bg-primary"
                style={{ width: `${Math.max(0, Math.min(100, share))}%` }}
            />
        </div>
    );
}
