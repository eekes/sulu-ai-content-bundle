// @flow
import {action, computed, observable} from 'mobx';
import {Requester, resourceRouteRegistry} from 'sulu-admin-bundle/services';

const CONVERSATIONS = 'eekes_ai_content_conversations';
const TURNS = 'eekes_ai_content_turns';
const HISTORY = 'eekes_ai_content_conversation_history';
const POLL_INTERVAL = 1200;

export type Reply = {
    type: 'questions' | 'outline' | 'content' | 'message' | 'plan',
    message: string,
    questions: Array<{question: string, options: Array<string>, multiple: boolean}>,
    outline: ?{template: ?string, blocks: Array<{type: string, purpose: string, snippets?: Array<{title: string, uuid: ?string}>}>},
    template: ?string,
    properties: Object,
    seo: Object,
    excerpt: Object,
    suggestions: Array<string>,
    warnings: Array<string>,
    snippets: Array<{key: string, template: string, properties: Object}>,
    plan?: Array<{title: string, template: string, templateTitle?: string, parent: ?string, parentTitle?: ?string, brief: string}>,
};

export type Usage = {
    model: string,
    tokens: {input: number, output: number, cacheRead: number, cacheWrite: number, thinking: number, total: number},
    requests: number,
    cost: ?number,
    currency: string,
    durationMs: ?number,
};

export type Message = {
    role: 'user' | 'assistant' | 'error',
    content: string,
    reply?: ?Reply,
    usage?: ?Usage,
    runId?: string,
    // part of a conversation opened again, from an earlier session
    restored?: boolean,
};

export type HistoryItem = {id: string, title: string, turns: number, createdAt: string, updatedAt: string};

export type TurnRequest = {
    action: string,
    message: string,
    template: string,
    content: Object,
    model?: ?string,
    focusBlock?: ?number,
    focusProperty?: ?string,
    sourceContent?: ?Object,
    sourceLocale?: ?string,
    answers?: Array<{question: string, answer: string, remember: boolean}>,
    templateKeys?: Array<string>,
    selectedPage?: ?string,
    resourceId?: ?string | ?number,
};

type Scope = {formKey: string, locale: string, webspace: ?string, resourceId: ?string | ?number};

/**
 * One conversation with the assistant: starts it on the first message, sends every turn and
 * polls the run of that turn until it is finished.
 */
export default class ConversationStore {
    scope: Scope;
    @observable id: ?string = undefined;
    @observable messages: Array<Message> = [];
    @observable running: boolean = false;
    @observable progress: ?string = undefined;
    @observable toolCalls: Array<string> = [];
    @observable history: Array<HistoryItem> = [];
    @observable loading: boolean = false;
    // the planner's approved plans and how far they got, by the run of the plan reply
    @observable planProgress: {[runId: string]: Array<Object>} = {};
    pollTimeout: ?TimeoutID = undefined;
    onReply: ?(reply: Reply, request: TurnRequest) => void;

    constructor(scope: Scope, onReply?: (reply: Reply, request: TurnRequest) => void) {
        this.scope = scope;
        this.onReply = onReply;
    }

    @computed get empty(): boolean {
        return this.messages.length === 0;
    }

    @computed get lastReply(): ?Reply {
        for (let index = this.messages.length - 1; index >= 0; index--) {
            const message = this.messages[index];

            if (message.role === 'assistant' && message.reply) {
                return message.reply;
            }
        }

        return undefined;
    }

    @action reset() {
        this.stopPolling();
        this.id = undefined;
        this.messages = [];
        this.planProgress = {};
        this.running = false;
        this.progress = undefined;
        this.toolCalls = [];
    }

    @action send(request: TurnRequest): Promise<void> {
        if (this.running) {
            return Promise.resolve();
        }

        this.running = true;
        this.progress = undefined;
        this.toolCalls = [];
        this.messages.push({role: 'user', content: request.message || this.describeAction(request.action)});

        return this.ensureConversation()
            .then((id) => Requester.post(
                resourceRouteRegistry.getUrl('list', TURNS, {conversationId: id}),
                {...request, resourceId: this.scope.resourceId}
            ))
            .then((turn) => this.handleTurn(turn, request))
            .catch(action((error) => {
                this.running = false;
                this.handleError(error);
            }));
    }

    /**
     * The earlier conversations of this user on this page, newest first. A page that has not been
     * saved yet has none.
     */
    loadHistory(): Promise<Array<HistoryItem>> {
        const {formKey, locale, resourceId} = this.scope;

        if (!resourceId) {
            return Promise.resolve([]);
        }

        return Requester.get(resourceRouteRegistry.getUrl('list', HISTORY, {formKey, locale, resourceId}))
            .then(action((response) => {
                this.history = response.conversations || [];

                return this.history;
            }))
            .catch(() => []);
    }

