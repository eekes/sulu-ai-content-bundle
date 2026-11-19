// @flow
/*
 * The bit of Markdown models write in their messages - paragraphs, lists, headings, bold, italic,
 * code and links - parsed into plain data the panel renders as React elements. Never HTML, so
 * nothing in a reply can inject markup. Tested in tests/markdown.test.js.
 */

export type Inline =
    | {type: 'text', text: string}
    | {type: 'strong', children: Array<Inline>}
    | {type: 'em', children: Array<Inline>}
    | {type: 'code', text: string}
    | {type: 'link', href: string, children: Array<Inline>};

export type Block =
    | {type: 'paragraph', children: Array<Inline>}
    | {type: 'heading', children: Array<Inline>}
    | {type: 'list', ordered: boolean, items: Array<Array<Inline>>};

const INLINE = /(\*\*|__)(.+?)\1|(\*|_)(?=\S)(.+?)(?<=\S)\3|`([^`]+)`|\[([^\]]+)\]\((https?:\/\/[^)\s]+|mailto:[^)\s]+)\)/;

export function parseInline(text: string): Array<Inline> {
    const result = [];
    let rest = text;

    while (rest !== '') {
        const match = INLINE.exec(rest);

        if (!match) {
            result.push({type: 'text', text: rest});

            break;
        }

        if (match.index > 0) {
            result.push({type: 'text', text: rest.substring(0, match.index)});
        }

        if (match[2] !== undefined) {
            result.push({type: 'strong', children: parseInline(match[2])});
        } else if (match[4] !== undefined) {
            result.push({type: 'em', children: parseInline(match[4])});
        } else if (match[5] !== undefined) {
            result.push({type: 'code', text: match[5]});
        } else {
            result.push({type: 'link', href: match[7], children: parseInline(match[6])});
        }

        rest = rest.substring(match.index + match[0].length);
    }

    return result;
}

const BULLET = /^\s*[-*•]\s+(.*)$/;
const NUMBERED = /^\s*\d+[.)]\s+(.*)$/;
const HEADING = /^\s*#{1,6}\s+(.*)$/;

export function parseMarkdown(text: string): Array<Block> {
    const blocks = [];
    let paragraph = [];
    let list = null;

    const flushParagraph = () => {
        if (paragraph.length > 0) {
            blocks.push({type: 'paragraph', children: parseInline(paragraph.join(' '))});
            paragraph = [];
        }
    };

    const flushList = () => {
        if (list) {
            blocks.push(list);
            list = null;
        }
    };

    text.split('\n').forEach((line) => {
        const bullet = BULLET.exec(line);
        const numbered = bullet ? null : NUMBERED.exec(line);
        const heading = HEADING.exec(line);

        if (bullet || numbered) {
            const ordered = !!numbered;
            const content = (bullet || numbered || [])[1];

            flushParagraph();

            if (!list || list.ordered !== ordered) {
                flushList();
                list = {type: 'list', ordered, items: []};
            }

            list.items.push(parseInline(content));

            return;
        }

        flushList();

        if (heading) {
            flushParagraph();
            blocks.push({type: 'heading', children: parseInline(heading[1])});

            return;
        }

        if (line.trim() === '') {
            flushParagraph();

            return;
        }

        paragraph.push(line.trim());
    });

    flushParagraph();
    flushList();

    return blocks;
}
