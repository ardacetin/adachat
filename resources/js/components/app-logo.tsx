import { usePage } from '@inertiajs/react';

import AppLogoIcon from '@/components/app-logo-icon';

/**
 * Product name ("Ada") with the institution as secondary line. Uses the
 * institution logo when one is configured, otherwise the neutral Ada mark.
 */
export default function AppLogo() {
    const { name, institution } = usePage().props;

    return (
        <>
            {institution.logoUrl ? (
                // Institution logos are often wide; allow up to 3:1 in the sidebar.
                <div className="flex h-8 max-w-24 shrink-0 items-center overflow-hidden">
                    <img
                        src={institution.logoUrl}
                        alt=""
                        className="max-h-8 max-w-24 object-contain dark:hidden"
                    />
                    <img
                        src={institution.logoDarkUrl ?? institution.logoUrl}
                        alt=""
                        className="hidden max-h-8 max-w-24 object-contain dark:block"
                    />
                </div>
            ) : (
                <div className="flex aspect-square size-8 items-center justify-center rounded-md bg-sidebar-primary text-sidebar-primary-foreground">
                    <AppLogoIcon className="size-5 fill-current" />
                </div>
            )}
            <div className="ml-1 grid flex-1 text-left text-sm">
                <span className="truncate leading-tight font-semibold">
                    {name}
                </span>
                <span className="truncate text-xs leading-tight text-muted-foreground">
                    {institution.shortName ?? institution.name}
                </span>
            </div>
        </>
    );
}
