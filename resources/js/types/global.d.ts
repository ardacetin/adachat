import type { Auth } from '@/types/auth';

export type Locale = 'en' | 'tr';

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            locale: {
                current: Locale;
                available: Locale[];
            };
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
    }
}
