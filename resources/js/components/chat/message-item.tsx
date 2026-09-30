import { Check, Copy, RefreshCw } from 'lucide-react';
import { memo } from 'react';
import { useTranslation } from 'react-i18next';
import Markdown from '@/components/chat/markdown';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useClipboard } from '@/hooks/use-clipboard';
import { cn } from '@/lib/utils';
import type { ChatMessage } from '@/types/chat';

type Props = {
    message: ChatMessage;
    streaming?: boolean;
    onRegenerate?: () => void;
};

function MessageItem({ message, streaming = false, onRegenerate }: Props) {
    const { t } = useTranslation('chat');
    const [copied, copy] = useClipboard();

    if (message.role === 'user') {
        return (
            <div className="flex justify-end">
                <div className="max-w-[85%] rounded-2xl bg-muted px-4 py-2.5 break-words whitespace-pre-wrap">
                    <span className="sr-only">{t('message.you')}: </span>
                    {message.content}
                </div>
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

export default memo(MessageItem);
