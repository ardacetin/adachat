import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { search } from '@/routes';
import { show } from '@/routes/conversations';

type Result = {
    id: string;
    title: string | null;
    last_message_at: string;
    snippet: { before: string; match: string; after: string } | null;
};

type Props = {
    query: string;
    results: Result[] | null;
};

/** Search in the user's own conversations. */
export default function ChatSearch({ query, results }: Props) {
    const { t } = useTranslation('chat');
    const { locale } = usePage().props;
    const [value, setValue] = useState(query);

    const date = (value: string) =>
        new Intl.DateTimeFormat(locale.current, { dateStyle: 'medium' }).format(
            new Date(value),
        );

    return (
        <>
            <Head title={t('search.title')} />

            <div className="mx-auto w-full max-w-3xl space-y-6 px-4 py-8">
                <h1 className="text-xl font-semibold">{t('search.title')}</h1>

                <form
                    role="search"
                    className="flex gap-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        router.get(
                            search.url(),
                            { q: value },
                            { preserveState: true, replace: true },
                        );
                    }}
                >
                    <Input
                        type="search"
                        name="q"
                        value={value}
                        onChange={(event) => setValue(event.target.value)}
                        placeholder={t('search.placeholder')}
                        aria-label={t('search.label')}
                        maxLength={100}
                        autoFocus
                    />
                    <Button type="submit">{t('search.submit')}</Button>
                </form>
                <p className="text-xs text-muted-foreground">
                    {t('search.hint')}
                </p>

                {query !== '' && results === null && (
                    <p className="text-sm text-muted-foreground">
                        {t('search.short')}
                    </p>
                )}

                {results !== null && (
                    <section aria-live="polite" className="space-y-3">
                        <p className="text-sm text-muted-foreground">
                            {results.length === 0
                                ? t('search.none')
                                : t('search.count', { count: results.length })}
                        </p>
                        <ul className="divide-y rounded-lg border">
                            {results.map((result) => (
                                <li key={result.id}>
                                    <Link
                                        href={show(result.id)}
                                        className="block space-y-1 p-4 hover:bg-muted/50"
                                    >
                                        <span className="flex justify-between gap-4">
                                            <span className="font-medium">
                                                {result.title ?? t('untitled')}
                                            </span>
                                            <span className="shrink-0 text-xs text-muted-foreground">
                                                {date(result.last_message_at)}
                                            </span>
                                        </span>
                                        {result.snippet && (
                                            <span className="block text-sm text-muted-foreground">
                                                {result.snippet.before}
                                                <mark className="rounded bg-yellow-200 px-0.5 text-foreground dark:bg-yellow-700">
                                                    {result.snippet.match}
                                                </mark>
                                                {result.snippet.after}
                                            </span>
                                        )}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </>
    );
}
