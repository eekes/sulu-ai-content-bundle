import {createSnippets} from '../utils/snippets';

const snippets = [
    {key: 'a', template: 'faq', properties: {title: 'A'}},
    {key: 'b', template: 'faq', properties: {title: 'B'}},
    {key: 'c', template: 'faq', properties: {title: 'C'}},
];

test('creates the kept snippets in order, with their template', async () => {
    const posted = [];
    const result = await createSnippets(snippets, ['b'], {}, (data) => {
        posted.push(data);

        return Promise.resolve({id: 'uuid-' + data.title});
    });

    expect(posted).toEqual([{template: 'faq', title: 'A'}, {template: 'faq', title: 'C'}]);
    expect(result).toEqual({created: {a: 'uuid-A', c: 'uuid-C'}, failed: false});
});

test('applying again does not create a snippet twice', async () => {
    const post = jest.fn((data) => Promise.resolve({id: 'uuid-' + data.title}));
    const result = await createSnippets(snippets, [], {a: 'uuid-A', b: 'uuid-B'}, post);

    expect(post).toHaveBeenCalledTimes(1);
    expect(result.created).toEqual({a: 'uuid-A', b: 'uuid-B', c: 'uuid-C'});
});

test('a failing snippet is left out and reported, the others go on', async () => {
    const result = await createSnippets(snippets, [], {}, (data) => (
        data.title === 'B' ? Promise.reject(new Error('500')) : Promise.resolve({id: 'uuid-' + data.title})
    ));

    expect(result).toEqual({created: {a: 'uuid-A', c: 'uuid-C'}, failed: true});
});
