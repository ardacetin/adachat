import { useId, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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

/** A calculator field as a non-negative number (empty = 0). */
const amount = (value: string) => Math.max(0, price(value));

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

    // The administrator's own scenario, recalculated on every keystroke.
    const id = useId();
    const [scenario, setScenario] = useState({
        input: String(TYPICAL.input),
        output: String(TYPICAL.output),
        messages: '100',
        budget: String(budget),
    });
    const perMessage =
        cost(amount(scenario.input), prices.input) +
        cost(amount(scenario.output), prices.output);
    const messages = Math.floor(amount(scenario.messages));
    const scenarioBudget = amount(scenario.budget);
    const fits =
        perMessage > 0 ? Math.floor(scenarioBudget / perMessage) : null;

    const field = (key: keyof typeof scenario, label: string, step: string) => (
        <div className="space-y-1">
            <Label htmlFor={`${id}-${key}`} className="text-xs font-normal">
                {label}
            </Label>
            <Input
                id={`${id}-${key}`}
                type="number"
                inputMode="decimal"
                min={0}
                step={step}
                className="h-8 bg-background text-xs tabular-nums"
                value={scenario[key]}
                onChange={(event) =>
                    setScenario((current) => ({
                        ...current,
                        [key]: event.target.value,
                    }))
                }
                // Not part of the model form: Enter must not submit it.
                onKeyDown={(event) => {
                    if (event.key === 'Enter') {
                        event.preventDefault();
                    }
                }}
                data-test={`calculator-${key}`}
            />
        </div>
    );

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

            <div
                className="space-y-2 border-t pt-3"
                data-test="cost-calculator"
            >
                <p className="font-medium">
                    {t('models.hints.calculatorTitle')}
                </p>
                <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                    {field('input', t('models.hints.calculatorInput'), '100')}
                    {field('output', t('models.hints.calculatorOutput'), '100')}
                    {field(
                        'messages',
                        t('models.hints.calculatorMessages'),
                        '1',
                    )}
                    {field(
                        'budget',
                        t('models.hints.calculatorBudget'),
                        '0.01',
                    )}
                </div>
                <dl className="space-y-1" aria-live="polite">
                    <div className="flex justify-between gap-4">
                        <dt className="text-muted-foreground">
                            {t('models.hints.calculatorPerMessage', {
                                words: formatNumber(
                                    words(
                                        amount(scenario.input) +
                                            amount(scenario.output),
                                    ),
                                    lang,
                                ),
                            })}
                        </dt>
                        <dd
                            className="tabular-nums"
                            data-test="calculator-per-message"
                        >
                            {formatUsdPrecise(perMessage, lang)}
                        </dd>
                    </div>
                    <div className="flex justify-between gap-4">
                        <dt className="text-muted-foreground">
                            {t('models.hints.calculatorTotal', {
                                count: formatNumber(messages, lang),
                            })}
                        </dt>
                        <dd
                            className="tabular-nums"
                            data-test="calculator-total"
                        >
                            {formatUsdPrecise(perMessage * messages, lang)}
                        </dd>
                    </div>
                    <div className="flex justify-between gap-4">
                        <dt className="text-muted-foreground">
                            {t('models.hints.calculatorFits', {
                                budget: formatUsdPrecise(scenarioBudget, lang),
                            })}
                        </dt>
                        <dd
                            className="tabular-nums"
                            data-test="calculator-fits"
                        >
                            {fits === null ? '—' : formatNumber(fits, lang)}
                        </dd>
                    </div>
                </dl>
            </div>

            <p className="text-muted-foreground">
                {t('models.hints.tokensNote')}
            </p>
        </div>
    );
}
