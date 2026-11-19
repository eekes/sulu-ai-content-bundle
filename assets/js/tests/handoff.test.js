import {handOver, takeOver} from '../utils/handoff';

function memoryStorage() {
    const items = {};

    return {
        getItem: (key) => (key in items ? items[key] : null),
        setItem: (key, value) => {
            items[key] = value;
        },
        removeItem: (key) => {
            delete items[key];
        },
    };
}

const handoff = {resourceKey: 'pages', resourceId: 'page-1', locale: 'en', conversationId: 'conversation-1', open: true};

test('the edit view of the saved page takes the conversation over, once', () => {
    const storage = memoryStorage();
    handOver(handoff, storage);

    expect(takeOver('pages', 'page-1', 'en', storage)).toEqual(handoff);
    expect(takeOver('pages', 'page-1', 'en', storage)).toBeUndefined();
});

test('another page, locale or resource does not get it', () => {
    const storage = memoryStorage();

    handOver(handoff, storage);
    expect(takeOver('pages', 'page-2', 'en', storage)).toBeUndefined();

    handOver(handoff, storage);
    expect(takeOver('pages', 'page-1', 'nl', storage)).toBeUndefined();

    handOver(handoff, storage);
    expect(takeOver('snippets', 'page-1', 'en', storage)).toBeUndefined();
});

test('a numeric id matches and a resource without id gets nothing', () => {
    const storage = memoryStorage();

    handOver({...handoff, resourceKey: 'contacts', resourceId: '12'}, storage);
    expect(takeOver('contacts', undefined, 'en', storage)).toBeUndefined();
    expect(takeOver('contacts', 12, 'en', storage)).toEqual({...handoff, resourceKey: 'contacts', resourceId: '12'});
});

test('garbage in the storage is ignored', () => {
    const storage = memoryStorage();
    storage.setItem('eekes_ai_content.handoff', '{nope');

    expect(takeOver('pages', 'page-1', 'en', storage)).toBeUndefined();
});
