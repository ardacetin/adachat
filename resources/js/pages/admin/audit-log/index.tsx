import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import Pagination from '@/components/admin/pagination';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { index } from '@/routes/admin/audit-log';
import { show as showUser } from '@/routes/admin/users';
import type { Paginated } from '@/types/pagination';

type Values = Record<string, unknown> | null;

type Entry = {
    id: number;
    created_at: string;
    actor: { id: number; name: string; email: string } | null;
    actor_type: string;
    action: string;
    subject_type: string | null;
    subject_id: string | null;
    subject_label: string | null;
    old_values: Values;
    new_values: Values;
    ip_address: string | null;
};

type Filters = {
    action: string | null;
    actor: string;
    subject_type: string | null;
    subject_id: string | null;
    from: string | null;
    to: string | null;
};

type Props = {
    entries: Paginated<Entry>;
    filters: Filters;
    actions: string[];
    subjectTypes: string[];
};

const ALL = 'all';

const show = (value: unknown) =>
    value === null || value === undefined
        ? '—'
        : typeof value === 'string'
          ? value
          : JSON.stringify(value);

function Changes({ before, after }: { before: Values; after: Values }) {
    const { t } = useTranslation('admin');
    const keys = [
        ...new Set([...Object.keys(before ?? {}), ...Object.keys(after ?? {})]),
    ];

    if (keys.length === 0) {
        return null;
    }

    return (
        <dl className="grid gap-1 text-xs">
            {keys.map((key) => (
                <div key={key} className="flex flex-wrap gap-x-3">
                    <dt className="min-w-40 font-mono text-muted-foreground">
                        {key}
                    </dt>
                    <dd className="min-w-0 flex-1 font-mono break-all">
                        {before !== null && key in before && (
                            <span
                                className="text-muted-foreground line-through"
                                title={t('auditLog.before')}
                            >
                                {show(before[key])}
                            </span>
                        )}
                        {before !== null && key in before && ' → '}
                        <span title={t('auditLog.after')}>
                            {show(after?.[key])}
                        </span>
                    </dd>
                </div>
            ))}
        </dl>
    );
}

export default function AuditLog({
    entries,
    filters,
    actions,
    subjectTypes,
}: Props) {
    const { t } = useTranslation('admin');
    const { locale } = usePage().props;
    const [form, setForm] = useState(filters);

    const apply = (next: Filters) =>
        router.get(
            index.url(),
            Object.fromEntries(
                Object.entries(next).filter(
                    ([, value]) => value !== null && value !== '',
                ),
            ),
            { preserveState: true, replace: true },
        );

    const time = (value: string) =>
        new Intl.DateTimeFormat(locale.current, {
            dateStyle: 'medium',
            timeStyle: 'medium',
        }).format(new Date(value));

    const actor = (entry: Entry) =>
        entry.actor
            ? entry.actor.name
            : entry.actor_type === 'cli'
              ? t('auditLog.cli')
              : t('auditLog.system');

    return (
        <>
            <Head title={t('auditLog.title')} />

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={t('auditLog.title')}
                    description={t('auditLog.description')}
                />

                <form
                    className="grid gap-3 sm:grid-cols-2 sm:items-end lg:grid-cols-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        apply(form);
                    }}
                >
                    <div className="grid gap-1">
                        <Label>{t('auditLog.action')}</Label>
                        <Select
                            value={form.action ?? ALL}
                            onValueChange={(value) => {
                                const next = {
                                    ...form,
                                    action: value === ALL ? null : value,
                                };
                                setForm(next);
                                apply(next);
                            }}
                        >
                            <SelectTrigger aria-label={t('auditLog.action')}>
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent className="max-h-72">
                                <SelectItem value={ALL}>
                                    {t('common.all')}
                                </SelectItem>
                                {actions.map((action) => (
                                    <SelectItem key={action} value={action}>
                                        {action}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="grid gap-1">
                        <Label>{t('auditLog.subject')}</Label>
                        <Select
                            value={form.subject_type ?? ALL}
                            onValueChange={(value) => {
                                const next = {
                                    ...form,
                                    subject_type: value === ALL ? null : value,
                                    subject_id: null,
                                };
                                setForm(next);
                                apply(next);
                            }}
                        >
                            <SelectTrigger aria-label={t('auditLog.subject')}>
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ALL}>
                                    {t('common.all')}
                                </SelectItem>
                                {subjectTypes.map((type) => (
                                    <SelectItem key={type} value={type}>
                                        {type}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="actor">{t('auditLog.actor')}</Label>
                        <Input
                            id="actor"
                            type="search"
                            value={form.actor}
                            placeholder={t('auditLog.actorPlaceholder')}
                            onChange={(event) =>
                                setForm({ ...form, actor: event.target.value })
                            }
                        />
                    </div>
                    <div className="grid grid-cols-2 gap-2 lg:col-span-2">
                        <div className="grid gap-1">
                            <Label htmlFor="from">{t('auditLog.from')}</Label>
                            <Input
                                id="from"
                                type="date"
                                value={form.from ?? ''}
                                onChange={(event) =>
                                    setForm({
                                        ...form,
                                        from: event.target.value || null,
                                    })
                                }
                            />
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor="to">{t('auditLog.to')}</Label>
                            <Input
                                id="to"
                                type="date"
                                value={form.to ?? ''}
                                onChange={(event) =>
                                    setForm({
                                        ...form,
                                        to: event.target.value || null,
                                    })
                                }
                            />
                        </div>
                    </div>
                    <Button type="submit" variant="outline">
                        {t('common.filter')}
                    </Button>
                </form>

                {entries.data.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('auditLog.empty')}
                    </p>
                ) : (
                    <ol className="divide-y rounded-lg border">
                        {entries.data.map((entry) => (
                            <li key={entry.id} className="space-y-2 p-3">
                                <div className="flex flex-wrap items-baseline justify-between gap-2 text-sm">
                                    <span className="font-mono font-medium">
                                        {entry.action}
                                    </span>
                                    <time
                                        dateTime={entry.created_at}
                                        className="text-xs text-muted-foreground"
                                    >
                                        {time(entry.created_at)}
                                    </time>
                                </div>
                                <div className="text-xs text-muted-foreground">
                                    {actor(entry)}
                                    {entry.actor && ` (${entry.actor.email})`}
                                    {entry.subject_type && (
                                        <>
                                            {' → '}
                                            {entry.subject_type === 'User' &&
                                            entry.subject_id ? (
                                                <Link
                                                    href={showUser(
                                                        Number(
                                                            entry.subject_id,
                                                        ),
                                                    )}
                                                    className="underline underline-offset-4"
                                                >
                                                    {entry.subject_label ??
                                                        `User #${entry.subject_id}`}
                                                </Link>
                                            ) : (
                                                `${entry.subject_type}${entry.subject_id ? ` #${entry.subject_id}` : ''}`
                                            )}
                                        </>
                                    )}
                                    {entry.ip_address &&
                                        ` · ${t('auditLog.ip')} ${entry.ip_address}`}
                                </div>
                                <Changes
                                    before={entry.old_values}
                                    after={entry.new_values}
                                />
                            </li>
                        ))}
                    </ol>
                )}

                <Pagination page={entries} />
            </div>
        </>
    );
}

AuditLog.layout = {
    breadcrumbs: [{ titleKey: 'admin:auditLog.title', href: index() }],
};
