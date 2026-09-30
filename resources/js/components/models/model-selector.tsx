import { useTranslation } from 'react-i18next';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { AliasOption } from '@/types/chat';

type Props = {
    aliases: AliasOption[];
    value: number | null;
    onChange: (id: number) => void;
    disabled?: boolean;
};

/**
 * Users choose among the institution's model aliases ("Fast", "Advanced");
 * the underlying model is shown only when the administrator allows it.
 */
export default function ModelSelector({
    aliases,
    value,
    onChange,
    disabled,
}: Props) {
    const { t } = useTranslation('chat');

    return (
        <Select
            value={value === null ? undefined : String(value)}
            onValueChange={(id) => onChange(Number(id))}
            disabled={disabled}
        >
            <SelectTrigger
                className="h-8 w-auto max-w-56 gap-1 border-none bg-transparent shadow-none"
                aria-label={t('model.label')}
            >
                <SelectValue />
            </SelectTrigger>
            {/* The composer sits at the bottom of the screen: open upwards.
                (Below, the list would be squeezed into the few pixels left
                instead of flipping, because its height follows the space.) */}
            <SelectContent side="top" align="start" className="max-h-96">
                {aliases.map((alias) => (
                    <SelectItem
                        key={alias.id}
                        value={String(alias.id)}
                        className="py-2"
                    >
                        <div className="flex flex-col items-start gap-0.5">
                            <span className="font-medium">{alias.name}</span>
                            {alias.description && (
                                <span className="text-xs text-muted-foreground">
                                    {alias.description}
                                </span>
                            )}
                            {alias.details && (
                                <span className="text-xs text-muted-foreground">
                                    {alias.details}
                                </span>
                            )}
                        </div>
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}
