/** App\Domain\Reports\UsageStatistics results. Amounts are decimal strings. */

export type Totals = {
    requests: number;
    users: number;
    input_tokens: number;
    output_tokens: number;
    web_searches: number;
    web_search_usd: string;
    cost_usd: string;
    estimated: number;
    adjustments_usd: string;
};

export type BreakdownRow = {
    id: number | null;
    label: string;
    detail: string | null;
    requests: number;
    input_tokens: number;
    output_tokens: number;
    web_searches: number;
    cost_usd: string;
};

export type TimelinePoint = {
    date: string;
    requests: number;
    cost_usd: string;
};

export type Kpis = {
    month: string;
    cost_usd: string;
    previous_cost_usd: string;
    requests: number;
    previous_requests: number;
    active_users: number;
    previous_active_users: number;
    average_per_user_usd: string;
    users_at_limit: number;
};

export type Overshoots = {
    count: number;
    excess_usd: string;
    latest: {
        created_at: string;
        user: string | null;
        model: string | null;
        input_count_method: string | null;
        reserved_usd: string;
        charged_usd: string;
        reserved_input_tokens: number | null;
        billed_input_tokens: number;
    }[];
};

export type DeviationRow = {
    provider: string | null;
    model: string | null;
    method: string | null;
    requests: number;
    billed_input_tokens: number;
    reserved_input_tokens: number;
    deviation_percent: number;
    worst_percent: number;
    above_count: number;
};
