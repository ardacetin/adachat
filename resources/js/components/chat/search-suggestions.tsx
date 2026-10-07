import { useTranslation } from 'react-i18next';

/**
 * Google's Search Suggestions for an answer grounded in Google Search. The
 * terms require showing them, unmodified, with the answer: they render in a
 * sandboxed frame (no scripts, no access to Ada) and their links open in a
 * new tab.
 */
export function SearchSuggestions({ html }: { html: string }) {
    const { t } = useTranslation('chat');

    return (
        <iframe
            title={t('webSearch.googleSuggestions')}
            sandbox="allow-popups allow-popups-to-escape-sandbox"
            referrerPolicy="no-referrer"
            srcDoc={`<!doctype html><html><head><meta charset="utf-8"><base target="_blank"></head><body style="margin:0">${html}</body></html>`}
            className="h-16 w-full border-0 bg-transparent"
            data-test="search-suggestions"
        />
    );
}
