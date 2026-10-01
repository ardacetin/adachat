import { useCallback, useRef, useState } from 'react';
import { xsrfToken } from '@/lib/xsrf';
import { destroy, store } from '@/routes/attachments';
import type { AttachmentInfo } from '@/types/chat';

export type AttachmentItem = {
    key: string;
    name: string;
    kind: 'image' | 'text';
    status: 'uploading' | 'ready' | 'error';
    info?: AttachmentInfo;
    error?: string;
};

/** Files a text-only model can still read: sent as text in the message. */
export const TEXT_ACCEPT =
    '.txt,.md,.csv,.tsv,.json,.xml,.yaml,.yml,.log,.ini,.toml,.py,.js,.ts,.tsx,.jsx,.php,.java,.c,.h,.cpp,.hpp,.cs,.go,.rs,.rb,.kt,.swift,.r,.m,.sql,.sh,.html,.css,.scss,.tex,.bib,text/*';

export const IMAGE_ACCEPT = 'image/png,image/jpeg,image/webp,image/gif';

let counter = 0;

async function upload(file: File): Promise<AttachmentInfo> {
    const body = new FormData();
    body.append('file', file);

    const response = await fetch(store.url(), {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'X-XSRF-TOKEN': xsrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        body,
        credentials: 'same-origin',
    });

    if (response.ok) {
        return (await response.json()) as AttachmentInfo;
    }

    let message: string | undefined;

    try {
        const json = (await response.json()) as {
            errors?: Record<string, string[]>;
            message?: string;
        };
        message = Object.values(json.errors ?? {})[0]?.[0] ?? json.message;
    } catch {
        // Not JSON, e.g. 413 from the web server.
    }

    throw new Error(message ?? `HTTP ${response.status}`);
}

/**
 * The files attached in the composer: uploaded as soon as they are added,
 * so sending only passes their ids (docs/frontend-architecture.md §4).
 */
export function useAttachments({
    imagesAllowed,
    messages,
}: {
    imagesAllowed: boolean;
    messages: { imagesUnsupported: string; tooLarge: string };
}) {
    const [items, setItems] = useState<AttachmentItem[]>([]);
    const itemsRef = useRef(items);
    itemsRef.current = items;

    const update = (key: string, patch: Partial<AttachmentItem>) =>
        setItems((current) =>
            current.map((item) =>
                item.key === key ? { ...item, ...patch } : item,
            ),
        );

    const add = useCallback(
        (files: File[]) => {
            for (const file of files) {
                const key = `file-${++counter}`;
                const kind = file.type.startsWith('image/') ? 'image' : 'text';
                const item: AttachmentItem = {
                    key,
                    name: file.name || 'file',
                    kind,
                    status: 'uploading',
                };

                if (kind === 'image' && !imagesAllowed) {
                    setItems((current) => [
                        ...current,
                        {
                            ...item,
                            status: 'error',
                            error: messages.imagesUnsupported,
                        },
                    ]);
                    continue;
                }

                setItems((current) => [...current, item]);

                upload(file).then(
                    (info) =>
                        update(key, { status: 'ready', info, kind: info.kind }),
                    (error: Error) =>
                        update(key, {
                            status: 'error',
                            error: error.message.startsWith('HTTP 413')
                                ? messages.tooLarge
                                : error.message,
                        }),
                );
            }
        },
        [imagesAllowed, messages.imagesUnsupported, messages.tooLarge],
    );

    const remove = useCallback((key: string) => {
        const item = itemsRef.current.find(
            (candidate) => candidate.key === key,
        );

        setItems((current) =>
            current.filter((candidate) => candidate.key !== key),
        );

        if (item?.info) {
            void fetch(destroy.url(item.info.id), {
                method: 'DELETE',
                headers: {
                    'X-XSRF-TOKEN': xsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            });
        }
    }, []);

    const ready = items
        .filter((item) => item.status === 'ready' && item.info)
        .map((item) => item.info as AttachmentInfo);

    return {
        items,
        ready,
        uploading: items.some((item) => item.status === 'uploading'),
        add,
        remove,
        /** After sending: the files now belong to the message. */
        clear: () => setItems([]),
        /** After a refusal: put the sent files back. */
        restore: (restored: AttachmentItem[]) => setItems(restored),
    };
}
