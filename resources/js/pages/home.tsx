import { Head, Link, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { home } from '@/routes';
import { edit as editProfile } from '@/routes/profile';

export default function Home() {
    const { t } = useTranslation();
    const { auth } = usePage().props;

    return (
        <>
            <Head title={t('home.title')} />

            <div className="flex h-full flex-1 flex-col gap-6 p-6">
                <Heading
                    title={t('home.welcome', { name: auth.user.name })}
                    description={t('home.intro')}
                />

                <div>
                    <Button asChild variant="outline">
                        <Link href={editProfile()}>
                            {t('home.openSettings')}
                        </Link>
                    </Button>
                </div>
            </div>
        </>
    );
}

Home.layout = {
    breadcrumbs: [
        {
            titleKey: 'nav.home',
            href: home(),
        },
    ],
};
