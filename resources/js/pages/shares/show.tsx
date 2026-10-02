import { Form, Head, usePage } from '@inertiajs/react';
import { Copy, Paperclip } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import Markdown from '@/components/chat/markdown';
import { Sources } from '@/components/chat/message-item';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { formatMoment } from '@/lib/format';
import { copy } from '@/routes/shares';
import type { SharedMessage } from '@/types/chat';

type Props = {
    share: {
        token: string;
        title: string | null;
        shared_at: string | null;
        by: string;
        own: boolean;
    };
    messages: SharedMessage[];
};

/**
 * A shared conversation, read-only, as it was when it was shared. Attached
 * files are listed by name; they are never served to the viewer.
 */
export default function SharedConversation({ share, messages }: Props) {
    const { t } = useTranslation('chat');
    const { locale } = usePage().props;
    const title = share.title ?? t('untitled');

    return (
        <>
            <Head title={title}>
                <meta name="robots" content="noindex, nofollow" />
            </Head>

            <div className="mx-auto w-full max-w-3xl space-y-6 px-4 py-8">
                <header className="space-y-2">
                    <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                        {t('shared.label')}
                    </p>
                    <h1 className="text-xl font-semibold">{title}</h1>
                    {share.shared_at && (
                        <p className="text-sm text-muted-foreground">
                            {t('shared.by', {
                                name: share.by,
                                date: formatMoment(
                                    share.shared_at,
                                    locale.current,
                                ),
                            })}
                        </p>
                    )}
                    <Form {...copy.form(share.token)} className="print:hidden">
                        {({ processing }) => (
                            <Button
                                type="submit"
                                size="sm"
                                disabled={processing}
                                data-test="share-copy"
                            >
                                <Copy />
                                {t('shared.copy')}
                            </Button>
                        )}
                    </Form>
                    <Alert>
                        <AlertDescription>
                            {share.own ? t('shared.own') : t('shared.readOnly')}
                        </AlertDescription>
                    </Alert>
                </header>

                <ol className="flex flex-col gap-6" data-test="shared-messages">
                    {messages.map((message, index) => (
                        <li key={index}>
                            {message.role === 'user' ? (
                                <div className="flex flex-col items-end gap-2">
                                    {message.attachments.length > 0 && (
                                        <p className="flex max-w-[85%] items-center gap-1.5 text-xs text-muted-foreground">
                                            <Paperclip className="size-3.5 shrink-0" />
                                            {t('shared.attachments', {
                                                names: message.attachments.join(
                                                    ', ',
                                                ),
                                            })}
                                        </p>
                                    )}
                                    {message.content !== '' && (
                                        <div className="max-w-[85%] rounded-2xl bg-muted px-4 py-2.5 break-words whitespace-pre-wrap">
                                            <span className="sr-only">
                                                {t('message.you')}:{' '}
                                            </span>
                                            {message.content}
                                        </div>
                                    )}
                                </div>
                            ) : (
                                <div className="flex flex-col gap-2">
                                    <span className="text-xs text-muted-foreground">
                                        <span className="sr-only">
                                            {t('message.assistant')}:{' '}
                                        </span>
                                        {message.model}
                                    </span>
                                    <Markdown content={message.content} />
                                    {message.sources.length > 0 && (
                                        <Sources sources={message.sources} />
                                    )}
                                </div>
                            )}
                        </li>
                    ))}
                </ol>
            </div>
        </>
    );
}
