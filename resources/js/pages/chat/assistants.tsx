import { Head, Link } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import AssistantIcon from '@/components/chat/assistant-icon';
import { show } from '@/routes/assistants';
import type { AssistantSummary } from '@/types/chat';

/** The institution's assistants available to the user. */
export default function Assistants({
    assistants,
}: {
    assistants: AssistantSummary[];
}) {
    const { t } = useTranslation('chat');

    return (
        <>
            <Head title={t('assistants.title')} />

            <div className="mx-auto w-full max-w-4xl space-y-6 px-4 py-8">
                <div className="space-y-1">
                    <h1 className="text-xl font-semibold">
                        {t('assistants.title')}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {t('assistants.description')}
                    </p>
                </div>

                {assistants.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('assistants.none')}
                    </p>
                ) : (
                    <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {assistants.map((assistant) => (
                            <li key={assistant.id}>
                                <Link
                                    href={show(assistant.slug)}
                                    className="flex h-full flex-col gap-2 rounded-xl border p-4 transition-colors hover:bg-muted/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                >
                                    <AssistantIcon
                                        name={assistant.icon}
                                        className="size-6 text-muted-foreground"
                                    />
                                    <span className="font-medium">
                                        {assistant.name}
                                    </span>
                                    {assistant.description && (
                                        <span className="text-sm text-muted-foreground">
                                            {assistant.description}
                                        </span>
                                    )}
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}
