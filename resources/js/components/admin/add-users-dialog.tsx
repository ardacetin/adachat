import { useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import CheckboxField from '@/components/admin/checkbox-field';
import FormField from '@/components/admin/form-field';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { store } from '@/routes/admin/users';

type Props = {
    groups: { id: number; name: string }[];
};

/**
 * Adds accounts by e-mail address. The people sign in with the institution's
 * identity provider; the account is linked by the address.
 */
export default function AddUsersDialog({ groups }: Props) {
    const { t } = useTranslation('admin');
    const { can } = usePage().props;
    const [open, setOpen] = useState(false);

    const form = useForm({
        emails: '',
        group_id: String(groups[0]?.id ?? ''),
        role: 'user',
        send_email: true,
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.post(store.url(), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset('emails');
                setOpen(false);
            },
        });
    };

    return (
        <>
            <Button type="button" onClick={() => setOpen(true)}>
                {t('users.add')}
            </Button>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <form onSubmit={submit} className="space-y-4">
                        <DialogHeader>
                            <DialogTitle>{t('users.addTitle')}</DialogTitle>
                            <DialogDescription>
                                {t('users.addHelp')}
                            </DialogDescription>
                        </DialogHeader>

                        <FormField
                            id="emails"
                            label={t('users.addresses')}
                            help={t('users.addressesHelp')}
                            error={form.errors.emails}
                        >
                            <Textarea
                                id="emails"
                                rows={6}
                                className="font-mono text-sm"
                                value={form.data.emails}
                                aria-describedby="emails-help"
                                placeholder={t('users.addressesPlaceholder')}
                                onChange={(event) =>
                                    form.setData('emails', event.target.value)
                                }
                                required
                            />
                        </FormField>

                        <FormField
                            id="invite-group"
                            label={t('users.group')}
                            error={form.errors.group_id}
                        >
                            <Select
                                value={form.data.group_id}
                                onValueChange={(value) =>
                                    form.setData('group_id', value)
                                }
                            >
                                <SelectTrigger
                                    id="invite-group"
                                    className="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {groups.map((group) => (
                                        <SelectItem
                                            key={group.id}
                                            value={String(group.id)}
                                        >
                                            {group.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </FormField>

                        {can.manageSystem && (
                            <FormField
                                id="invite-role"
                                label={t('users.role')}
                                error={form.errors.role}
                            >
                                <Select
                                    value={form.data.role}
                                    onValueChange={(value) =>
                                        form.setData('role', value)
                                    }
                                >
                                    <SelectTrigger
                                        id="invite-role"
                                        className="w-full"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {(
                                            [
                                                'user',
                                                'admin',
                                                'super_admin',
                                            ] as const
                                        ).map((role) => (
                                            <SelectItem key={role} value={role}>
                                                {t(`roles.${role}`)}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </FormField>
                        )}

                        <div className="space-y-1">
                            <CheckboxField
                                id="invite-send-email"
                                label={t('users.sendEmail')}
                                checked={form.data.send_email}
                                onChange={(checked) =>
                                    form.setData('send_email', checked)
                                }
                            />
                            <p className="text-sm text-muted-foreground">
                                {t('users.sendEmailHelp')}
                            </p>
                        </div>

                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setOpen(false)}
                            >
                                {t('common.cancel')}
                            </Button>
                            <Button type="submit" disabled={form.processing}>
                                {t('users.addSubmit')}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}
