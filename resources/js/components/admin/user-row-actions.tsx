import { router } from '@inertiajs/react';
import { MoreHorizontal, UserCheck, UserX } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
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
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { role as roleRoute, status as statusRoute } from '@/routes/admin/users';

type Role = 'super_admin' | 'admin' | 'user';

type Props = {
    user: {
        id: number;
        name: string;
        role: Role;
        status: 'active' | 'disabled';
        can: { changeStatus: boolean; changeRole: boolean };
    };
};

/**
 * Quick actions in the users list: disable or enable the account and
 * change the role, without opening the user page.
 */
export default function UserRowActions({ user }: Props) {
    const { t } = useTranslation('admin');
    const [confirming, setConfirming] = useState(false);

    if (!user.can.changeStatus && !user.can.changeRole) {
        return null;
    }

    const options = {
        preserveScroll: true,
        preserveState: true,
        onError: (errors: Record<string, string>) =>
            toast.error(Object.values(errors)[0] ?? t('users.actionFailed')),
    };

    const setStatus = (status: 'active' | 'disabled') =>
        router.put(
            statusRoute.url(user.id),
            { status, stay: true },
            { ...options, onFinish: () => setConfirming(false) },
        );

    const setRole = (role: string) => {
        if (role !== user.role) {
            router.put(roleRoute.url(user.id), { role, stay: true }, options);
        }
    };

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button
                        variant="ghost"
                        size="icon"
                        className="size-8"
                        aria-label={t('users.actionsFor', { name: user.name })}
                    >
                        <MoreHorizontal />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    {user.can.changeStatus &&
                        (user.status === 'active' ? (
                            <DropdownMenuItem
                                variant="destructive"
                                onSelect={() => setConfirming(true)}
                            >
                                <UserX />
                                {t('users.disable')}
                            </DropdownMenuItem>
                        ) : (
                            <DropdownMenuItem
                                onSelect={() => setStatus('active')}
                            >
                                <UserCheck />
                                {t('users.enable')}
                            </DropdownMenuItem>
                        ))}
                    {user.can.changeStatus && user.can.changeRole && (
                        <DropdownMenuSeparator />
                    )}
                    {user.can.changeRole && (
                        <>
                            <DropdownMenuLabel>
                                {t('users.roleTitle')}
                            </DropdownMenuLabel>
                            <DropdownMenuRadioGroup
                                value={user.role}
                                onValueChange={setRole}
                            >
                                {(
                                    ['user', 'admin', 'super_admin'] as const
                                ).map((role) => (
                                    <DropdownMenuRadioItem
                                        key={role}
                                        value={role}
                                    >
                                        {t(`roles.${role}`)}
                                    </DropdownMenuRadioItem>
                                ))}
                            </DropdownMenuRadioGroup>
                        </>
                    )}
                </DropdownMenuContent>
            </DropdownMenu>

            <Dialog open={confirming} onOpenChange={setConfirming}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {t('users.disableTitle', { name: user.name })}
                        </DialogTitle>
                        <DialogDescription>
                            {t('users.disableHelp')}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setConfirming(false)}
                        >
                            {t('common.cancel')}
                        </Button>
                        <Button
                            type="button"
                            variant="destructive"
                            onClick={() => setStatus('disabled')}
                        >
                            {t('users.disable')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
