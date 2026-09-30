export type MessageStatus = 'completed' | 'streaming' | 'failed' | 'cancelled';

export type ChatMessage = {
    id: string;
    role: 'user' | 'assistant';
    content: string;
    status: MessageStatus;
    error_code: string | null;
    finish_reason: string | null;
    output_capped: boolean;
};

export type AliasOption = {
    id: number;
    name: string;
    description: string | null;
    details: string | null;
};

export type ConversationSummary = {
    id: string;
    title: string | null;
};

export type StartedEvent = {
    conversation_id: string;
    user_message_id: string | null;
    assistant_message_id: string;
    model_alias_id: number;
    output_capped: boolean;
};

export type CompletedEvent = {
    assistant_message_id: string;
    status: MessageStatus;
    finish_reason: string | null;
    usage: {
        input_tokens: number;
        output_tokens: number;
        cost_usd: string;
        estimated: boolean;
    };
};

export type ErrorEvent = {
    code: string;
    retryable: boolean;
    assistant_message_id?: string;
};
