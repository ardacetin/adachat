import { Check, Copy } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { useClipboard } from '@/hooks/use-clipboard';

type Props = {
    code: string;
    language: string | null;
    /** Highlighting is skipped while the answer is still streaming. */
    highlight: boolean;
};

/**
 * Code block with a language label, a copy button and syntax highlighting.
 * Shiki (and each language grammar) is loaded only when a code block is
 * shown; plain text is rendered until then.
 */
export default function CodeBlock({ code, language, highlight }: Props) {
    const { t } = useTranslation('chat');
    const [copied, copy] = useClipboard();
    const [html, setHtml] = useState<string | null>(null);

    useEffect(() => {
        if (!highlight || !language) {
            return;
        }

        let active = true;

        import('shiki')
            .then(({ codeToHtml, bundledLanguages }) => {
                if (!(language in bundledLanguages)) {
                    return null;
                }

                return codeToHtml(code, {
                    lang: language,
                    themes: { light: 'github-light', dark: 'github-dark' },
                    defaultColor: false,
                });
            })
            .then((result) => {
                if (active && result) {
                    setHtml(result);
                }
            })
            .catch(() => undefined);

        return () => {
            active = false;
        };
    }, [code, language, highlight]);

    return (
        <div className="group/code my-3 overflow-hidden rounded-lg border bg-muted/40">
            <div className="flex items-center justify-between border-b px-3 py-1 text-xs text-muted-foreground">
                <span className="font-mono">{language ?? ''}</span>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    className="h-7 gap-1 px-2"
                    onClick={() => void copy(code)}
                    aria-label={t('code.copy')}
                >
                    {copied === code ? (
                        <Check className="size-3.5" />
                    ) : (
                        <Copy className="size-3.5" />
                    )}
                    <span className="sr-only sm:not-sr-only">
                        {copied === code ? t('code.copied') : t('code.copy')}
                    </span>
                </Button>
            </div>
            {html !== null ? (
                // Shiki escapes the code; the markup only adds styled spans.
                <div
                    className="shiki-block overflow-x-auto p-3 text-sm [&_pre]:!bg-transparent"
                    dangerouslySetInnerHTML={{ __html: html }}
                />
            ) : (
                <pre className="overflow-x-auto p-3 text-sm">
                    <code>{code}</code>
                </pre>
            )}
        </div>
    );
}
