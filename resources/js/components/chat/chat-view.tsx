import { router } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import Composer from '@/components/chat/composer';
import MessageItem from '@/components/chat/message-item';
import ModelSelector from '@/components/models/model-selector';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { useChatStream } from '@/hooks/use-chat-stream';
import { show } from '@/routes/conversations';
import { cancel, regenerate, store } from '@/routes/messages';
import type { AliasOption, ChatMessage, ErrorEvent } from '@/types/chat';

type Props = {
    conversationId: string | null;
    conversationAliasId: number | null;
    messages: ChatMessage[];
    aliases: AliasOption[];
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

    const candidates = [preferred, stored];

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
}: Props) {
    const { t } = useTranslation('chat');
    const { status, draft, run, stop } = useChatStream();
    const [input, setInput] = useState('');
    const [aliasId, setAliasId] = useState<number | null>(() =>
        rememberedAlias(aliases, conversationAliasId),
    );
    const [pending, setPending] = useState<Pending | null>(null);
    const [error, setError] = useState<string | null>(null);
    const bottom = useRef<HTMLDivElement>(null);
    const streaming = status === 'streaming';

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
            });
        }

        return list;
    }, [messages, pending, draft, streaming]);

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
        t(`errors.${event.code}`, { defaultValue: t('errors.unknown') });

    const start = (
        url: string,
        body: Record<string, unknown>,
        next: Pending,
        restoreInput: string | null,
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
                    setError(describe(event));

                    if (restoreInput !== null) {
                        setInput(restoreInput);
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
                        only: ['messages', 'conversations'],
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

    const send = () => {
        const content = input.trim();

        if (content === '' || aliasId === null) {
            return;
        }

        setInput('');
        start(
            store.url(),
            {
                content,
                model_alias_id: aliasId,
                conversation_id: conversationId,
            },
            {
                user: {
                    ...assistantPlaceholder(),
                    id: 'pending-user',
                    role: 'user',
                    content,
                    status: 'completed',
                },
                assistant: assistantPlaceholder(),
                replaces: null,
            },
            content,
        );
    };

    const regenerateLast = (message: ChatMessage) =>
        start(
            regenerate.url(message.id),
            { model_alias_id: aliasId },
            {
                user: null,
                assistant: assistantPlaceholder(),
                replaces: message.id,
            },
            null,
        );

    const last = shown.at(-1);

    return (
        <div className="mx-auto flex w-full max-w-3xl flex-1 flex-col px-4">
            {shown.length === 0 ? (
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

            <div className="sticky bottom-0 bg-gradient-to-t from-background from-80% pt-2 pb-4">
                {error && (
                    <Alert variant="destructive" className="mb-2">
                        <AlertDescription>{error}</AlertDescription>
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
                        toolbar={
                            <ModelSelector
                                aliases={aliases}
                                value={aliasId}
                                onChange={chooseAlias}
                                disabled={streaming}
                            />
                        }
                    />
                )}
            </div>
        </div>
    );
}
