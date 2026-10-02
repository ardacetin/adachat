import { Head, router } from '@inertiajs/react';
import { Download, Pin, PinOff, Printer } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import ChatView from '@/components/chat/chat-view';
import ShareDialog from '@/components/chat/share-dialog';
import { Button } from '@/components/ui/button';
import { exportMethod, update } from '@/routes/conversations';
import type {
    AliasOption,
    AssistantSummary,
    ChatMessage,
    ShareLink,
} from '@/types/chat';

type Props = {
    conversation: {
        id: string;
        title: string | null;
        model_alias_id: number | null;
        pinned: boolean;
    };
    messages: ChatMessage[];
    aliases: AliasOption[];
    assistant: AssistantSummary | null;
    /** Null when the institution does not allow sharing. */
    sharing: { links: ShareLink[] } | null;
};

export default function ChatShow({
    conversation,
    messages,
    aliases,
    assistant,
    sharing,
}: Props) {
    const { t } = useTranslation('chat');
    const title = conversation.title ?? t('untitled');

    return (
        <>
            <Head title={title} />
            <div className="mx-auto flex w-full max-w-3xl items-center gap-1 px-4 pt-4">
                <h1 className="min-w-0 flex-1 truncate text-sm font-medium print:text-xl print:whitespace-normal">
                    {title}
                    {assistant && (
                        <span
                            className="ml-2 text-xs font-normal text-muted-foreground"
                            data-test="conversation-assistant"
                        >
                            · {assistant.name}
                        </span>
                    )}
                </h1>
                <div
                    className="flex gap-1 print:hidden"
                    data-test="conversation-toolbar"
                >
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        aria-label={
                            conversation.pinned
                                ? t('conversation.unpin')
                                : t('conversation.pin')
                        }
                        title={
                            conversation.pinned
                                ? t('conversation.unpin')
                                : t('conversation.pin')
                        }
                        aria-pressed={conversation.pinned}
                        onClick={() =>
                            router.patch(
                                update.url(conversation.id),
                                { pinned: !conversation.pinned },
                                {
                                    preserveScroll: true,
                                    only: ['conversation', 'conversations'],
                                },
                            )
                        }
                    >
                        {conversation.pinned ? <PinOff /> : <Pin />}
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon"
                        asChild
                        title={t('conversation.exportMarkdown')}
                    >
                        <a
                            href={exportMethod.url(conversation.id)}
                            download
                            aria-label={t('conversation.exportMarkdown')}
                        >
                            <Download />
                        </a>
                    </Button>
                    {sharing && (
                        <ShareDialog
                            conversationId={conversation.id}
                            links={sharing.links}
                        />
                    )}
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        aria-label={t('conversation.print')}
                        title={t('conversation.print')}
                        onClick={() => window.print()}
                    >
                        <Printer />
                    </Button>
                </div>
            </div>
            <ChatView
                key={conversation.id}
                conversationId={conversation.id}
                conversationAliasId={conversation.model_alias_id}
                messages={messages}
                aliases={aliases}
                assistant={assistant}
            />
        </>
    );
}
