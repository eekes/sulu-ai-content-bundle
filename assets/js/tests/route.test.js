import {fieldNamesOfType, mergePageTreeRoute, routeParts, shouldGeneratePageTreeSuffix, shouldGenerateRoute} from '../utils/route';

describe('routeParts', () => {
    test('the values of the part fields by name, empty ones left out', () => {
        expect(routeParts(['/title', '/subtitle', '/missing'], {title: 'Fresh bread', subtitle: ' '})).toEqual({title: 'Fresh bread'});
    });
});

describe('shouldGenerateRoute', () => {
    test('a new page gets a URL when its title changes', () => {
        expect(shouldGenerateRoute(['/title'], ['title', 'blocks'], undefined, '')).toBe(true);
    });

    test('a saved page with a URL keeps it', () => {
        expect(shouldGenerateRoute(['/title'], ['title'], 'page-1', '/bread')).toBe(false);
    });

    test('a saved page without a URL gets one', () => {
        expect(shouldGenerateRoute(['/title'], ['title'], 'page-1', '')).toBe(true);
    });

    test('nothing happens when the title did not change, or there are no URL parts', () => {
        expect(shouldGenerateRoute(['/title'], ['blocks'], undefined, '')).toBe(false);
        expect(shouldGenerateRoute([], ['title'], undefined, '')).toBe(false);
    });
});

describe('the URL of an article', () => {
    const schema = {
        title: {type: 'text_line', tags: [{name: 'sulu.rlp.part'}]},
        meta: {type: 'section', items: {url: {type: 'page_tree_route'}}},
        blocks: {type: 'block', types: {}},
    };

    test('the field is found, also in a section', () => {
        expect(fieldNamesOfType(schema, 'page_tree_route')).toEqual(['url']);
        expect(fieldNamesOfType(undefined, 'page_tree_route')).toEqual([]);
    });

    test('only the page changes, the suffix stays', () => {
        expect(mergePageTreeRoute(
            {page: {uuid: 'news', path: '/news'}},
            {page: {uuid: 'blog', path: '/blog'}, suffix: '/fresh-bread'}
        )).toEqual({page: {uuid: 'news', path: '/news'}, suffix: '/fresh-bread'});
        expect(mergePageTreeRoute({page: {uuid: 'news', path: '/news'}}, null)).toEqual({page: {uuid: 'news', path: '/news'}});
    });

    const news = {page: {uuid: 'news', path: '/news'}};

    test('a new article gets its suffix when the title or the page is set', () => {
        expect(shouldGeneratePageTreeSuffix(['/title'], ['title', 'url'], undefined, undefined, news)).toBe(true);
        expect(shouldGeneratePageTreeSuffix(['/title'], ['url'], undefined, {page: {uuid: 'blog'}}, {...news, suffix: '/a'})).toBe(true);
        expect(shouldGeneratePageTreeSuffix(['/title'], ['article'], undefined, news, news)).toBe(false);
    });

    test('a saved article keeps its suffix, unless it has none', () => {
        expect(shouldGeneratePageTreeSuffix(['/title'], ['title'], 'article-1', news, {...news, suffix: '/fresh-bread'})).toBe(false);
        expect(shouldGeneratePageTreeSuffix(['/title'], ['title'], 'article-1', news, news)).toBe(true);
    });

    test('without a page there is nothing to sit below', () => {
        expect(shouldGeneratePageTreeSuffix(['/title'], ['title'], undefined, undefined, {suffix: ''})).toBe(false);
    });
});
