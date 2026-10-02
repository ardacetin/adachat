import { Head } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import ChatView from '@/components/chat/chat-view';
import type { AliasOption, AssistantSummary } from '@/types/chat';

type Props = {
    aliases: AliasOption[];
    /** Set when a new conversation is started with an assistant. */
    assistant?: AssistantSummary;
};

export default function ChatIndex({ aliases, assistant }: Props) {
    const { t } = useTranslation('chat');

    return (
        <>
            <Head title={assistant?.name ?? t('newChat')} />
            <ChatView
                key={assistant?.slug ?? 'new'}
                conversationId={null}
                conversationAliasId={null}
                messages={[]}
                aliases={aliases}
                assistant={assistant ?? null}
            />
        </>
    );
}
