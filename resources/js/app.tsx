import { createInertiaApp } from '@inertiajs/react';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import { initI18n } from '@/i18n';
import AdminLayout from '@/layouts/admin/layout';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import LandingLayout from '@/layouts/landing-layout';
import SettingsLayout from '@/layouts/settings/layout';

const appName = import.meta.env.VITE_APP_NAME || 'Ada Chat';

// The server renders <html lang> with the resolved locale; load those
// translations before the first render so no untranslated text flashes.
void initI18n(document.documentElement.lang || 'en').then(() =>
    createInertiaApp({
        title: (title) => (title ? `${title} - ${appName}` : appName),
        layout: (name) => {
            switch (true) {
                case name === 'welcome':
                    return LandingLayout;
                case name.startsWith('auth/'):
                    return AuthLayout;
                case name.startsWith('settings/'):
                    return [AppLayout, SettingsLayout];
                case name.startsWith('admin/'):
                    return [AppLayout, AdminLayout];
                default:
                    return AppLayout;
            }
        },
        strictMode: true,
        withApp(app) {
            return (
                <TooltipProvider delayDuration={0}>
                    {app}
                    <Toaster />
                </TooltipProvider>
            );
        },
        progress: {
            color: '#4B5563',
        },
    }),
);

// This will set light / dark mode on load...
initializeTheme();
