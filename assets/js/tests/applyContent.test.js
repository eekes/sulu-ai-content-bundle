import {
    buildChanges,
    isEmptyContent,
    listBlocks,
    mergeBlocks,
    outlineToMessage,
    resolveSnippetRefs,
    snapshotOf,
} from '../utils/applyContent';
import {observable} from 'mobx';

describe('mergeBlocks', () => {
    const base = [
        {type: 'hero', title: 'Old hero', image: {id: 3}, settings: {hidden: false}},
        {type: 'text', title: 'Old text', text: '<p>Old</p>', settings: {segment: 'b2b'}},
        {
            type: 'usps',
            title: 'Why us',
            items: [
                {type: 'usp', icon: 'star', title: 'Fast', settings: {}},
                {type: 'usp', icon: 'heart', title: 'Kind', settings: {}},
            ],
        },
    ];

    test('a referenced block keeps what the assistant did not send', () => {
        const [hero] = mergeBlocks([{type: 'hero', _ref: 0, title: 'New hero'}], base);

        expect(hero).toEqual({type: 'hero', title: 'New hero', image: {id: 3}, settings: {hidden: false}});
    });

    test('blocks can be reordered and new ones added', () => {
        const merged = mergeBlocks([
            {type: 'text', _ref: 1, text: '<p>New</p>'},
            {type: 'cta', title: 'Call us'},
            {type: 'hero', _ref: 0},
        ], base);

        expect(merged.map((block) => block.type)).toEqual(['text', 'cta', 'hero']);
        expect(merged[0]).toEqual({type: 'text', title: 'Old text', text: '<p>New</p>', settings: {segment: 'b2b'}});
        expect(merged[1]).toEqual({type: 'cta', title: 'Call us'});
        expect(merged[2].title).toBe('Old hero');
    });

    test('a reference to a block of another type starts empty', () => {
        const [block] = mergeBlocks([{type: 'quote', _ref: 0, quote: 'Hi'}], base);

        expect(block).toEqual({type: 'quote', quote: 'Hi'});
    });

    test('a reference that does not exist starts empty', () => {
        const [block] = mergeBlocks([{type: 'hero', _ref: 9, title: 'X'}], base);

        expect(block).toEqual({type: 'hero', title: 'X'});
    });

    test('nested block lists merge against the nested list of the referenced block', () => {
        const [usps] = mergeBlocks([{
            type: 'usps',
            _ref: 2,
            items: [{type: 'usp', _ref: 1, title: 'Kinder'}, {type: 'usp', title: 'New'}],
        }], base);

        expect(usps.title).toBe('Why us');
        expect(usps.items).toEqual([
            {type: 'usp', icon: 'heart', title: 'Kinder', settings: {}},
            {type: 'usp', title: 'New'},
        ]);
    });

    test('the base is never changed', () => {
        const copy = JSON.parse(JSON.stringify(base));
        mergeBlocks([{type: 'hero', _ref: 0, title: 'New'}], base);

        expect(base).toEqual(copy);
    });
});

describe('buildChanges', () => {
    const current = {
        title: 'Old',
        blocks: [{type: 'text', text: '<p>Old</p>', settings: {}}],
        seo: {title: 'Old SEO', noIndex: true},
    };

    test('only changed properties, with block lists merged', () => {
        const changes = buildChanges({
            properties: {title: 'New', blocks: [{type: 'text', _ref: 0, text: '<p>New</p>'}]},
        }, current);

        expect(changes).toEqual({
            title: 'New',
            blocks: [{type: 'text', text: '<p>New</p>', settings: {}}],
        });
    });

    test('SEO and excerpt merge into what is there', () => {
        const changes = buildChanges({properties: {}, seo: {description: 'New'}, excerpt: {title: 'Teaser'}}, current);

        expect(changes).toEqual({
            seo: {title: 'Old SEO', noIndex: true, description: 'New'},
            excerpt: {title: 'Teaser'},
        });
    });

    test('a translation merges against the source locale', () => {
        const source = {blocks: [{type: 'text', text: '<p>Hallo</p>', settings: {segment: 'x'}}]};
        const changes = buildChanges({properties: {blocks: [{type: 'text', _ref: 0, text: '<p>Hello</p>'}]}}, {}, source);

        expect(changes.blocks).toEqual([{type: 'text', text: '<p>Hello</p>', settings: {segment: 'x'}}]);
    });

    test('an empty block list stays an empty list', () => {
        expect(buildChanges({properties: {blocks: []}}, current)).toEqual({blocks: []});
    });
});

describe('snapshotOf', () => {
    test('holds the old values of the changed paths, null for what was not there', () => {
        const current = {title: 'Old', blocks: [{type: 'text'}]};
        const snapshot = snapshotOf({title: 'New', excerpt: {title: 'x'}}, current);

        expect(snapshot).toEqual({title: 'Old', excerpt: null});
    });
});

