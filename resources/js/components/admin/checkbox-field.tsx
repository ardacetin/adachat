import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';

type Props = {
    id: string;
    label: string;
    checked: boolean;
    onChange: (checked: boolean) => void;
};

export default function CheckboxField({ id, label, checked, onChange }: Props) {
    return (
        <div className="flex items-center gap-2">
            <Checkbox
                id={id}
                checked={checked}
                onCheckedChange={(value) => onChange(value === true)}
            />
            <Label htmlFor={id} className="font-normal">
                {label}
            </Label>
        </div>
    );
}
