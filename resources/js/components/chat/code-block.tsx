import { Check, Copy } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { useClipboard } from '@/hooks/use-clipboard';

type Highlighter = Awaited<
    ReturnType<typeof import('shiki').createHighlighter>
>;

let highlighter: Promise<Highlighter> | null = null;

/**
 * One shared highlighter, languages loaded on demand. It uses Shiki's
 * JavaScript regex engine instead of the WebAssembly one, so the Content
 * Security Policy does not need 'wasm-unsafe-eval'.
 */
async function highlightCode(
    code: string,
    language: string,
): Promise<string | null> {
    const [shiki, { createJavaScriptRegexEngine }] = await Promise.all([
        import('shiki'),
        import('shiki/engine/javascript'),
    ]);

    if (!(language in shiki.bundledLanguages)) {
        return null;
    }

    highlighter ??= shiki.createHighlighter({
        themes: ['github-light', 'github-dark'],
        langs: [],
        engine: createJavaScriptRegexEngine({ forgiving: true }),
    });

    const instance = await highlighter;

    if (!instance.getLoadedLanguages().includes(language)) {
        await instance.loadLanguage(
            language as keyof typeof shiki.bundledLanguages,
        );
    }

    return instance.codeToHtml(code, {
        lang: language,
        themes: { light: 'github-light', dark: 'github-dark' },
        defaultColor: false,
    });
}

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

        highlightCode(code, language)
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
