import { memo } from 'react';
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
    const components: Components = {
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
        pre: ({ children }) => {
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
        },
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
