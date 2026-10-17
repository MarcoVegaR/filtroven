import type { ReactNode } from 'react';

type Block =
    | { type: 'paragraph'; content: string }
    | { type: 'ordered-list'; items: string[] }
    | { type: 'unordered-list'; items: string[] };

function parseBlocks(text: string): Block[] {
    const lines = text.split('\n');
    const blocks: Block[] = [];
    let currentListItems: string[] = [];
    let currentListType: 'ordered' | 'unordered' | null = null;
    let currentParagraph = '';

    function flushParagraph() {
        const trimmed = currentParagraph.trim();

        if (trimmed) {
            blocks.push({ type: 'paragraph', content: trimmed });
        }

        currentParagraph = '';
    }

    function flushList() {
        if (currentListItems.length > 0 && currentListType) {
            blocks.push({
                type:
                    currentListType === 'ordered'
                        ? 'ordered-list'
                        : 'unordered-list',
                items: [...currentListItems],
            });
            currentListItems = [];
            currentListType = null;
        }
    }

    for (const line of lines) {
        const trimmedLine = line.trim();

        const orderedMatch = trimmedLine.match(/^(\d+)\.\s+(.+)/);
        const unorderedMatch = trimmedLine.match(/^[-*]\s+(.+)/);

        if (orderedMatch) {
            flushParagraph();

            if (currentListType === 'unordered') {
                flushList();
            }

            currentListType = 'ordered';
            currentListItems.push(orderedMatch[2]);
        } else if (unorderedMatch) {
            flushParagraph();

            if (currentListType === 'ordered') {
                flushList();
            }

            currentListType = 'unordered';
            currentListItems.push(unorderedMatch[1]);
        } else if (trimmedLine === '') {
            flushParagraph();
            flushList();
        } else {
            flushList();
            currentParagraph += (currentParagraph ? ' ' : '') + trimmedLine;
        }
    }

    flushParagraph();
    flushList();

    return blocks;
}

function renderInline(text: string): ReactNode[] {
    const parts: ReactNode[] = [];
    const regex = /\*\*(.+?)\*\*/g;
    let lastIndex = 0;
    let match;

    while ((match = regex.exec(text)) !== null) {
        if (match.index > lastIndex) {
            parts.push(text.slice(lastIndex, match.index));
        }

        parts.push(
            <strong key={match.index} className="font-semibold text-foreground">
                {match[1]}
            </strong>,
        );
        lastIndex = match.index + match[0].length;
    }

    if (lastIndex < text.length) {
        parts.push(text.slice(lastIndex));
    }

    return parts.length > 0 ? parts : [text];
}

export function CopilotMarkdown({ content }: { content: string }) {
    const blocks = parseBlocks(content);

    if (blocks.length === 0) {
        return <p>{content}</p>;
    }

    return (
        <div className="space-y-2 text-sm leading-relaxed">
            {blocks.map((block, i) => {
                if (block.type === 'paragraph') {
                    return <p key={i}>{renderInline(block.content)}</p>;
                }

                if (block.type === 'ordered-list') {
                    return (
                        <ol
                            key={i}
                            className="list-decimal space-y-1.5 pl-5 marker:text-muted-foreground"
                        >
                            {block.items.map((item, j) => (
                                <li key={j} className="pl-1">
                                    {renderInline(item)}
                                </li>
                            ))}
                        </ol>
                    );
                }

                if (block.type === 'unordered-list') {
                    return (
                        <ul
                            key={i}
                            className="list-disc space-y-1.5 pl-5 marker:text-muted-foreground"
                        >
                            {block.items.map((item, j) => (
                                <li key={j} className="pl-1">
                                    {renderInline(item)}
                                </li>
                            ))}
                        </ul>
                    );
                }

                return null;
            })}
        </div>
    );
}
