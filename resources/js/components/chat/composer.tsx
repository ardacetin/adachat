import { ArrowUp, Square } from 'lucide-react';
import { useRef } from 'react';
import type { KeyboardEvent, ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';

type Props = {
    value: string;
    onChange: (value: string) => void;
    onSubmit: () => void;
    onStop: () => void;
    streaming: boolean;
    disabled?: boolean;
    /** Shown in the toolbar, e.g. the model selector. */
    toolbar?: ReactNode;
};

export default function Composer({
    value,
    onChange,
    onSubmit,
    onStop,
    streaming,
    disabled = false,
    toolbar,
}: Props) {
    const { t } = useTranslation('chat');
    const textarea = useRef<HTMLTextAreaElement>(null);
    const canSend = !streaming && !disabled && value.trim() !== '';

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
            className="rounded-2xl border bg-background shadow-sm focus-within:ring-2 focus-within:ring-ring/40"
            onSubmit={(event) => {
                event.preventDefault();

                if (canSend) {
                    onSubmit();
                }
            }}
        >
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
                placeholder={t('composer.placeholder')}
                aria-describedby="chat-composer-hint"
                disabled={disabled}
                className="field-sizing-content max-h-60 min-h-12 w-full resize-none bg-transparent px-4 pt-3 text-base outline-none placeholder:text-muted-foreground disabled:cursor-not-allowed md:text-sm"
            />
            <div className="flex items-center justify-between gap-2 px-2 pb-2">
                <div className="min-w-0">{toolbar}</div>
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
