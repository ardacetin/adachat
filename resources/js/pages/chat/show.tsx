import { Head } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import ChatView from '@/components/chat/chat-view';
import type { AliasOption, ChatMessage } from '@/types/chat';

type Props = {
    conversation: {
        id: string;
        title: string | null;
        model_alias_id: number | null;
    };
    messages: ChatMessage[];
    aliases: AliasOption[];
};

export default function ChatShow({ conversation, messages, aliases }: Props) {
    const { t } = useTranslation('chat');

    return (
        <>
            <Head title={conversation.title ?? t('untitled')} />
            <ChatView
                key={conversation.id}
                conversationId={conversation.id}
                conversationAliasId={conversation.model_alias_id}
                messages={messages}
                aliases={aliases}
            />
        </>
    );
}
