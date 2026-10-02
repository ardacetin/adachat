import { Check, Copy, Globe, RefreshCw } from 'lucide-react';
import { memo } from 'react';
import { useTranslation } from 'react-i18next';
import AnswerFeedback from '@/components/chat/answer-feedback';
import FileIcon from '@/components/chat/file-icon';
import Markdown from '@/components/chat/markdown';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useClipboard } from '@/hooks/use-clipboard';
import { cn } from '@/lib/utils';
import type { AttachmentInfo, ChatMessage, Source } from '@/types/chat';

type Props = {
    message: ChatMessage;
    streaming?: boolean;
    onRegenerate?: () => void;
};

function MessageItem({ message, streaming = false, onRegenerate }: Props) {
    const { t } = useTranslation('chat');
    const [copied, copy] = useClipboard();

    if (message.role === 'user') {
        const files = message.attachments ?? [];

        return (
            <div className="flex flex-col items-end gap-2">
                {files.length > 0 && <Attachments files={files} />}
                {message.content !== '' && (
                    <div className="max-w-[85%] rounded-2xl bg-muted px-4 py-2.5 break-words whitespace-pre-wrap">
                        <span className="sr-only">{t('message.you')}: </span>
                        {message.content}
                    </div>
                )}
            </div>
        );
    }

    const failed = message.status === 'failed';
    const waiting = streaming && message.content === '';
    const searches = streaming ? (message.searches ?? []) : [];
    const sources = message.sources ?? [];

    return (
        <div className="group/message flex flex-col gap-2">
            <span className="sr-only">{t('message.assistant')}:</span>

            {searches.length > 0 && (
                <ul
                    className="flex flex-col gap-1 text-sm text-muted-foreground"
                    data-test="web-searches"
                >
                    {searches.map((query, index) => (
                        <li key={index} className="flex items-center gap-2">
                            <Globe className="size-3.5 shrink-0" />
                            <span className="truncate">
                                {query
                                    ? t('webSearch.searching', { query })
                                    : t('webSearch.searchingUnknown')}
                            </span>
                        </li>
                    ))}
                </ul>
            )}

            {waiting ? (
                <div
                    className="flex items-center gap-2 text-sm text-muted-foreground"
                    role="status"
                >
                    <Spinner className="size-4" />
                    {t('message.generating')}
                </div>
            ) : (
                <Markdown content={message.content} streaming={streaming} />
            )}

            {sources.length > 0 && <Sources sources={sources} />}

            {message.output_capped && (
                <p className="text-xs text-muted-foreground">
                    {t('message.capped')}
                </p>
            )}
            {message.finish_reason === 'length' && !streaming && (
                <p className="text-xs text-muted-foreground">
                    {t('message.truncated')}
                </p>
            )}
            {message.status === 'cancelled' && (
                <p className="text-xs text-muted-foreground">
                    {t('message.cancelled')}
                </p>
            )}
            {failed && (
                <p className="text-sm text-destructive" role="alert">
                    {t(`errors.${message.error_code ?? 'unknown'}`, {
                        defaultValue: t('errors.unknown'),
                    })}
                </p>
            )}

            {!streaming && message.content !== '' && (
                <div
                    className={cn(
                        'flex gap-1 text-muted-foreground opacity-100 transition-opacity',
                        'md:opacity-0 md:group-hover/message:opacity-100 md:focus-within:opacity-100',
                    )}
                    data-print-hide
                >
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="size-8"
                        onClick={() => void copy(message.content)}
                        aria-label={
                            copied === message.content
                                ? t('message.copied')
                                : t('message.copy')
                        }
                    >
                        {copied === message.content ? (
                            <Check className="size-4" />
                        ) : (
                            <Copy className="size-4" />
                        )}
                    </Button>
                    {message.status === 'completed' &&
                        !message.id.startsWith('pending-') && (
                            <AnswerFeedback
                                messageId={message.id}
                                initial={message.feedback ?? null}
                            />
                        )}
                    {onRegenerate && (
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="size-8"
                            onClick={onRegenerate}
                            aria-label={t('message.regenerate')}
                        >
                            <RefreshCw className="size-4" />
                        </Button>
                    )}
                </div>
            )}
        </div>
    );
}

function hostOf(url: string): string {
    try {
        return new URL(url).hostname.replace(/^www\./, '');
    } catch {
        return url;
    }
}

/** The web pages an answer is based on, numbered, opening in a new tab. */
function Sources({ sources }: { sources: Source[] }) {
    const { t } = useTranslation('chat');

    return (
        <section
            className="rounded-xl border bg-muted/30 px-3 py-2"
            aria-label={t('webSearch.sources')}
            data-test="sources"
        >
            <h3 className="mb-1 text-xs font-medium text-muted-foreground">
                {t('webSearch.sources')}
            </h3>
            <ol className="flex flex-col gap-1 text-sm">
                {sources.map((source, index) => {
                    const host = hostOf(source.url);
                    const title = source.title ?? host;

                    return (
                        <li key={source.url} className="flex min-w-0 gap-2">
                            <span className="shrink-0 text-muted-foreground tabular-nums">
                                {index + 1}.
                            </span>
                            <a
                                href={source.url}
                                target="_blank"
                                rel="noopener noreferrer nofollow"
                                className="min-w-0 truncate underline-offset-4 hover:underline"
                                aria-label={t('webSearch.sourceLink', {
                                    title,
                                })}
                            >
                                {title}
                                {source.title && (
                                    <span className="ml-1.5 text-xs text-muted-foreground">
                                        {host}
                                    </span>
                                )}
                            </a>
                        </li>
                    );
                })}
            </ol>
        </section>
    );
}

/** Images as thumbnails (open in a new tab), other files as download chips. */
function Attachments({ files }: { files: AttachmentInfo[] }) {
    const { t } = useTranslation('chat');

    return (
        <ul
            className="flex max-w-[85%] flex-wrap justify-end gap-2"
            aria-label={t('attachments.list')}
        >
            {files.map((file) => (
                <li key={file.id}>
                    {file.kind === 'image' ? (
                        <a
                            href={file.url}
                            target="_blank"
                            rel="noopener"
                            className="block overflow-hidden rounded-xl border"
                        >
                            <img
                                src={file.url}
                                alt={file.name}
                                loading="lazy"
                                className="max-h-48 max-w-64 object-cover"
                            />
                        </a>
                    ) : (
                        <a
                            href={file.url}
                            download={file.name}
                            className="flex items-center gap-2 rounded-xl border bg-muted/50 px-3 py-2 text-sm hover:bg-muted"
                        >
                            <FileIcon mime={file.mime} />
                            <span className="max-w-48 truncate">
                                {file.name}
                            </span>
                        </a>
                    )}
                </li>
            ))}
        </ul>
    );
}

export default memo(MessageItem);
