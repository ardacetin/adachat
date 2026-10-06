import { Link, router, usePage } from '@inertiajs/react';
import { Bot, Search, ShieldCheck, SquarePen } from 'lucide-react';
import { useEffect } from 'react';
import AppLogo from '@/components/app-logo';
import BudgetIndicator from '@/components/budget/budget-indicator';
import { ConversationList } from '@/components/chat/conversation-list';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { home, search } from '@/routes';
import { index as assistants } from '@/routes/assistants';
import { index as adminIndex } from '@/routes/admin';
import type { NavItem } from '@/types';

export function AppSidebar() {
    const { can } = usePage().props;

    // Ctrl+K / ⌘K opens the conversation search from anywhere. On a Mac only
    // ⌘K: ⌃K deletes to the end of the line in text fields.
    useEffect(() => {
        const mac = /Mac|iPhone|iPad/.test(navigator.platform);
        const open = (event: KeyboardEvent) => {
            if (
                (mac ? event.metaKey : event.ctrlKey) &&
                event.key.toLowerCase() === 'k'
            ) {
                event.preventDefault();
                router.visit(search());
            }
        };

        window.addEventListener('keydown', open);

        return () => window.removeEventListener('keydown', open);
    }, []);

    const mainNavItems: NavItem[] = [
        { titleKey: 'chat:newChat', href: home(), icon: SquarePen },
        { titleKey: 'chat:assistants.nav', href: assistants(), icon: Bot },
        { titleKey: 'chat:search.nav', href: search(), icon: Search },
        ...(can.accessAdmin
            ? [
                  {
                      titleKey: 'nav.admin',
                      href: adminIndex(),
                      icon: ShieldCheck,
                  } satisfies NavItem,
              ]
            : []),
    ];

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={home()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
                <ConversationList />
            </SidebarContent>

            <SidebarFooter>
                <BudgetIndicator />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
