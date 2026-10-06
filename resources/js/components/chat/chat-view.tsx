import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import AssistantIcon from '@/components/chat/assistant-icon';
import Composer from '@/components/chat/composer';
import MessageItem from '@/components/chat/message-item';
import ModelSelector from '@/components/models/model-selector';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { useAttachments } from '@/hooks/use-attachments';
import type { AttachmentItem } from '@/hooks/use-attachments';
import { useChatStream } from '@/hooks/use-chat-stream';
import { formatDate } from '@/lib/format';
import { usage } from '@/routes';
import { show } from '@/routes/conversations';
import { cancel, regenerate, store } from '@/routes/messages';
import type {
    AliasOption,
    AssistantSummary,
    ChatMessage,
    ErrorEvent,
} from '@/types/chat';

type Props = {
    conversationId: string | null;
    conversationAliasId: number | null;
    messages: ChatMessage[];
    aliases: AliasOption[];
    /** The assistant the conversation is (or will be) held with. */
    assistant?: AssistantSummary | null;
};

const LAST_ALIAS_KEY = 'ada.chat.alias';

function rememberedAlias(
    aliases: AliasOption[],
    preferred: number | null,
): number | null {
    const stored = (() => {
        try {
            return Number(window.localStorage.getItem(LAST_ALIAS_KEY));
        } catch {
            return NaN;
        }
    })();

    // The conversation's own model, then the institution's default for new
    // conversations, then the user's last choice in this browser.
    const institutionDefault =
        aliases.find((alias) => alias.is_default)?.id ?? null;
    const candidates = [preferred, institutionDefault, stored];

    for (const id of candidates) {
        if (aliases.some((alias) => alias.id === id)) {
            return id as number;
        }
    }

    return aliases[0]?.id ?? null;
}

type Pending = {
    user: ChatMessage | null;
    assistant: ChatMessage;
    /** Regenerating hides the answer being replaced. */
    replaces: string | null;
};

