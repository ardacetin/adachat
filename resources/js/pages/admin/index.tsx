import { Head, Link, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Heading from '@/components/heading';
import {
    Card,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { index } from '@/routes/admin';
import { index as aliases } from '@/routes/admin/aliases';
import { index as auditLog } from '@/routes/admin/audit-log';
import { edit as editAuthentication } from '@/routes/admin/authentication';
import { index as budgetPolicies } from '@/routes/admin/budget-policies';
import { index as groups } from '@/routes/admin/groups';
import { edit as editInstitution } from '@/routes/admin/institution';
import { index as models } from '@/routes/admin/models';
import { index as providers } from '@/routes/admin/providers';
import { index as users } from '@/routes/admin/users';

/** Open to administrators and super administrators. */
const peopleSections = [
    { key: 'users', href: users() },
    { key: 'groups', href: groups() },
    { key: 'auditLog', href: auditLog() },
] as const;

/** System configuration: super administrators only. */
const sections = [
    { key: 'institution', href: editInstitution() },
    { key: 'authentication', href: editAuthentication() },
    { key: 'providers', href: providers() },
    { key: 'models', href: models() },
    { key: 'aliases', href: aliases() },
    { key: 'budgetPolicies', href: budgetPolicies() },
] as const;

export default function AdminIndex() {
    const { t } = useTranslation('admin');
    const { can } = usePage().props;
    const visible = [...peopleSections, ...(can.manageSystem ? sections : [])];

    return (
        <>
            <Head title={t('title')} />

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={t('nav.overview')}
                    description={t('index.intro')}
                />

                <div className="grid gap-4 sm:grid-cols-2">
                    {visible.map((section) => (
                        <Link
                            key={section.key}
                            href={section.href}
                            className="rounded-xl"
                        >
                            <Card className="h-full transition-colors hover:bg-muted/50">
                                <CardHeader>
                                    <CardTitle>
                                        {t(`${section.key}.title`)}
                                    </CardTitle>
                                    <CardDescription>
                                        {t(`${section.key}.description`)}
                                    </CardDescription>
                                </CardHeader>
                            </Card>
                        </Link>
                    ))}
                </div>

                <p className="text-sm text-muted-foreground">
                    {t('index.comingSoon')}
                </p>
            </div>
        </>
    );
}

AdminIndex.layout = {
    breadcrumbs: [{ titleKey: 'admin:title', href: index() }],
};
