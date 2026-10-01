import { usePage } from '@inertiajs/react';
import { X } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import FileIcon from '@/components/chat/file-icon';
import { Spinner } from '@/components/ui/spinner';
import type { AttachmentItem } from '@/hooks/use-attachments';
import { formatNumber } from '@/lib/format';
import { cn } from '@/lib/utils';

type Props = {
    items: AttachmentItem[];
    onRemove: (key: string) => void;
};

/** The files attached in the composer, before sending. */
export default function AttachmentChips({ items, onRemove }: Props) {
    const { t } = useTranslation('chat');
    const { locale } = usePage().props;

    if (items.length === 0) {
        return null;
    }

    return (
        <div className="px-3 pt-3">
            <ul
                className="flex flex-wrap gap-2"
                aria-label={t('attachments.list')}
            >
                {items.map((item) => (
                    <li
                        key={item.key}
                        data-test="attachment-chip"
                        className={cn(
                            'flex max-w-full items-center gap-2 rounded-lg border bg-muted/50 py-1 pr-1 pl-1 text-xs',
                            item.status === 'error' &&
                                'border-destructive/50 bg-destructive/5',
                        )}
                    >
                        {item.kind === 'image' && item.info ? (
                            <img
                                src={item.info.url}
                                alt=""
                                className="size-8 rounded object-cover"
                            />
                        ) : (
                            <span className="flex size-8 items-center justify-center rounded bg-background">
                                {item.status === 'uploading' ? (
                                    <Spinner className="size-4" />
                                ) : (
                                    <FileIcon mime={item.info?.mime} />
                                )}
                            </span>
                        )}
                        <span className="flex min-w-0 flex-col">
                            <span className="max-w-48 truncate font-medium">
                                {item.name}
                            </span>
                            <span
                                className={cn(
                                    'max-w-64 truncate text-muted-foreground',
                                    item.status === 'error' &&
                                        'text-destructive',
                                )}
                                role={
                                    item.status === 'error'
                                        ? 'alert'
                                        : undefined
                                }
                            >
                                {item.status === 'uploading' &&
                                    t('attachments.uploading')}
                                {item.status === 'ready' &&
                                    item.info &&
                                    (item.info.pages
                                        ? `${t('attachments.pages', { count: item.info.pages })} · `
                                        : '') +
                                        t('attachments.tokens', {
                                            count: item.info.token_estimate,
                                            formatted: formatNumber(
                                                item.info.token_estimate,
                                                locale.current,
                                            ),
                                        })}
                                {item.status === 'error' && item.error}
                            </span>
                        </span>
                        <button
                            type="button"
                            onClick={() => onRemove(item.key)}
                            className="rounded p-1 text-muted-foreground hover:bg-background hover:text-foreground"
                            aria-label={t('attachments.remove', {
                                name: item.name,
                            })}
                        >
                            <X className="size-3.5" />
                        </button>
                    </li>
                ))}
            </ul>
            <p className="mt-1.5 text-xs text-muted-foreground">
                {t('attachments.resent')}
            </p>
        </div>
    );
}
