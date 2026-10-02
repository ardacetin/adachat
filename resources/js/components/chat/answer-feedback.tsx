import { router } from '@inertiajs/react';
import { ThumbsDown, ThumbsUp } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';
import { feedback as feedbackRoute } from '@/routes/messages';
import type { FeedbackReason, MessageFeedback } from '@/types/chat';

const REASONS: FeedbackReason[] = [
    'inaccurate',
    'unhelpful',
    'incomplete',
    'too_long',
    'other',
];

/**
 * Thumbs up / down on an answer; a thumbs down asks for a reason. Clicking
 * the chosen thumb again takes the vote back. Administrators only see
 * totals, never the answer (docs/feedback.md).
 */
export default function AnswerFeedback({
    messageId,
    initial,
}: {
    messageId: string;
    initial: MessageFeedback | null;
}) {
    const { t } = useTranslation('chat');
    const [current, setCurrent] = useState(initial);

    const save = (next: MessageFeedback | null) => {
        const previous = current;
        setCurrent(next);
        router.put(
            feedbackRoute.url(messageId),
            { rating: next?.rating ?? null, reason: next?.reason ?? null },
            {
                preserveScroll: true,
                preserveState: true,
                only: [],
                onError: () => setCurrent(previous),
            },
        );
    };

    const up = current?.rating === 'up';
    const down = current?.rating === 'down';

    return (
        <>
            <Button
                type="button"
                variant="ghost"
                size="icon"
                className={cn('size-8', up && 'text-primary')}
                aria-pressed={up}
                aria-label={t('feedback.up')}
                onClick={() => save(up ? null : { rating: 'up', reason: null })}
                data-test="feedback-up"
            >
                <ThumbsUp className={cn('size-4', up && 'fill-current')} />
            </Button>
            {down ? (
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="size-8 text-primary"
                    aria-pressed
                    aria-label={t('feedback.downGiven', {
                        reason: t(
                            `feedback.reasons.${current.reason ?? 'other'}`,
                        ),
                    })}
                    onClick={() => save(null)}
                    data-test="feedback-down"
                >
                    <ThumbsDown className="size-4 fill-current" />
                </Button>
            ) : (
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="size-8"
                            aria-pressed={false}
                            aria-label={t('feedback.down')}
                            data-test="feedback-down"
                        >
                            <ThumbsDown className="size-4" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="start">
                        <DropdownMenuLabel>
                            {t('feedback.why')}
                        </DropdownMenuLabel>
                        {REASONS.map((reason) => (
                            <DropdownMenuItem
                                key={reason}
                                onSelect={() =>
                                    save({ rating: 'down', reason })
                                }
                            >
                                {t(`feedback.reasons.${reason}`)}
                            </DropdownMenuItem>
                        ))}
                    </DropdownMenuContent>
                </DropdownMenu>
            )}
        </>
    );
}
