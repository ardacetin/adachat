import { Link, usePage } from '@inertiajs/react';
import { ShieldCheck, SquarePen } from 'lucide-react';
import AppLogo from '@/components/app-logo';
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
import { home } from '@/routes';
import { index as adminIndex } from '@/routes/admin';
import type { NavItem } from '@/types';

export function AppSidebar() {
    const { can } = usePage().props;

    const mainNavItems: NavItem[] = [
        { titleKey: 'chat:newChat', href: home(), icon: SquarePen },
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
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
