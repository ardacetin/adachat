import type { ReactNode } from 'react';
import InputError from '@/components/input-error';
import { Label } from '@/components/ui/label';

type Props = {
    id: string;
    label: string;
    help?: string;
    error?: string;
    children: ReactNode;
};

/**
 * Label + control + help text + validation error, wired for screen readers.
 */
export default function FormField({ id, label, help, error, children }: Props) {
    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>{label}</Label>
            {children}
            {help && (
                <p id={`${id}-help`} className="text-xs text-muted-foreground">
                    {help}
                </p>
            )}
            <InputError message={error} />
        </div>
    );
}
