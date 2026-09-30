import { Head, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Heading from '@/components/heading';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/profile';

export default function Profile() {
    const { t } = useTranslation('settings');
    const { auth } = usePage().props;

    return (
        <>
            <Head title={t('profile.title')} />

            <h1 className="sr-only">{t('profile.title')}</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={t('profile.title')}
                    description={t('profile.description')}
                />

                <div className="grid gap-2">
                    <Label htmlFor="name">{t('profile.name')}</Label>
                    <Input id="name" value={auth.user.name} readOnly />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="email">{t('profile.email')}</Label>
                    <Input
                        id="email"
                        type="email"
                        value={auth.user.email}
                        readOnly
                    />
                </div>
            </div>
        </>
    );
}

Profile.layout = {
    breadcrumbs: [
        {
            titleKey: 'settings:profile.title',
            href: edit(),
        },
    ],
};
