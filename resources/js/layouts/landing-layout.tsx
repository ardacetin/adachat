import type { ReactNode } from 'react';

/** The public landing page: no sidebar, no sign-in card. */
export default function LandingLayout({ children }: { children: ReactNode }) {
    return (
        <div className="flex min-h-svh flex-col bg-background text-foreground">
            {children}
        </div>
    );
}
