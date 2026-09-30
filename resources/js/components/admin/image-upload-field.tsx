import { useTranslation } from 'react-i18next';
import FormField from '@/components/admin/form-field';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Props = {
    id: string;
    label: string;
    help: string;
    accept: string;
    currentUrl: string | null;
    error?: string;
    remove: boolean;
    onFile: (file: File | null) => void;
    onRemove: (remove: boolean) => void;
};

export default function ImageUploadField({
    id,
    label,
    help,
    accept,
    currentUrl,
    error,
    remove,
    onFile,
    onRemove,
}: Props) {
    const { t } = useTranslation('admin');

    return (
        <FormField id={id} label={label} help={help} error={error}>
            <div className="flex items-center gap-4">
                <div className="flex h-12 w-32 shrink-0 items-center justify-center overflow-hidden rounded-md border bg-muted/40 p-1">
                    {currentUrl && !remove ? (
                        <img
                            src={currentUrl}
                            alt={t('institution.current')}
                            className="max-h-10 max-w-full object-contain"
                        />
                    ) : (
                        <span className="px-2 text-xs text-muted-foreground">
                            {t('institution.none')}
                        </span>
                    )}
                </div>
                <Input
                    id={id}
                    type="file"
                    accept={accept}
                    aria-describedby={`${id}-help`}
                    aria-invalid={error !== undefined}
                    onChange={(event) =>
                        onFile(event.target.files?.[0] ?? null)
                    }
                />
            </div>
            {currentUrl && (
                <div className="flex items-center gap-2">
                    <Checkbox
                        id={`${id}-remove`}
                        checked={remove}
                        onCheckedChange={(checked) =>
                            onRemove(checked === true)
                        }
                    />
                    <Label htmlFor={`${id}-remove`} className="font-normal">
                        {t('institution.remove')}
                    </Label>
                </div>
            )}
        </FormField>
    );
}
