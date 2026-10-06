import { memo, useMemo } from 'react';
import type { ReactElement } from 'react';
import ReactMarkdown from 'react-markdown';
import type { Components } from 'react-markdown';
import remarkGfm from 'remark-gfm';
import CodeBlock from '@/components/chat/code-block';

/** Text of a fenced code block (react-markdown passes it as a string child). */
function codeText(children: unknown): string {
    const text = Array.isArray(children)
        ? children.join('')
        : typeof children === 'string'
          ? children
          : '';

    return text.replace(/\n$/, '');
}

/** Renderers that do not depend on props: stable, so React keeps the DOM. */
const STATIC_COMPONENTS: Components = {
    a: ({ href, children }) => (
        <a
            href={href}
            target="_blank"
            rel="noopener noreferrer nofollow"
            className="font-medium underline underline-offset-4"
        >
            {children}
        </a>
    ),
    img: ({ src, alt }) =>
        typeof src === 'string' ? (
            <a
                href={src}
                target="_blank"
                rel="noopener noreferrer nofollow"
                className="underline underline-offset-4"
            >
                {alt || src}
            </a>
        ) : null,
    code: ({ children }) => (
        <code className="rounded bg-muted px-1 py-0.5 font-mono text-[0.9em]">
            {children}
        </code>
    ),
    table: ({ children }) => (
        <div className="my-3 overflow-x-auto">
            <table>{children}</table>
        </div>
    ),
};

/** Code blocks are highlighted once the answer is complete. */
function codeRenderer(streaming: boolean): Components['pre'] {
    return function Pre({ children }) {
        const code = children as ReactElement<{
            className?: string;
            children?: unknown;
        }>;
        const language =
            /language-([\w+#-]+)/.exec(code?.props?.className ?? '')?.[1] ??
            null;

        return (
            <CodeBlock
                code={codeText(code?.props?.children)}
                language={language}
                highlight={!streaming}
            />
        );
    };
}

type Props = {
    content: string;
    streaming?: boolean;
};

/**
 * Model output as Markdown. Raw HTML is never rendered (skipHtml), unsafe
 * URLs are dropped by react-markdown's default transform, links open in a
 * new tab without referrer, and images are shown as links (no tracking
 * pixels).
 */
function Markdown({ content, streaming = false }: Props) {
    // Rebuilt only when streaming changes, not on every delta: inline
    // renderers would be new component types and remount each block.
    const components = useMemo<Components>(
        () => ({ ...STATIC_COMPONENTS, pre: codeRenderer(streaming) }),
        [streaming],
    );

    return (
        <div className="prose prose-sm max-w-none dark:prose-invert prose-pre:m-0 prose-pre:bg-transparent prose-pre:p-0">
            <ReactMarkdown
                remarkPlugins={[remarkGfm]}
                skipHtml
                components={components}
            >
                {content}
            </ReactMarkdown>
        </div>
    );
}

export default memo(Markdown);
