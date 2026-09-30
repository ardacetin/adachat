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
import { edit as editAuthentication } from '@/routes/admin/authentication';
import { edit as editInstitution } from '@/routes/admin/institution';

export default function AdminIndex() {
    const { t } = useTranslation('admin');
    const { can } = usePage().props;

    return (
        <>
            <Head title={t('title')} />

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={t('nav.overview')}
                    description={t('index.intro')}
                />

                {can.manageSystem && (
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Link href={editInstitution()} className="rounded-xl">
                            <Card className="h-full transition-colors hover:bg-muted/50">
                                <CardHeader>
                                    <CardTitle>
                                        {t('institution.title')}
                                    </CardTitle>
                                    <CardDescription>
                                        {t('institution.description')}
                                    </CardDescription>
                                </CardHeader>
                            </Card>
                        </Link>
                        <Link
                            href={editAuthentication()}
                            className="rounded-xl"
                        >
                            <Card className="h-full transition-colors hover:bg-muted/50">
                                <CardHeader>
                                    <CardTitle>
                                        {t('authentication.title')}
                                    </CardTitle>
                                    <CardDescription>
                                        {t('authentication.description')}
                                    </CardDescription>
                                </CardHeader>
                            </Card>
                        </Link>
                    </div>
                )}

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
