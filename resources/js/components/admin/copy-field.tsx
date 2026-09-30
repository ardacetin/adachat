import { Check, Copy } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useClipboard } from '@/hooks/use-clipboard';

type Props = {
    id: string;
    label: string;
    value: string;
};

/**
 * A read-only value the administrator copies somewhere else (e.g. into the
 * identity provider's settings).
 */
export default function CopyField({ id, label, value }: Props) {
    const { t } = useTranslation('admin');
    const [copied, copy] = useClipboard();

    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>{label}</Label>
            <div className="flex gap-2">
                <Input
                    id={id}
                    value={value}
                    readOnly
                    className="font-mono text-xs"
                    onFocus={(event) => event.currentTarget.select()}
                />
                <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    onClick={() => void copy(value)}
                    aria-label={
                        copied === value ? t('common.copied') : t('common.copy')
                    }
                >
                    {copied === value ? (
                        <Check className="size-4" />
                    ) : (
                        <Copy className="size-4" />
                    )}
                </Button>
            </div>
        </div>
    );
}
