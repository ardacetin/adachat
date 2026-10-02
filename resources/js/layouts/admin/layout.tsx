import { Link, usePage } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import { useTranslation } from 'react-i18next';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { Skeleton } from '@/components/ui/skeleton';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { allNamespaces, useLazyNamespace } from '@/i18n';
import { cn, toUrl } from '@/lib/utils';
import { index } from '@/routes/admin';
import { index as aliases } from '@/routes/admin/aliases';
import { index as auditLog } from '@/routes/admin/audit-log';
import { edit as editAuthentication } from '@/routes/admin/authentication';
import { index as assistantsAdmin } from '@/routes/admin/assistants';
import { index as budgetPolicies } from '@/routes/admin/budget-policies';
import { index as groups } from '@/routes/admin/groups';
import { index as feedback } from '@/routes/admin/feedback';
import { edit as editInstitution } from '@/routes/admin/institution';
import { index as models } from '@/routes/admin/models';
import { edit as editPrivacy } from '@/routes/admin/privacy';
import { index as providers } from '@/routes/admin/providers';
import { index as reports } from '@/routes/admin/reports';
import { index as users } from '@/routes/admin/users';
import type { NavItem } from '@/types';

export default function AdminLayout({ children }: PropsWithChildren) {
    const ready = useLazyNamespace('admin');
    const { t } = useTranslation(allNamespaces);
    const { can } = usePage().props;
    const { isCurrentUrl, isCurrentOrParentUrl } = useCurrentUrl();

    const items: NavItem[] = [
        { titleKey: 'admin:nav.overview', href: index() },
        { titleKey: 'admin:nav.reports', href: reports() },
        { titleKey: 'admin:nav.feedback', href: feedback() },
        { titleKey: 'admin:nav.users', href: users() },
        { titleKey: 'admin:nav.groups', href: groups() },
        { titleKey: 'admin:nav.auditLog', href: auditLog() },
        ...(can.manageSystem
            ? [
                  {
                      titleKey: 'admin:nav.institution',
                      href: editInstitution(),
                  } satisfies NavItem,
                  {
                      titleKey: 'admin:nav.authentication',
                      href: editAuthentication(),
                  } satisfies NavItem,
                  {
                      titleKey: 'admin:nav.privacy',
                      href: editPrivacy(),
                  } satisfies NavItem,
                  {
                      titleKey: 'admin:nav.providers',
                      href: providers(),
                  } satisfies NavItem,
                  {
                      titleKey: 'admin:nav.models',
                      href: models(),
                  } satisfies NavItem,
                  {
                      titleKey: 'admin:nav.aliases',
                      href: aliases(),
                  } satisfies NavItem,
                  {
                      titleKey: 'admin:nav.assistants',
                      href: assistantsAdmin(),
                  } satisfies NavItem,
                  {
                      titleKey: 'admin:nav.budgetPolicies',
                      href: budgetPolicies(),
                  } satisfies NavItem,
              ]
            : []),
    ];

    if (!ready) {
        return (
            <div className="space-y-4 px-4 py-6">
                <Skeleton className="h-8 w-48" />
                <Skeleton className="h-4 w-72" />
            </div>
        );
    }

    return (
        <div className="px-4 py-6">
            <Heading
                title={t('admin:title')}
                description={t('admin:description')}
            />

            <div className="flex flex-col lg:flex-row lg:space-x-12">
                <aside className="w-full max-w-xl lg:w-48">
                    <nav
                        className="flex flex-col space-y-1 space-x-0"
                        aria-label={t('admin:title')}
                    >
                        {items.map((item) => (
                            <Button
                                key={toUrl(item.href)}
                                size="sm"
                                variant="ghost"
                                asChild
                                className={cn('w-full justify-start', {
                                    'bg-muted':
                                        item.href === items[0].href
                                            ? isCurrentUrl(item.href)
                                            : isCurrentOrParentUrl(item.href),
                                })}
                            >
                                <Link href={item.href}>{t(item.titleKey)}</Link>
                            </Button>
                        ))}
                    </nav>
                </aside>

                <Separator className="my-6 lg:hidden" />

                <div className="flex-1 md:max-w-2xl">
                    <section className="max-w-2xl space-y-12">
                        {children}
                    </section>
                </div>
            </div>
        </div>
    );
}
