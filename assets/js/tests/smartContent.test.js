import {staleSmartContent} from '../utils/smartContent';

test('a list on screen with other filters is reported', () => {
    expect(staleSmartContent(
        {overview: {dataSource: 'page-1', limitResult: 3}},
        {overview: {dataSource: 'page-1', limitResult: 6, presentAs: undefined}}
    )).toEqual(['overview']);
});

test('a list on screen without filters is reported too', () => {
    expect(staleSmartContent({overview: {limitResult: 3}}, {overview: {}})).toEqual(['overview']);
});

test('the same filters, in another order or with empty values, are not', () => {
    expect(staleSmartContent(
        {overview: {limitResult: 6, dataSource: 'page-1', tags: []}},
        {overview: {dataSource: 'page-1', limitResult: 6, categories: undefined}}
    )).toEqual([]);
});

test('a list that is not on screen yet is not', () => {
    expect(staleSmartContent({overview: {limitResult: 3}}, {})).toEqual([]);
    expect(staleSmartContent(
        {blocks: [{type: 'text'}, {type: 'page_overview', pages: {limitResult: 3}}]},
        {blocks: [{type: 'text'}]}
    )).toEqual([]);
});

test('lists in blocks are compared by position', () => {
    expect(staleSmartContent(
        {blocks: [{type: 'hero', title: 'A'}, {type: 'latest_articles', articles: {tags: [2], sortBy: 'published'}}]},
        {blocks: [{type: 'hero', title: 'B'}, {type: 'latest_articles', articles: {tags: [1], sortBy: 'published'}}]}
    )).toEqual(['blocks[1].articles']);
});

test('links and other objects are not lists', () => {
    expect(staleSmartContent(
        {button: {provider: 'page', href: 'b'}, seo: {title: 'New'}},
        {button: {provider: 'page', href: 'a'}, seo: {title: 'Old'}}
    )).toEqual([]);
});
