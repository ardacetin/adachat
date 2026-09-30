import type { Auth } from '@/types/auth';
import type { BudgetSummary } from '@/types/budget';

export type Locale = 'en' | 'tr';

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            institution: {
                name: string;
                shortName: string | null;
                logoUrl: string | null;
                logoDarkUrl: string | null;
                faviconUrl: string | null;
                supportEmail: string | null;
                privacyUrl: string | null;
                termsUrl: string | null;
            };
            budget: BudgetSummary | null;
            can: {
                accessAdmin: boolean;
                manageSystem: boolean;
            };
            locale: {
                current: Locale;
                available: Locale[];
            };
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
    }
}
