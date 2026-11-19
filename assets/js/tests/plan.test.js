import {orderPlan, prepareItem, restoreProgress, runPlannedItem, storableProgress, urlParts, writeMessage} from '../utils/plan';

const plan = [
    {title: 'Bread', template: 'default', parent: 'services', brief: 'Our breads.'},
    {title: 'Cakes', template: 'default', parent: 'services', brief: 'Our cakes.'},
];

const context = {
    formKey: 'page',
    resourceKey: 'pages',
    locale: 'en',
    webspace: 'website',
    model: 'smart',
    templates: [{key: 'default', title: 'Default', route: {name: 'url', type: 'route', parts: ['title']}}],
    pages: [{uuid: 'services', title: 'Services', url: '/services', depth: 1}],
};

function fakeApi(turns, overrides = {}) {
    const calls = [];
    const record = (name, result) => (...args) => {
        calls.push([name, ...args]);

        return typeof result === 'function' ? result(...args) : Promise.resolve(result);
    };

    return {
        calls,
        api: {
            startConversation: record('startConversation', 'conversation-1'),
            sendTurn: record('sendTurn', () => Promise.resolve(turns.shift())),
            getTurn: record('getTurn', () => Promise.resolve(turns.shift())),
            wait: () => Promise.resolve(),
            generateUrl: record('generateUrl', '/services/bread'),
            createSnippet: record('createSnippet', (data) => Promise.resolve({id: 'snippet-' + data.title})),
            createResource: record('createResource', {id: 'page-1'}),
            attach: record('attach', {}),
            ...overrides,
        },
    };
}

const content = {
    type: 'content',
    message: 'Written.',
    properties: {
        title: 'Bread',
        blocks: [{type: 'text', text: '<p>Fresh.</p>'}, {type: 'faq_snippets', items: ['new:faq-1', 'existing']}],
    },
    seo: {title: 'Bread in Ghent'},
    excerpt: {},
    snippets: [{key: 'faq-1', template: 'faq', properties: {title: 'Is it fresh?'}}],
};

test('the brief and the rest of the plan go to the writer', () => {
    const message = writeMessage(plan[0], plan);

    expect(message).toContain('Write the page "Bread".');
    expect(message).toContain('Brief: Our breads.');
    expect(message).toContain('- Cakes: Our cakes.');
    expect(message).not.toContain('- Bread');
});

test('a planned page is written, its snippets and URL made, created and linked to its conversation', async () => {
    const {api, calls} = fakeApi([{id: 'run-1', finished: false, progress: 'Thinking'}, {id: 'run-1', finished: true, status: 'succeeded', reply: content}]);
    const states = [];

    const result = await runPlannedItem(plan[0], plan, context, api, (state) => states.push(state.state));

    expect(result).toEqual({state: 'done', id: 'page-1'});
    expect(states).toEqual(['writing', 'writing', 'creating', 'done']);

    const turn = calls.find((call) => call[0] === 'sendTurn');
    expect(turn[2]).toMatchObject({action: 'write_planned', template: 'default', content: {title: 'Bread'}, model: 'smart'});
    expect(calls.find((call) => call[0] === 'generateUrl')[1]).toEqual({parts: {title: 'Bread'}, resourceKey: 'pages', locale: 'en', webspace: 'website', parentId: 'services'});

    const [, data, parentId] = calls.find((call) => call[0] === 'createResource');
    expect(parentId).toBe('services');
    expect(data).toEqual({
        template: 'default',
        title: 'Bread',
        url: '/services/bread',
        blocks: [{type: 'text', text: '<p>Fresh.</p>'}, {type: 'faq_snippets', items: ['snippet-Is it fresh?', 'existing']}],
        seo: {title: 'Bread in Ghent'},
    });
    expect(calls.find((call) => call[0] === 'attach')).toEqual(['attach', 'conversation-1', 'page-1']);
});

test('an article sits below a page by its URL', async () => {
    const articleContext = {
        ...context,
        formKey: 'article',
        resourceKey: 'articles',
        templates: [{key: 'default', title: 'Article', route: {name: 'url', type: 'page_tree_route', parts: ['title']}}],
    };
    const {api, calls} = fakeApi([{id: 'run-1', finished: true, status: 'succeeded', reply: {...content, snippets: [], properties: {title: 'Bread'}}}], {
        generateUrl: (request) => {
            calls.push(['generateUrl', request]);

            return Promise.resolve('/bread');
        },
    });

    await runPlannedItem(plan[0], plan, articleContext, api, () => undefined);

    expect(calls.find((call) => call[0] === 'generateUrl')[1]).toMatchObject({parentId: 'services', parentKey: 'pages', relative: true, resourceKey: 'articles'});
    expect(calls.find((call) => call[0] === 'createResource')[1].url).toEqual({page: {uuid: 'services', path: '/services'}, suffix: '/bread'});
});

test('a question instead of content creates the page with its title, the question waits there', async () => {
    const {api, calls} = fakeApi([{id: 'run-1', finished: true, status: 'succeeded', reply: {type: 'questions', message: 'Which breads?', questions: []}}]);

    const result = await runPlannedItem(plan[0], plan, context, api, () => undefined);

    expect(result).toEqual({state: 'needs_input', id: 'page-1', message: 'Which breads?'});
    expect(calls.find((call) => call[0] === 'createResource')[1]).toEqual({template: 'default', title: 'Bread', url: '/services/bread'});
    expect(calls.find((call) => call[0] === 'attach')).toBeDefined();
});

