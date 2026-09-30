import type { InertiaLinkProps } from '@inertiajs/react';
import type { ParseKeys } from 'i18next';
import type { LucideIcon } from 'lucide-react';

/**
 * A translation key; keys outside the default namespace use the
 * "namespace:key" form (e.g. "settings:profile.title").
 */
export type TranslationKey = ParseKeys<['common', 'auth', 'settings', 'admin']>;

export type BreadcrumbItem = {
    titleKey: TranslationKey;
    href: NonNullable<InertiaLinkProps['href']>;
};

export type NavItem = {
    titleKey: TranslationKey;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon | null;
    isActive?: boolean;
};
