import { Link, router, usePage } from '@inertiajs/react';
import { MoreHorizontal, Pencil, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuAction,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSkeleton,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { destroy, show, update } from '@/routes/conversations';
import type { ConversationSummary } from '@/types/chat';

/**
 * Recent conversations in the sidebar. The list is a deferred prop of the
 * chat pages; other pages do not load it.
 */
export function ConversationList() {
    const { t } = useTranslation('chat');
    const { props, component } = usePage<{
        conversations?: ConversationSummary[];
    }>();
    const { isCurrentUrl } = useCurrentUrl();
    const [renaming, setRenaming] = useState<ConversationSummary | null>(null);
    const [deleting, setDeleting] = useState<ConversationSummary | null>(null);
    const [title, setTitle] = useState('');

    if (!component.startsWith('chat/')) {
        return null;
    }

    const conversations = props.conversations;

    return (
        <SidebarGroup className="px-2 py-0 group-data-[collapsible=icon]:hidden">
            <SidebarGroupLabel>{t('recent')}</SidebarGroupLabel>
            <SidebarMenu>
                {conversations === undefined
                    ? Array.from({ length: 3 }, (_, index) => (
                          <SidebarMenuItem key={index}>
                              <SidebarMenuSkeleton />
                          </SidebarMenuItem>
                      ))
                    : conversations.map((conversation) => (
                          <SidebarMenuItem key={conversation.id}>
                              <SidebarMenuButton
                                  asChild
                                  isActive={isCurrentUrl(show(conversation.id))}
                              >
                                  <Link href={show(conversation.id)} prefetch>
                                      <span className="truncate">
                                          {conversation.title ?? t('untitled')}
                                      </span>
                                  </Link>
                              </SidebarMenuButton>
                              <DropdownMenu>
                                  <DropdownMenuTrigger asChild>
                                      <SidebarMenuAction
                                          showOnHover
                                          aria-label={t('conversation.actions')}
                                      >
                                          <MoreHorizontal />
                                      </SidebarMenuAction>
                                  </DropdownMenuTrigger>
                                  <DropdownMenuContent
                                      side="right"
                                      align="start"
                                  >
                                      <DropdownMenuItem
                                          onSelect={() => {
                                              setTitle(
                                                  conversation.title ?? '',
                                              );
                                              setRenaming(conversation);
                                          }}
                                      >
                                          <Pencil />
                                          {t('conversation.rename')}
                                      </DropdownMenuItem>
                                      <DropdownMenuItem
                                          variant="destructive"
                                          onSelect={() =>
                                              setDeleting(conversation)
                                          }
                                      >
                                          <Trash2 />
                                          {t('conversation.delete')}
                                      </DropdownMenuItem>
                                  </DropdownMenuContent>
                              </DropdownMenu>
                          </SidebarMenuItem>
                      ))}
            </SidebarMenu>

            <Dialog
                open={renaming !== null}
                onOpenChange={(open) => !open && setRenaming(null)}
            >
                <DialogContent>
                    <form
                        className="space-y-4"
                        onSubmit={(event) => {
                            event.preventDefault();

                            if (renaming && title.trim() !== '') {
                                router.patch(
                                    update.url(renaming.id),
                                    { title },
                                    {
                                        preserveScroll: true,
                                        onSuccess: () => setRenaming(null),
                                    },
                                );
                            }
                        }}
                    >
                        <DialogHeader>
                            <DialogTitle>
                                {t('conversation.renameTitle')}
                            </DialogTitle>
                        </DialogHeader>
                        <Input
                            value={title}
                            onChange={(event) => setTitle(event.target.value)}
                            maxLength={255}
                            aria-label={t('conversation.renameTitle')}
                            autoFocus
                        />
                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setRenaming(null)}
                            >
                                {t('conversation.cancel')}
                            </Button>
                            <Button type="submit">
                                {t('conversation.save')}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog
                open={deleting !== null}
                onOpenChange={(open) => !open && setDeleting(null)}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {t('conversation.deleteTitle')}
                        </DialogTitle>
                        <DialogDescription>
                            {t('conversation.deleteConfirm')}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setDeleting(null)}
                        >
                            {t('conversation.cancel')}
                        </Button>
                        <Button
                            type="button"
                            variant="destructive"
                            onClick={() => {
                                if (deleting) {
                                    router.delete(destroy.url(deleting.id), {
                                        onSuccess: () => setDeleting(null),
                                    });
                                }
                            }}
                        >
                            {t('conversation.delete')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </SidebarGroup>
    );
}
