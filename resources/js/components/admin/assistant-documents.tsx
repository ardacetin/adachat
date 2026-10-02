import { router, usePage } from '@inertiajs/react';
import { FileText } from 'lucide-react';
import { useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import DeleteButton from '@/components/admin/delete-button';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { formatNumber, formatUsdPrecise } from '@/lib/format';
import { destroy, store } from '@/routes/admin/assistants/documents';

export type AssistantDocument = {
    id: string;
    name: string;
    kind: string;
    size: number;
    page_count: number | null;
    token_estimate: number;
};

type Props = {
    assistantId: number;
    documents: AssistantDocument[];
    fixedTokens: number;
    inputPricePerMillion: string;
    maxDocumentTokens: number;
};

/**
 * The assistant's fixed documents. Their text goes with every message, so
 * the totals and the cost per message are shown next to them.
 */
export default function AssistantDocuments({
    assistantId,
    documents,
    fixedTokens,
    inputPricePerMillion,
    maxDocumentTokens,
}: Props) {
    const { t } = useTranslation('admin');
    const { locale } = usePage().props;
    const input = useRef<HTMLInputElement>(null);
    const [uploading, setUploading] = useState(false);
    const [error, setError] = useState<string | undefined>();

    const used = documents.reduce(
        (sum, document) => sum + document.token_estimate,
        0,
    );
    const perMessage = (fixedTokens * Number(inputPricePerMillion)) / 1_000_000;

    const upload = (file: File) => {
        setError(undefined);
        router.post(
            store.url(assistantId),
            { file },
            {
                forceFormData: true,
                preserveScroll: true,
                onStart: () => setUploading(true),
                onFinish: () => {
                    setUploading(false);

                    if (input.current) {
                        input.current.value = '';
                    }
                },
                onError: (errors) => setError(errors.file),
            },
        );
    };

    return (
        <section className="space-y-3" data-test="assistant-documents">
            <div className="space-y-1">
                <h3 className="text-sm font-medium">
                    {t('assistants.documents')}
                </h3>
                <p className="text-sm text-muted-foreground">
                    {t('assistants.documentsHelp')}
                </p>
            </div>

            {documents.length > 0 && (
                <ul className="divide-y rounded-lg border">
                    {documents.map((document) => (
                        <li
                            key={document.id}
                            className="flex items-center gap-3 p-3 text-sm"
                        >
                            <FileText
                                className="size-4 shrink-0 text-muted-foreground"
                                aria-hidden
                            />
                            <span className="min-w-0 flex-1 truncate">
                                {document.name}
                            </span>
                            <span className="shrink-0 text-xs text-muted-foreground tabular-nums">
                                {t('assistants.documentTokens', {
                                    tokens: formatNumber(
                                        document.token_estimate,
                                        locale.current,
                                    ),
                                })}
                            </span>
                            <DeleteButton
                                url={destroy.url({
                                    assistant: assistantId,
                                    document: document.id,
                                })}
                                title={t('assistants.removeDocumentTitle', {
                                    name: document.name,
                                })}
                                description={t('assistants.removeDocumentHelp')}
                            />
                        </li>
                    ))}
                </ul>
            )}

            <p className="text-sm" data-test="assistant-documents-total">
                {t('assistants.documentsTotal', {
                    used: formatNumber(used, locale.current),
                    max: formatNumber(maxDocumentTokens, locale.current),
                })}{' '}
                {t('assistants.perMessage', {
                    tokens: formatNumber(fixedTokens, locale.current),
                    cost: formatUsdPrecise(perMessage, locale.current),
                })}
            </p>

            <div className="flex items-center gap-2">
                <input
                    ref={input}
                    id="assistant-document"
                    type="file"
                    className="sr-only"
                    accept=".pdf,.docx,.xlsx,.pptx,.txt,.md,.csv,.json,.html,.xml"
                    onChange={(event) => {
                        const file = event.target.files?.[0];

                        if (file) {
                            upload(file);
                        }
                    }}
                />
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    disabled={uploading}
                    onClick={() => input.current?.click()}
                >
                    {uploading
                        ? t('assistants.uploading')
                        : t('assistants.addDocument')}
                </Button>
            </div>
            <InputError message={error} />
        </section>
    );
}