describe('listBlocks', () => {
    test('lists every block with a text preview', () => {
        const blocks = listBlocks({
            title: 'Page',
            blocks: [{type: 'hero', title: 'Welcome home'}, {type: 'text', text: '<p>Some <b>bold</b> text</p>'}],
        });

        expect(blocks).toEqual([
            {property: 'blocks', index: 0, type: 'hero', preview: 'Welcome home'},
            {property: 'blocks', index: 1, type: 'text', preview: 'Some bold text'},
        ]);
    });
});

describe('isEmptyContent', () => {
    test('a form with only a title and url is empty', () => {
        expect(isEmptyContent({title: 'New page', url: '/new-page', blocks: []})).toBe(true);
    });

    test('what Sulu keeps next to the content does not count', () => {
        expect(isEmptyContent({title: 'Home', template: 'homepage', author: 1, authored: '2026-11-09T10:00:00', seo: {title: 'x'}, blocks: []})).toBe(true);
    });

    test('a long text is content', () => {
        expect(isEmptyContent({title: 'Page', article: '<p>' + 'word '.repeat(30) + '</p>'})).toBe(false);
    });

    test('a form with blocks is not', () => {
        expect(isEmptyContent({title: 'Page', blocks: [{type: 'text'}]})).toBe(false);
    });
});

describe('outlineToMessage', () => {
    test('numbers the blocks', () => {
        expect(outlineToMessage({template: 'default', blocks: [{type: 'hero', purpose: 'Welcome'}, {type: 'faq', purpose: 'Questions'}]}))
            .toBe('Write the page with this outline (template "default"):\n1. hero: Welcome\n2. faq: Questions');
    });
});

describe('outlineToMessage with names', () => {
    test('shows the name and keeps the key for the assistant', () => {
        expect(outlineToMessage({template: 'default', blocks: [{type: 'faq_snippets', title: 'FAQ from snippets', purpose: 'Delivery'}]}))
            .toBe('Write the page with this outline (template "default"):\n1. FAQ from snippets (faq_snippets): Delivery');
    });
});

describe('outlineToMessage with snippets', () => {
    test('says which snippets are reused and which are new', () => {
        expect(outlineToMessage({template: null, blocks: [{type: 'faq_snippets', purpose: 'FAQ', snippets: [{title: 'Delivery?', uuid: 'u-1'}, {title: 'Online?', uuid: null}]}]}))
            .toBe('Write the page with this outline:\n1. faq_snippets: FAQ\n   - reuse "Delivery?" (u-1)\n   - new: "Online?"');
    });
});

describe('resolveSnippetRefs', () => {
    test('new snippets get their uuid, left out ones disappear', () => {
        const changes = {
            blocks: [
                {type: 'faq_snippets', items: ['existing-1', 'new:faq-1', 'new:faq-2'], settings: {}},
                {type: 'quote', testimonial: 'new:quote-1'},
            ],
            title: 'new: is not a reference here',
        };

        expect(resolveSnippetRefs(changes, {'faq-1': 'uuid-1'})).toEqual({
            blocks: [
                {type: 'faq_snippets', items: ['existing-1', 'uuid-1'], settings: {}},
                {type: 'quote', testimonial: null},
            ],
            title: 'new: is not a reference here',
        });
    });
});

describe('a reply kept in the conversation', () => {
    // the panel keeps messages in a MobX store: a reply applied later (after a reload, from an
    // earlier conversation, or a turn that ran on while the panel was closed) is observable, and
    // in MobX 4 an observable array is not an array
    const reply = {
        type: 'content',
        properties: {
            blocks: [
                {type: 'hero', title: 'Learn to bake', _ref: 0},
                {type: 'faq_snippets', items: ['new:faq-1', 'new:faq-2']},
                {type: 'usps', items: [{type: 'usp', title: 'Small groups'}]},
            ],
        },
        seo: {title: 'Sourdough workshops'},
    };
    const current = {blocks: [{type: 'hero', title: 'Old', background: 'dark'}]};

    test('is applied with its block lists as lists', () => {
        const changes = resolveSnippetRefs(buildChanges(observable(reply), current), {'faq-1': 'uuid-1'});

        expect(changes.blocks).toEqual([
            {type: 'hero', title: 'Learn to bake', background: 'dark'},
            {type: 'faq_snippets', items: ['uuid-1']},
            {type: 'usps', items: [{type: 'usp', title: 'Small groups'}]},
        ]);
        expect(changes.seo).toEqual({title: 'Sourdough workshops'});
    });

    test('merges against an observable form as well', () => {
        const changes = buildChanges(reply, observable(current));

        expect(changes.blocks[0]).toEqual({type: 'hero', title: 'Learn to bake', background: 'dark'});
    });
});
