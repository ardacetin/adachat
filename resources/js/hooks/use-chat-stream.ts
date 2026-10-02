import { createParser } from 'eventsource-parser';
import { useCallback, useEffect, useRef, useState } from 'react';
import { xsrfToken } from '@/lib/xsrf';
import type {
    CompletedEvent,
    ErrorEvent,
    Source,
    StartedEvent,
} from '@/types/chat';

export type StreamStatus = 'idle' | 'streaming';

/** Web searches and sources of the answer being streamed. */
export type StreamActivity = {
    searches: (string | null)[];
    sources: Source[];
};

const NO_ACTIVITY: StreamActivity = { searches: [], sources: [] };

type Handlers = {
    onStarted?: (event: StartedEvent) => void;
    onCompleted?: (event: CompletedEvent) => void;
    onError?: (event: ErrorEvent) => void;
    /** Called once the request is over, whatever the outcome. */
    onSettled?: () => void;
};

async function firstValidationError(
    response: Response,
): Promise<string | undefined> {
    try {
        const body = (await response.json()) as {
            errors?: Record<string, string[]>;
        };

        return Object.values(body.errors ?? {})[0]?.[0];
    } catch {
        return undefined;
    }
}

/** How long Stop waits for the server to finish before dropping the connection. */
const STOP_GRACE_MS = 5000;

/**
 * One chat generation over server-sent events on a POST request
 * (docs/frontend-architecture.md §4). Deltas are batched per animation
 * frame so the answer does not re-render on every token.
 */
export function useChatStream() {
    const [status, setStatus] = useState<StreamStatus>('idle');
    const [draft, setDraft] = useState('');
    const [activity, setActivity] = useState<StreamActivity>(NO_ACTIVITY);
    const bufferRef = useRef('');
    const frameRef = useRef<number | null>(null);
    const controllerRef = useRef<AbortController | null>(null);
    const assistantIdRef = useRef<string | null>(null);

    const flush = useCallback(() => {
        frameRef.current = null;
        setDraft(bufferRef.current);
    }, []);

    const run = useCallback(
        async (
            url: string,
            body: Record<string, unknown>,
            handlers: Handlers,
        ) => {
            const controller = new AbortController();
            controllerRef.current = controller;
            assistantIdRef.current = null;
            bufferRef.current = '';
            setDraft('');
            setActivity(NO_ACTIVITY);
            setStatus('streaming');

            const parser = createParser({
                onEvent(event) {
                    const data: unknown = JSON.parse(event.data);

                    switch (event.event) {
                        case 'message.started':
                            assistantIdRef.current = (
                                data as StartedEvent
                            ).assistant_message_id;
                            handlers.onStarted?.(data as StartedEvent);
                            break;
                        case 'delta':
                            bufferRef.current += (
                                data as { text: string }
                            ).text;
                            frameRef.current ??= requestAnimationFrame(flush);
                            break;
                        case 'search':
                            setActivity((current) => ({
                                ...current,
                                searches: [
                                    ...current.searches,
                                    (data as { query?: string }).query ?? null,
                                ],
                            }));
                            break;
                        case 'source':
                            setActivity((current) => ({
                                ...current,
                                sources: [...current.sources, data as Source],
                            }));
                            break;
                        case 'message.completed':
                            handlers.onCompleted?.(data as CompletedEvent);
                            break;
                        case 'error':
                            handlers.onError?.(data as ErrorEvent);
                            break;
                    }
                },
            });

            try {
                const response = await fetch(url, {
                    method: 'POST',
                    headers: {
                        Accept: 'text/event-stream',
                        'Content-Type': 'application/json',
                        'X-XSRF-TOKEN': xsrfToken(),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify(body),
                    signal: controller.signal,
                    credentials: 'same-origin',
                });

                const isStream = (
                    response.headers.get('Content-Type') ?? ''
                ).startsWith('text/event-stream');

                if (response.status === 422) {
                    handlers.onError?.({
                        code: 'validation',
                        retryable: false,
                        message: await firstValidationError(response),
                    });

                    return;
                }

                if (!response.ok || !response.body || !isStream) {
                    handlers.onError?.({
                        code: response.status === 403 ? 'forbidden' : 'unknown',
                        retryable: false,
                    });

                    return;
                }

                const reader = response.body
                    .pipeThrough(new TextDecoderStream())
                    .getReader();

                for (;;) {
                    const { value, done } = await reader.read();

                    if (done) {
                        break;
                    }

                    parser.feed(value);
                }
            } catch (error) {
                if (!controller.signal.aborted) {
                    handlers.onError?.({ code: 'network', retryable: true });
                }

                void error;
            } finally {
                if (frameRef.current !== null) {
                    cancelAnimationFrame(frameRef.current);
                    frameRef.current = null;
                }

                setDraft(bufferRef.current);
                setStatus('idle');
                controllerRef.current = null;
                handlers.onSettled?.();
            }
        },
        [flush],
    );

    /**
     * Ask the server to stop; it settles the budget and ends the stream.
     * If it does not answer in time, drop the connection.
     */
    const stop = useCallback((cancelUrl: (id: string) => string) => {
        const controller = controllerRef.current;
        const id = assistantIdRef.current;

        if (!controller) {
            return;
        }

        if (id === null) {
            controller.abort();

            return;
        }

        void fetch(cancelUrl(id), {
            method: 'POST',
            headers: {
                'X-XSRF-TOKEN': xsrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        });

        window.setTimeout(() => controller.abort(), STOP_GRACE_MS);
    }, []);

    // Leaving the page drops the connection; the server stops and settles.
    useEffect(() => () => controllerRef.current?.abort(), []);

    return { status, draft, activity, run, stop };
}
