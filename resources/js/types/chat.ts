export type MessageStatus = 'completed' | 'streaming' | 'failed' | 'cancelled';

export type ChatMessage = {
    id: string;
    role: 'user' | 'assistant';
    content: string;
    status: MessageStatus;
    error_code: string | null;
    finish_reason: string | null;
    output_capped: boolean;
    attachments?: AttachmentInfo[];
    sources?: Source[];
    /** Searches the provider runs while the answer streams. */
    searches?: (string | null)[];
};

/** A web page an answer is based on. */
export type Source = {
    url: string;
    title: string | null;
};

export type AttachmentInfo = {
    id: string;
    kind: 'image' | 'text' | 'pdf' | 'document';
    name: string;
    size: number;
    mime: string;
    pages: number | null;
    token_estimate: number;
    url: string;
};

export type AliasOption = {
    id: number;
    name: string;
    description: string | null;
    details: string | null;
    supports_vision: boolean;
    /** What web search allows and costs with this alias; null when it cannot search. */
    web_search: { max_uses: number; price_per_search: string } | null;
};

export type ConversationSummary = {
    id: string;
    title: string | null;
    pinned: boolean;
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
    sources: Source[];
    usage: {
        input_tokens: number;
        output_tokens: number;
        web_searches: number;
        cost_usd: string;
        estimated: boolean;
    };
};

export type ErrorEvent = {
    code: string;
    retryable: boolean;
    assistant_message_id?: string;
    /** A validation message from the server, already translated. */
    message?: string;
};

export type AssistantSummary = {
    id: number;
    slug: string;
    name: string;
    description: string | null;
    icon: string;
    model_alias_id: number;
    starter_prompts: string[];
    /** Whether the assistant's users may search the web. */
    web_search: boolean;
};