export default function ChatView({
    conversationId,
    conversationAliasId,
    messages,
    aliases,
    assistant = null,
}: Props) {
    const { t } = useTranslation('chat');
    const { budget, locale } = usePage().props;
    const { status, draft, activity, run, stop } = useChatStream();
    const [input, setInput] = useState('');
    // Off for every new message: searching costs money and sends the question out.
    const [webSearch, setWebSearch] = useState(false);
    // An assistant always answers with its own model.
    const [aliasId, setAliasId] = useState<number | null>(() =>
        assistant
            ? assistant.model_alias_id
            : rememberedAlias(aliases, conversationAliasId),
    );
    const [pending, setPending] = useState<Pending | null>(null);
    const [error, setError] = useState<string | null>(null);
    const bottom = useRef<HTMLDivElement>(null);
    const streaming = status === 'streaming';
    const alias = aliases.find((candidate) => candidate.id === aliasId);
    const searchOptions =
        assistant && !assistant.web_search ? null : (alias?.web_search ?? null);
    const searching = webSearch && searchOptions !== null;
    const attachments = useAttachments({
        imagesAllowed: alias?.supports_vision ?? false,
        messages: {
            imagesUnsupported: t('attachments.imagesUnsupported'),
            tooLarge: t('attachments.tooLarge'),
        },
    });

    const chooseAlias = (id: number) => {
        setAliasId(id);

        try {
            window.localStorage.setItem(LAST_ALIAS_KEY, String(id));
        } catch {
            // Remembering the choice is a convenience only.
        }
    };

    const shown = useMemo(() => {
        const list = pending?.replaces
            ? messages.filter((message) => message.id !== pending.replaces)
            : [...messages];

        if (pending) {
            if (pending.user) {
                list.push(pending.user);
            }

            list.push({
                ...pending.assistant,
                content: streaming ? draft : pending.assistant.content || draft,
                searches: activity.searches,
                sources: activity.sources,
            });
        }

        return list;
    }, [messages, pending, draft, streaming, activity]);

    // Follow the answer while it streams, unless the user scrolled up.
    useEffect(() => {
        const nearBottom =
            window.innerHeight + window.scrollY >=
            document.body.scrollHeight - 160;

        if (nearBottom) {
            bottom.current?.scrollIntoView({ block: 'end' });
        }
    }, [shown.length, draft]);

    const describe = (event: ErrorEvent) =>
        event.message ??
        t(`errors.${event.code}`, { defaultValue: t('errors.unknown') });

    const start = (
        url: string,
        body: Record<string, unknown>,
        next: Pending,
        restoreInput: string | null,
        restoreFiles: AttachmentItem[] = [],
    ) => {
        let targetConversation = conversationId;
        let started = false;

        setError(null);
        setPending(next);

        void run(url, body, {
            onStarted: (event) => {
                started = true;
                targetConversation = event.conversation_id;
                setPending(
                    (current) =>
                        current && {
                            ...current,
                            assistant: {
                                ...current.assistant,
                                id: event.assistant_message_id,
                                output_capped: event.output_capped,
                            },
                        },
                );
            },
            onError: (event) => {
                if (!started) {
                    // Refused before anything was stored: give the text back.
                    setPending(null);

                    if (event.code === 'budget_exhausted') {
                        // The budget alert below explains it, with the renewal date.
                        router.reload({ only: ['budget'] });
                    } else {
                        setError(describe(event));
                    }

                    if (restoreInput !== null) {
                        setInput(restoreInput);
                    }

                    if (restoreFiles.length > 0) {
                        attachments.restore(restoreFiles);
                    }

                    return;
                }

                setPending(
                    (current) =>
                        current && {
                            ...current,
                            assistant: {
                                ...current.assistant,
                                status: 'failed',
                                error_code: event.code,
                            },
                        },
                );
            },
            onSettled: () => {
                if (!started || targetConversation === null) {
                    return;
                }

                // The server has the final state; show it without a jump.
                const done = {
                    preserveScroll: true,
                    onFinish: () => setPending(null),
                };

                if (targetConversation === conversationId) {
                    router.reload({
                        only: ['messages', 'conversations', 'budget'],
                        ...done,
                    });
                } else {
                    router.visit(show(targetConversation), {
                        replace: true,
                        ...done,
                    });
                }
            },
        });
    };

    const assistantPlaceholder = (): ChatMessage => ({
        id: 'pending-assistant',
        role: 'assistant',
        content: '',
        status: 'streaming',
        error_code: null,
        finish_reason: null,
        output_capped: false,
    });

    const send = (text?: string) => {
        const content = (text ?? input).trim();
        const files = attachments.ready;

        if ((content === '' && files.length === 0) || aliasId === null) {
            return;
        }

        // Files that failed to upload are not sent; they stay visible
        // only until the message goes out.
        const sentItems = attachments.items.filter(
            (item) => item.status === 'ready',
        );

        setInput('');
        attachments.clear();
        setWebSearch(false);
        start(
            store.url(),
            {
                content,
                model_alias_id: aliasId,
                conversation_id: conversationId,
                assistant_id:
                    conversationId === null ? (assistant?.id ?? null) : null,
                attachment_ids: files.map((file) => file.id),
                web_search: searching,
            },
            {
                user: {
                    ...assistantPlaceholder(),
                    id: 'pending-user',
                    role: 'user',
                    content,
                    status: 'completed',
                    attachments: files,
                },
                assistant: assistantPlaceholder(),
                replaces: null,
            },
            content,
            sentItems,
        );
    };

    const regenerateLast = (message: ChatMessage) => {
        setWebSearch(false);
        start(
            regenerate.url(message.id),
            { model_alias_id: aliasId, web_search: searching },
            {
                user: null,
                assistant: assistantPlaceholder(),
                replaces: message.id,
            },
            null,
        );
    };

    const last = shown.at(-1);
    const exhausted = budget?.exhausted === true && !streaming;
    // At 80 %, once per period until the user closes it.
    const warningKey = budget ? `ada.budget.warned.${budget.period_start}` : '';
    const [warningClosed, setWarningClosed] = useState(() => {
        try {
            return window.localStorage.getItem(warningKey) === '1';
        } catch {
            return false;
        }
    });
    const nearLimit =
        budget !== null &&
        budget !== undefined &&
        !budget.exhausted &&
        budget.percent_used >= 80 &&
        !warningClosed;
    const closeWarning = () => {
        setWarningClosed(true);

        try {
            window.localStorage.setItem(warningKey, '1');
        } catch {
            // Closing it is a convenience only.
        }
    };

    return (
        <div className="mx-auto flex w-full max-w-3xl flex-1 flex-col px-4">
            {shown.length === 0 && assistant ? (
                <div
                    className="flex flex-1 flex-col items-center justify-center gap-4 py-16 text-center"
                    data-test="assistant-intro"
                >
                    <AssistantIcon
                        name={assistant.icon}
                        className="size-10 text-muted-foreground"
                    />
                    <h1 className="text-2xl font-semibold">{assistant.name}</h1>
                    {assistant.description && (
                        <p className="max-w-md text-sm text-muted-foreground">
                            {assistant.description}
                        </p>
                    )}
                    {assistant.starter_prompts.length > 0 && (
                        <div className="flex max-w-xl flex-wrap justify-center gap-2">
                            {assistant.starter_prompts.map((prompt) => (
                                <Button
                                    key={prompt}
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    className="h-auto py-2 whitespace-normal"
                                    disabled={
                                        streaming || budget?.exhausted === true
                                    }
                                    onClick={() => send(prompt)}
                                >
                                    {prompt}
                                </Button>
                            ))}
                        </div>
                    )}
                </div>
            ) : shown.length === 0 ? (
                <div className="flex flex-1 flex-col items-center justify-center gap-2 py-16 text-center">
                    <h1 className="text-2xl font-semibold">
                        {t('empty.title')}
                    </h1>
                    <p className="max-w-md text-sm text-muted-foreground">
                        {t('empty.hint')}
                    </p>
                </div>
            ) : (
                <div
                    className="flex flex-1 flex-col gap-6 py-6"
                    aria-live="polite"
                    aria-busy={streaming}
                >
                    {shown.map((message) => (
                        <MessageItem
                            key={message.id}
                            message={message}
                            streaming={
                                streaming &&
                                message.id === pending?.assistant.id
                            }
                            onRegenerate={
                                !streaming &&
                                message === last &&
                                message.role === 'assistant' &&
                                message.status !== 'streaming'
                                    ? () => regenerateLast(message)
                                    : undefined
                            }
                        />
                    ))}
                </div>
            )}

            <div ref={bottom} aria-hidden />

            <div
                className="sticky bottom-0 bg-gradient-to-t from-background from-80% pt-2 pb-4"
                data-print-hide
            >
                {error && (
                    <Alert variant="destructive" className="mb-2">
                        <AlertDescription>{error}</AlertDescription>
                    </Alert>
                )}
                {nearLimit && budget && (
                    <Alert className="mb-2" data-test="budget-near-limit">
                        <AlertDescription className="flex items-start justify-between gap-3">
                            <span>
                                {t('budget.nearLimit', {
                                    percent: budget.percent_used,
                                    date: formatDate(
                                        budget.resets_on,
                                        locale.current,
                                    ),
                                })}{' '}
                                <Link
                                    href={usage()}
                                    className="underline underline-offset-4"
                                >
                                    {t('budget.details')}
                                </Link>
                            </span>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                className="h-auto px-2 py-0.5"
                                onClick={closeWarning}
                            >
                                {t('budget.dismiss')}
                            </Button>
                        </AlertDescription>
                    </Alert>
                )}
                {exhausted && budget && (
                    <Alert
                        variant="destructive"
                        className="mb-2"
                        data-test="budget-exhausted"
                    >
                        <AlertDescription>
                            <p>
                                {t('budget.exhausted', {
                                    date: formatDate(
                                        budget.resets_on,
                                        locale.current,
                                    ),
                                })}
                            </p>
                            <Link
                                href={usage()}
                                className="underline underline-offset-4"
                            >
                                {t('budget.details')}
                            </Link>
                        </AlertDescription>
                    </Alert>
                )}
                {aliases.length === 0 ? (
                    <Alert>
                        <AlertDescription>{t('model.none')}</AlertDescription>
                    </Alert>
                ) : (
                    <Composer
                        value={input}
                        onChange={setInput}
                        onSubmit={send}
                        onStop={() => stop((id) => cancel.url(id))}
                        streaming={streaming}
                        disabled={exhausted}
                        attachments={{
                            items: attachments.items,
                            readyCount: attachments.ready.length,
                            uploading: attachments.uploading,
                            imagesAllowed: alias?.supports_vision ?? false,
                            onAdd: attachments.add,
                            onRemove: attachments.remove,
                        }}
                        webSearch={
                            searchOptions && {
                                enabled: webSearch,
                                onToggle: () => setWebSearch((on) => !on),
                                maxUses: searchOptions.max_uses,
                                pricePerSearch: searchOptions.price_per_search,
                            }
                        }
                        toolbar={
                            <ModelSelector
                                aliases={aliases}
                                value={aliasId}
                                onChange={chooseAlias}
                                disabled={streaming || assistant !== null}
                            />
                        }
                    />
                )}
            </div>
        </div>
    );
}
