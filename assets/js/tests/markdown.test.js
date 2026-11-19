import {parseInline, parseMarkdown} from '../utils/markdown';

describe('parseInline', () => {
    test('bold, italic, code and links', () => {
        expect(parseInline('A **bold** and *soft* `code` [link](https://example.com).')).toEqual([
            {type: 'text', text: 'A '},
            {type: 'strong', children: [{type: 'text', text: 'bold'}]},
            {type: 'text', text: ' and '},
            {type: 'em', children: [{type: 'text', text: 'soft'}]},
            {type: 'text', text: ' '},
            {type: 'code', text: 'code'},
            {type: 'text', text: ' '},
            {type: 'link', href: 'https://example.com', children: [{type: 'text', text: 'link'}]},
            {type: 'text', text: '.'},
        ]);
    });

    test('a javascript link stays text', () => {
        expect(parseInline('[click](javascript:alert(1))')).toEqual([{type: 'text', text: '[click](javascript:alert(1))'}]);
    });

    test('HTML is just text', () => {
        expect(parseInline('<b>hi</b>')).toEqual([{type: 'text', text: '<b>hi</b>'}]);
    });

    test('a lone star is not italic', () => {
        expect(parseInline('5 * 3 = 15')).toEqual([{type: 'text', text: '5 * 3 = 15'}]);
    });
});

describe('parseMarkdown', () => {
    test('the message of the screenshot: a paragraph and a list with bold names', () => {
        const blocks = parseMarkdown('The website has these pages:\n- **Homepage** (Website)\n- **About us** - the organisation\n\nUseful extra pages:\n- **Contact** - opening hours');

        expect(blocks.map((block) => block.type)).toEqual(['paragraph', 'list', 'paragraph', 'list']);
        expect(blocks[1].items[0]).toEqual([
            {type: 'strong', children: [{type: 'text', text: 'Homepage'}]},
            {type: 'text', text: ' (Website)'},
        ]);
        expect(blocks[1].ordered).toBe(false);
    });

    test('numbered lists, headings and lines of one paragraph', () => {
        expect(parseMarkdown('## Plan\n1. Hero\n2. FAQ\nSome text\non two lines')).toEqual([
            {type: 'heading', children: [{type: 'text', text: 'Plan'}]},
            {type: 'list', ordered: true, items: [[{type: 'text', text: 'Hero'}], [{type: 'text', text: 'FAQ'}]]},
            {type: 'paragraph', children: [{type: 'text', text: 'Some text on two lines'}]},
        ]);
    });
});