test('a failing run or request is a failed state, never an exception', async () => {
    const failedRun = fakeApi([{id: 'run-1', finished: true, status: 'failed', error: 'Budget reached.'}]);
    expect(await runPlannedItem(plan[0], plan, context, failedRun.api, () => undefined)).toEqual({state: 'failed', error: 'Budget reached.'});
    expect(failedRun.calls.find((call) => call[0] === 'createResource')).toBeUndefined();

    const failedSave = fakeApi([{id: 'run-1', finished: true, status: 'succeeded', reply: content}], {createResource: () => Promise.reject(new Error('Sulu said no.'))});
    expect(await runPlannedItem(plan[0], plan, context, failedSave.api, () => undefined)).toEqual({state: 'failed', error: 'Sulu said no.'});
});

test('the URL is made from the part fields that are filled', () => {
    expect(urlParts(['title', 'subtitle'], {title: 'Bread', subtitle: ' '})).toEqual({title: 'Bread'});
});

test('after a reload created items stay created and interrupted ones can be tried again', () => {
    const [bread, cakes, catering, pies, tarts] = ['Bread', 'Cakes', 'Catering', 'Pies', 'Tarts'].map((title) => ({title, template: 'default', parent: 'p', brief: ''}));

    expect(restoreProgress([
        {item: bread, state: {state: 'done', id: 'page-1', url: '/bread'}},
        {item: cakes, state: {state: 'needs_input', id: 'page-2', message: 'Which cakes?', url: '/cakes'}},
        {item: catering, state: {state: 'writing'}},
        {item: pies, state: {state: 'failed', error: 'Budget reached.'}},
        {item: tarts, state: {state: 'waiting'}},
    ], {interrupted: 'Not created.', notStarted: 'Not started.'})).toEqual([
        {item: bread, state: {state: 'done', id: 'page-1', url: '/bread'}},
        {item: cakes, state: {state: 'needs_input', id: 'page-2', message: 'Which cakes?', url: '/cakes'}},
        {item: catering, state: {state: 'failed', error: 'Not created.'}},
        {item: pies, state: {state: 'failed', error: 'Budget reached.'}},
        // an item that had not had its turn was not being written when the planner was left
        {item: tarts, state: {state: 'failed', error: 'Not started.'}},
    ]);
});

test('the passing progress text is not stored', () => {
    expect(storableProgress([{item: plan[0], state: {state: 'writing', progress: 'Thinking'}}])).toEqual([{item: plan[0], state: {state: 'writing'}}]);
});

describe('a plan of articles with the page they go below', () => {
    const blog = {kind: 'page', key: 'blog', title: 'Blog', template: 'overview', parent: 'home', brief: 'The blog.'};
    const bread = {title: 'Slow bread', template: 'article', parent: 'new:blog', brief: 'Bread.'};
    const news = {title: 'News', template: 'article', parent: 'services', brief: 'News.'};
    const base = {
        formKey: 'article',
        resourceKey: 'articles',
        locale: 'en',
        webspace: 'website',
        model: 'smart',
        templates: [{key: 'article', title: 'Article', route: {name: 'url', type: 'page_tree_route', parts: ['title']}}],
        pageTemplates: [{key: 'overview', title: 'Overview', route: {name: 'url', type: 'route', parts: ['title']}}],
        pages: [{uuid: 'home', title: 'Website', url: '/', depth: 0}, {uuid: 'services', title: 'Services', url: '/services', depth: 1}],
    };

    test('the page is created first, the rest keeps its order', () => {
        expect(orderPlan([bread, news, blog])).toEqual([blog, bread, news]);
    });

    test('the page is written and created as a page, with its URL kept for the articles', async () => {
        const prepared = prepareItem(blog, [{item: blog, state: {state: 'waiting'}}], base);

        expect(prepared.context).toMatchObject({formKey: 'page', resourceKey: 'pages', templates: base.pageTemplates});
        expect(prepared.item).toBe(blog);

        const {api, calls} = fakeApi([{id: 'run-1', finished: true, status: 'succeeded', reply: {...content, snippets: [], seo: {}, properties: {title: 'Blog'}}}], {
            generateUrl: () => Promise.resolve('/blog'),
            createResource: (data, parentId) => {
                calls.push(['createResource', data, parentId]);

                return Promise.resolve({id: 'blog-page'});
            },
        });

        expect(await runPlannedItem(prepared.item, [blog, bread], prepared.context, api, () => undefined)).toEqual({state: 'done', id: 'blog-page', url: '/blog'});
        expect(calls.find((call) => call[0] === 'startConversation')[1]).toMatchObject({formKey: 'page'});
        expect(calls.find((call) => call[0] === 'createResource').slice(1)).toEqual([{template: 'overview', title: 'Blog', url: '/blog'}, 'home']);
    });

    test('an article goes below the page the plan created', () => {
        const items = [{item: blog, state: {state: 'done', id: 'blog-page', url: '/blog'}}, {item: bread, state: {state: 'waiting'}}];
        const prepared = prepareItem(bread, items, base);

        expect(prepared.item).toEqual({...bread, parent: 'blog-page'});
        expect(prepared.context).toMatchObject({formKey: 'article', resourceKey: 'articles', templates: base.templates});
        expect(prepared.context.pages).toContainEqual({uuid: 'blog-page', title: 'Blog', url: '/blog', depth: 0});
        expect(prepareItem(news, items, base).item).toBe(news);
    });

    test('an article whose page was not created is not created below the homepage', () => {
        expect(prepareItem(bread, [{item: blog, state: {state: 'failed', error: 'No.'}}, {item: bread, state: {state: 'waiting'}}], base)).toEqual({missing: 'Blog'});
        // the editor removed the page from the plan
        expect(prepareItem(bread, [{item: bread, state: {state: 'waiting'}}], base)).toEqual({missing: 'blog'});
    });
});
