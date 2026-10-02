import { router, usePage } from '@inertiajs/react';
import { Check, Copy, Link2, Share2 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useClipboard } from '@/hooks/use-clipboard';
import { formatMoment } from '@/lib/format';
import { xsrfToken } from '@/lib/xsrf';
import { store } from '@/routes/conversations/shares';
import { destroy } from '@/routes/shares';
import type { ShareLink } from '@/types/chat';

type Props = {
    conversationId: string;
    links: ShareLink[];
};

/**
 * Creates read-only links to the conversation and lists the live ones. A
 * new link is shown once: only its hash is kept on the server.
 */
export default function ShareDialog({ conversationId, links }: Props) {
    const { t } = useTranslation('chat');
    const { locale } = usePage().props;
    const [copied, copy] = useClipboard();
    const [url, setUrl] = useState<string | null>(null);
    const [creating, setCreating] = useState(false);

    const create = async () => {
        setCreating(true);

        try {
            const response = await fetch(store.url(conversationId), {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': xsrfToken(),
                },
            });

            if (!response.ok) {
                throw new Error(String(response.status));
            }

            const data = (await response.json()) as { url: string };
            setUrl(data.url);
            router.reload({ only: ['sharing'] });
        } catch {
            toast.error(t('conversation.share.failed'));
        } finally {
            setCreating(false);
        }
    };

    const revoke = (link: ShareLink) =>
        router.delete(destroy.url(link.id), {
            preserveScroll: true,
            only: ['sharing'],
            onSuccess: () => toast.success(t('conversation.share.revoked')),
        });

    return (
        <Dialog onOpenChange={(open) => !open && setUrl(null)}>
            <DialogTrigger asChild>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    aria-label={t('conversation.share.button')}
                    title={t('conversation.share.button')}
                    data-test="share-open"
                >
                    <Share2 />
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{t('conversation.share.title')}</DialogTitle>
                    <DialogDescription>
                        {t('conversation.share.description')}
                    </DialogDescription>
                </DialogHeader>

                {url ? (
                    <div className="space-y-2">
                        <p className="text-sm text-muted-foreground">
                            {t('conversation.share.newLink')}
                        </p>
                        <div className="flex gap-2">
                            <Input
                                readOnly
                                value={url}
                                aria-label={t('conversation.share.copy')}
                                data-test="share-url"
                                onFocus={(event) => event.target.select()}
                            />
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => void copy(url)}
                            >
                                {copied === url ? <Check /> : <Copy />}
                                {copied === url
                                    ? t('conversation.share.copied')
                                    : t('conversation.share.copy')}
                            </Button>
                        </div>
                    </div>
                ) : (
                    <Button
                        type="button"
                        onClick={() => void create()}
                        disabled={creating}
                        data-test="share-create"
                    >
                        <Link2 />
                        {creating
                            ? t('conversation.share.creating')
                            : t('conversation.share.create')}
                    </Button>
                )}

                <section className="space-y-2">
                    <h3 className="text-sm font-medium">
                        {t('conversation.share.links')}
                    </h3>
                    {links.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            {t('conversation.share.none')}
                        </p>
                    ) : (
                        <ul
                            className="divide-y rounded-md border"
                            data-test="share-links"
                        >
                            {links.map((link) => (
                                <li
                                    key={link.id}
                                    className="flex items-center justify-between gap-3 px-3 py-2 text-sm"
                                >
                                    <span>
                                        {link.created_at &&
                                            t('conversation.share.created', {
                                                date: formatMoment(
                                                    link.created_at,
                                                    locale.current,
                                                ),
                                            })}
                                        <span className="ml-2 text-muted-foreground">
                                            {t('conversation.share.views', {
                                                count: link.view_count,
                                            })}
                                        </span>
                                    </span>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => revoke(link)}
                                    >
                                        {t('conversation.share.revoke')}
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </DialogContent>
        </Dialog>
    );
}
