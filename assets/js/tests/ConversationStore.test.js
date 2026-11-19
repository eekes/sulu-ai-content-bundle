import {configure} from 'mobx';
import ConversationStore from '../stores/ConversationStore';
import services from './suluServicesMock';

// as in the Sulu admin
configure({enforceActions: 'observed'});

beforeEach(() => {
    services.calls.length = 0;
    services.responses.length = 0;
});

function scope(resourceId) {
    return {formKey: 'page', locale: 'en', webspace: 'website', resourceId};
}

test('a conversation of a new page is linked once the page is saved', async () => {
    const store = new ConversationStore(scope(undefined));
    services.responses.push({id: 'conversation-1'});
    await store.ensureConversation();

    await store.attach('page-1');

    expect(store.scope.resourceId).toBe('page-1');
    expect(services.calls[1]).toEqual({
        method: 'PATCH',
        url: 'detail:eekes_ai_content_conversations {"id":"conversation-1"}',
        data: {resourceId: 'page-1'},
    });
});

test('a conversation that is not started yet only remembers the page', async () => {
    const store = new ConversationStore(scope(undefined));

    await store.attach('page-1');

    expect(store.scope.resourceId).toBe('page-1');
    expect(services.calls).toEqual([]);
});

test('a linked conversation keeps its page', async () => {
    const store = new ConversationStore(scope('page-1'));

    await store.attach('page-2');

    expect(store.scope.resourceId).toBe('page-1');
    expect(services.calls).toEqual([]);
});

test('opening an earlier conversation and resetting work in strict mode', async () => {
    const store = new ConversationStore(scope('page-1'));
    services.responses.push({id: 'conversation-1', messages: [{role: 'user', content: 'Hi', runId: 'run-1'}]});

    await store.open('conversation-1');
    expect(store.id).toBe('conversation-1');
    expect(store.messages).toHaveLength(1);

    store.reset();
    expect(store.empty).toBe(true);
});

const flush = () => new Promise((resolve) => setImmediate(resolve));

test('a conversation opened while its turn is still running waits for the reply', async () => {
    const replies = [];
    const store = new ConversationStore(scope('page-1'), (reply) => replies.push(reply));
    services.responses.push(
        {id: 'conversation-1', messages: [{role: 'user', content: 'Write the page', runId: 'run-1'}], runningTurn: 'run-1'},
        {id: 'run-1', finished: false, progress: 'Thinking'}
    );

    await store.open('conversation-1');
    await flush();

    expect(store.running).toBe(true);
    expect(store.progress).toBe('Thinking');
    expect(services.calls[1]).toEqual({method: 'GET', url: 'detail:eekes_ai_content_turns {"id":"run-1"}', data: undefined});

    const reply = {type: 'content', message: 'Done', properties: {}, suggestions: []};
    services.responses.push({id: 'run-1', finished: true, status: 'succeeded', reply, usage: {model: 'smart'}});
    store.stopPolling();
    store.poll('run-1', null);
    await flush();

    expect(store.running).toBe(false);
    expect(store.messages[1]).toEqual({role: 'assistant', content: 'Done', reply, usage: {model: 'smart'}, runId: 'run-1'});
    // the form may have changed meanwhile: the editor applies the reply
    expect(replies).toEqual([]);
});

test('a conversation without a running turn is not polled', async () => {
    const store = new ConversationStore(scope('page-1'));
    services.responses.push({id: 'conversation-1', messages: [], runningTurn: null});

    await store.open('conversation-1');
    await flush();

    expect(store.running).toBe(false);
    expect(services.calls).toHaveLength(1);
});
