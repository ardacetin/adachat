import { Head } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import ChatView from '@/components/chat/chat-view';
import type { AliasOption } from '@/types/chat';

export default function ChatIndex({ aliases }: { aliases: AliasOption[] }) {
    const { t } = useTranslation('chat');

    return (
        <>
            <Head title={t('newChat')} />
            <ChatView
                conversationId={null}
                conversationAliasId={null}
                messages={[]}
                aliases={aliases}
            />
        </>
    );
}