    /**
     * Opens an earlier conversation: its messages, with the usage of every reply, and the next turn
     * continues it.
     */
    @action open(id: string): Promise<void> {
        this.stopPolling();
        this.loading = true;

        return Requester.get(resourceRouteRegistry.getUrl('detail', CONVERSATIONS, {id}))
            .then(action((conversation) => {
                this.id = conversation.id;
                this.running = false;
                this.progress = undefined;
                this.messages = (conversation.messages || []).map((message) => ({
                    role: message.role,
                    content: message.content,
                    reply: message.reply,
                    usage: message.usage,
                    runId: message.runId,
                    restored: true,
                }));
                this.planProgress = conversation.planProgress || {};
                this.loading = false;

                // the panel was closed while a turn ran on: wait for its reply again
                if (conversation.runningTurn) {
                    this.running = true;
                    this.poll(conversation.runningTurn, null);
                }
            }))
            .catch(action((error) => {
                this.loading = false;
                this.handleError(error);
            }));
    }

    /**
     * The most recent conversation on this page, opened again - so closing the panel or reloading
     * the page does not lose it.
     */
    openLatest(): Promise<void> {
        return this.loadHistory().then((history) => (history.length > 0 && this.empty ? this.open(history[0].id) : undefined));
    }

    /**
     * Links the conversation to the page, article or snippet it was started on, once that is saved
     * and has an id - so it shows up in the history of that page. A linked conversation keeps it.
     */
    attach(resourceId: string | number): Promise<void> {
        if (this.scope.resourceId) {
            return Promise.resolve();
        }

        this.scope.resourceId = resourceId;
        const {id} = this;

        if (!id) {
            return Promise.resolve();
        }

        return Requester.patch(resourceRouteRegistry.getUrl('detail', CONVERSATIONS, {id}), {resourceId})
            .then(() => undefined)
            .catch(() => undefined);
    }

    findMessage(runId: string): ?Message {
        return this.messages.find((message) => message.runId === runId && message.role === 'assistant');
    }

    describeAction(actionName: string): string {
        return actionName;
    }

    ensureConversation(): Promise<string> {
        const {id} = this;

        if (id) {
            return Promise.resolve(id);
        }

        return Requester.post(resourceRouteRegistry.getUrl('list', CONVERSATIONS), this.scope)
            .then(action((conversation) => {
                this.id = conversation.id;

                return conversation.id;
            }));
    }

    /**
     * A turn as the server reports it. Without a request it is a turn that was sent before the
     * conversation was opened again: its reply is shown, never applied to a form that may have
     * changed since - the editor applies it.
     */
    @action handleTurn = (turn: Object, request: ?TurnRequest) => {
        this.progress = turn.progress;
        this.toolCalls = turn.toolCalls || [];

        if (!turn.finished) {
            this.pollTimeout = setTimeout(() => this.poll(turn.id, request), POLL_INTERVAL);

            return;
        }

        this.running = false;
        this.progress = undefined;

        if (turn.status === 'failed') {
            this.messages.push({role: 'error', content: turn.error || 'The assistant failed.', usage: turn.usage, runId: turn.id});

            return;
        }

        const reply: Reply = turn.reply;
        this.messages.push({role: 'assistant', content: reply.message, reply, usage: turn.usage, runId: turn.id});

        if (this.onReply && request) {
            this.onReply(reply, request);
        }
    };

    poll(runId: string, request: ?TurnRequest) {
        Requester.get(resourceRouteRegistry.getUrl('detail', TURNS, {id: runId}))
            .then((turn) => this.handleTurn(turn, request))
            .catch(action((error) => {
                this.running = false;
                this.handleError(error);
            }));
    }

    @action handleError(error: mixed) {
        if (error && typeof error === 'object' && typeof error.json === 'function') {
            error.json()
                .then(action((body) => {
                    this.messages.push({role: 'error', content: body.message || body.detail || 'The assistant failed.'});
                }))
                .catch(action(() => {
                    this.messages.push({role: 'error', content: 'The assistant failed.'});
                }));

            return;
        }

        this.messages.push({role: 'error', content: error instanceof Error ? error.message : 'The assistant failed.'});
    }

    stopPolling() {
        if (this.pollTimeout) {
            clearTimeout(this.pollTimeout);
            this.pollTimeout = undefined;
        }
    }

    destroy() {
        this.stopPolling();
    }
}
