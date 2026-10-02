import { Link } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import { useTranslation } from 'react-i18next';
import { allNamespaces } from '@/i18n';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn, toUrl } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editLanguage } from '@/routes/language';
import { edit as editNotifications } from '@/routes/notifications';
import { edit as editProfile } from '@/routes/profile';
import type { NavItem } from '@/types';

const sidebarNavItems: NavItem[] = [
    {
        titleKey: 'settings:nav.profile',
        href: editProfile(),
        icon: null,
    },
    {
        titleKey: 'settings:nav.language',
        href: editLanguage(),
        icon: null,
    },
    {
        titleKey: 'settings:nav.notifications',
        href: editNotifications(),
        icon: null,
    },
    {
        titleKey: 'settings:nav.appearance',
        href: editAppearance(),
        icon: null,
    },
];

export default function SettingsLayout({ children }: PropsWithChildren) {
    const { t } = useTranslation(allNamespaces);
    const { isCurrentOrParentUrl } = useCurrentUrl();

    return (
        <div className="px-4 py-6">
            <Heading
                title={t('settings:title')}
                description={t('settings:description')}
            />

            <div className="flex flex-col lg:flex-row lg:space-x-12">
                <aside className="w-full max-w-xl lg:w-48">
                    <nav
                        className="flex flex-col space-y-1 space-x-0"
                        aria-label={t('settings:title')}
                    >
                        {sidebarNavItems.map((item, index) => (
                            <Button
                                key={`${toUrl(item.href)}-${index}`}
                                size="sm"
                                variant="ghost"
                                asChild
                                className={cn('w-full justify-start', {
                                    'bg-muted': isCurrentOrParentUrl(item.href),
                                })}
                            >
                                <Link href={item.href}>
                                    {item.icon && (
                                        <item.icon className="h-4 w-4" />
                                    )}
                                    {t(item.titleKey)}
                                </Link>
                            </Button>
                        ))}
                    </nav>
                </aside>

                <Separator className="my-6 lg:hidden" />

                <div className="flex-1 md:max-w-2xl">
                    <section className="max-w-xl space-y-12">
                        {children}
                    </section>
                </div>
            </div>
        </div>
    );
}
