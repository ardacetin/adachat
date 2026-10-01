import { Check, Copy, FileText, RefreshCw } from 'lucide-react';
import { memo } from 'react';
import { useTranslation } from 'react-i18next';
import Markdown from '@/components/chat/markdown';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useClipboard } from '@/hooks/use-clipboard';
import { cn } from '@/lib/utils';
import type { AttachmentInfo, ChatMessage } from '@/types/chat';

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

    return (
        <div className="group/message flex flex-col gap-2">
            <span className="sr-only">{t('message.assistant')}:</span>

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
                            <FileText className="size-4 text-muted-foreground" />
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
