import { ArrowUp, Paperclip, Square } from 'lucide-react';
import { useRef, useState } from 'react';
import type {
    ClipboardEvent,
    DragEvent,
    KeyboardEvent,
    ReactNode,
} from 'react';
import { useTranslation } from 'react-i18next';
import AttachmentChips from '@/components/chat/attachment-chips';
import { Button } from '@/components/ui/button';
import { IMAGE_ACCEPT, TEXT_ACCEPT } from '@/hooks/use-attachments';
import type { AttachmentItem } from '@/hooks/use-attachments';
import { cn } from '@/lib/utils';

type Props = {
    value: string;
    onChange: (value: string) => void;
    onSubmit: () => void;
    onStop: () => void;
    streaming: boolean;
    disabled?: boolean;
    /** Shown in the toolbar, e.g. the model selector. */
    toolbar?: ReactNode;
    attachments?: {
        items: AttachmentItem[];
        readyCount: number;
        uploading: boolean;
        imagesAllowed: boolean;
        onAdd: (files: File[]) => void;
        onRemove: (key: string) => void;
    };
};

export default function Composer({
    value,
    onChange,
    onSubmit,
    onStop,
    streaming,
    disabled = false,
    toolbar,
    attachments,
}: Props) {
    const { t } = useTranslation('chat');
    const textarea = useRef<HTMLTextAreaElement>(null);
    const fileInput = useRef<HTMLInputElement>(null);
    const [dragging, setDragging] = useState(false);
    const canSend =
        !streaming &&
        !disabled &&
        !attachments?.uploading &&
        (value.trim() !== '' || (attachments?.readyCount ?? 0) > 0);

    const accept = attachments?.imagesAllowed
        ? `${IMAGE_ACCEPT},${TEXT_ACCEPT}`
        : TEXT_ACCEPT;

    const onDrop = (event: DragEvent<HTMLFormElement>) => {
        setDragging(false);

        if (!attachments || disabled || event.dataTransfer.files.length === 0) {
            return;
        }

        event.preventDefault();
        attachments.onAdd(Array.from(event.dataTransfer.files));
    };

    const onPaste = (event: ClipboardEvent<HTMLTextAreaElement>) => {
        const files = Array.from(event.clipboardData.files);

        // Pasted text stays text; only pasted files (screenshots) are attached.
        if (attachments && files.length > 0) {
            event.preventDefault();
            attachments.onAdd(files);
        }
    };

    const onKeyDown = (event: KeyboardEvent<HTMLTextAreaElement>) => {
        // Enter sends, Shift+Enter adds a line; never during IME composition.
        if (
            event.key === 'Enter' &&
            !event.shiftKey &&
            !event.nativeEvent.isComposing
        ) {
            event.preventDefault();

            if (canSend) {
                onSubmit();
            }
        }
    };

    return (
        <form
            className={cn(
                'rounded-2xl border bg-background shadow-sm focus-within:ring-2 focus-within:ring-ring/40',
                dragging && 'ring-2 ring-ring/60',
            )}
            onDragOver={(event) => {
                if (attachments && !disabled) {
                    event.preventDefault();
                    setDragging(true);
                }
            }}
            onDragLeave={() => setDragging(false)}
            onDrop={onDrop}
            onSubmit={(event) => {
                event.preventDefault();

                if (canSend) {
                    onSubmit();
                }
            }}
        >
            {attachments && (
                <AttachmentChips
                    items={attachments.items}
                    onRemove={attachments.onRemove}
                />
            )}
            <label htmlFor="chat-composer" className="sr-only">
                {t('composer.label')}
            </label>
            <textarea
                ref={textarea}
                id="chat-composer"
                rows={1}
                value={value}
                onChange={(event) => onChange(event.target.value)}
                onKeyDown={onKeyDown}
                onPaste={onPaste}
                placeholder={t('composer.placeholder')}
                aria-describedby="chat-composer-hint"
                disabled={disabled}
                className="field-sizing-content max-h-60 min-h-12 w-full resize-none bg-transparent px-4 pt-3 text-base outline-none placeholder:text-muted-foreground disabled:cursor-not-allowed md:text-sm"
            />
            <div className="flex items-center justify-between gap-2 px-2 pb-2">
                <div className="flex min-w-0 items-center gap-1">
                    {attachments && (
                        <>
                            <input
                                ref={fileInput}
                                type="file"
                                multiple
                                accept={accept}
                                className="sr-only"
                                tabIndex={-1}
                                aria-hidden
                                data-test="attachment-input"
                                onChange={(event) => {
                                    attachments.onAdd(
                                        Array.from(event.target.files ?? []),
                                    );
                                    event.target.value = '';
                                }}
                            />
                            <Button
                                type="button"
                                size="icon"
                                variant="ghost"
                                className="size-8 shrink-0 rounded-full"
                                disabled={disabled || streaming}
                                onClick={() => fileInput.current?.click()}
                                aria-label={
                                    attachments.imagesAllowed
                                        ? t('attachments.add')
                                        : t('attachments.addText')
                                }
                                title={
                                    attachments.imagesAllowed
                                        ? t('attachments.add')
                                        : t('attachments.addText')
                                }
                            >
                                <Paperclip className="size-4" />
                            </Button>
                        </>
                    )}
                    <div className="min-w-0">{toolbar}</div>
                </div>
                <div className="flex items-center gap-2">
                    <span
                        id="chat-composer-hint"
                        className="hidden text-xs text-muted-foreground md:inline"
                    >
                        {t('composer.hint')}
                    </span>
                    {streaming ? (
                        <Button
                            type="button"
                            size="icon"
                            variant="secondary"
                            className="size-8 rounded-full"
                            onClick={onStop}
                            aria-label={t('composer.stop')}
                        >
                            <Square className="size-3.5 fill-current" />
                        </Button>
                    ) : (
                        <Button
                            type="submit"
                            size="icon"
                            className="size-8 rounded-full"
                            disabled={!canSend}
                            aria-label={t('composer.send')}
                        >
                            <ArrowUp className="size-4" />
                        </Button>
                    )}
                </div>
            </div>
        </form>
    );
}
