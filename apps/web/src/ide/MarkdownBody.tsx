import { useState, type ComponentPropsWithoutRef } from 'react';
import ReactMarkdown from 'react-markdown';
import remarkGfm from 'remark-gfm';

function unwrapOuterFence(text: string): string {
  let value = text.replace(/^\uFEFF/, '');
  const open = value.match(/^```(?:markdown|md|gfm)?\r?\n/i);
  if (!open) {
    return value;
  }
  value = value.slice(open[0].length);
  return value.replace(/\n```[ \t]*\s*$/, '');
}

function languageFromClass(className?: string): string {
  const match = /language-([A-Za-z0-9_+-]+)/.exec(className ?? '');
  return match?.[1] ?? '';
}

function CodeBlock({ className, children, ...props }: ComponentPropsWithoutRef<'code'>) {
  const text = String(children).replace(/\n$/, '');
  const language = languageFromClass(className);
  const inline = !language && !text.includes('\n');
  const [copied, setCopied] = useState(false);

  if (inline) {
    return (
      <code className="ai-md-inline" {...props}>
        {children}
      </code>
    );
  }

  return (
    <div className="ai-md-code">
      <div className="ai-md-code-bar">
        <span>{language || 'code'}</span>
        <button
          type="button"
          onClick={() => {
            void navigator.clipboard.writeText(text).then(() => {
              setCopied(true);
              window.setTimeout(() => setCopied(false), 1200);
            });
          }}
        >
          {copied ? 'Copied' : 'Copy'}
        </button>
      </div>
      <pre>
        <code {...props}>{text}</code>
      </pre>
    </div>
  );
}

export function MarkdownBody({ text }: { text: string }) {
  return (
    <div className="ai-md">
      <ReactMarkdown
        remarkPlugins={[remarkGfm]}
        components={{
          a: ({ href, children }) => (
            <a href={href} target="_blank" rel="noreferrer noopener">
              {children}
            </a>
          ),
          img: ({ alt, src }) => <span className="ai-md-img">[{alt || src || 'image'}]</span>,
          code: CodeBlock,
          pre: ({ children }) => <>{children}</>,
        }}
      >
        {unwrapOuterFence(text)}
      </ReactMarkdown>
    </div>
  );
}
