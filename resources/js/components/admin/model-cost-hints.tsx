import { useTranslation } from 'react-i18next';
import { formatNumber, formatUsdPrecise } from '@/lib/format';

/** Tokens per English word, the usual rule of thumb. */
export const WORDS_PER_TOKEN = 0.75;
const WORDS_PER_PAGE = 500;
/** "Typical message" used in the budget estimate. */
const TYPICAL = { input: 2000, output: 500 };

export type Prices = {
    input: number;
    output: number;
    cachedInput: number;
    cacheWrite: number;
};

/** USD for a number of tokens at a price per million. */
export const cost = (tokens: number, perMillion: number) =>
    (tokens * perMillion) / 1_000_000;

/** A price field as a number; empty or invalid counts as `fallback`. */
export function price(value: string, fallback = 0): number {
    const parsed = Number(value.replace(',', '.'));

    return value.trim() === '' || Number.isNaN(parsed) ? fallback : parsed;
}

export function words(tokens: number): number {
    return Math.round(tokens * WORDS_PER_TOKEN);
}

export function pages(tokens: number): number {
    return Math.max(1, Math.round(words(tokens) / WORDS_PER_PAGE));
}

/**
 * Worked examples under the price fields: what a few kinds of messages cost
 * and how far a monthly budget goes, recalculated as the prices change.
 */
export default function ModelCostHints({
    prices,
    budget,
    lang,
}: {
    prices: Prices;
    /** A monthly limit to illustrate with, e.g. the default policy. */
    budget: number;
    lang: string;
}) {
    const { t } = useTranslation('admin');

    const examples = [
        { key: 'exampleShort', input: 500, output: 300 },
        { key: 'exampleTypical', ...TYPICAL },
        { key: 'exampleLong', input: 20000, output: 1000 },
    ] as const;

    const typical =
        cost(TYPICAL.input, prices.input) + cost(TYPICAL.output, prices.output);

    return (
        <div
            className="space-y-2 rounded-lg border bg-muted/30 p-3 text-xs"
            data-test="model-cost-hints"
        >
            <p className="font-medium">{t('models.hints.examplesTitle')}</p>
            <dl className="space-y-1">
                {examples.map((example) => (
                    <div
                        key={example.key}
                        className="flex justify-between gap-4"
                    >
                        <dt className="text-muted-foreground">
                            {t(`models.hints.${example.key}`)}
                        </dt>
                        <dd className="tabular-nums">
                            {formatUsdPrecise(
                                cost(example.input, prices.input) +
                                    cost(example.output, prices.output),
                                lang,
                            )}
                        </dd>
                    </div>
                ))}
            </dl>
            {typical > 0 && budget > 0 && (
                <p>
                    {t('models.hints.perBudget', {
                        budget: formatUsdPrecise(budget, lang),
                        count: formatNumber(Math.floor(budget / typical), lang),
                    })}
                </p>
            )}
            <p className="text-muted-foreground">
                {t('models.hints.tokensNote')}
            </p>
        </div>
    );
}
