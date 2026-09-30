/** The signed-in user's budget (App\Domain\Budget\Services\BudgetSummary). */
export type BudgetSummary = {
    display: 'amount' | 'percent';
    percent_used: number;
    exhausted: boolean;
    /** Calendar dates (YYYY-MM-DD) in the institution's time zone. */
    period_start: string;
    resets_on: string;
    /** Only with the "amount" display. */
    limit_usd?: string;
    spent_usd?: string;
    remaining_usd?: string;
};

type Cost = { cost_usd?: string; percent_of_limit?: number };

export type UsageByModel = Cost & {
    alias: string | null;
    requests: number;
    input_tokens: number;
    output_tokens: number;
};

export type UsageByDay = Cost & { date: string; requests: number };

export type UsageMonth = {
    month: string;
    percent_used: number;
    spent_usd?: string;
    limit_usd?: string;
};
