// @flow
import React from 'react';
import log from 'loglevel';
import ReactDOM from 'react-dom';
import {action, computed, observable, reaction, toJS} from 'mobx';
import {AbstractFormToolbarAction} from 'sulu-admin-bundle/views';
import {fieldRegistry} from 'sulu-admin-bundle/containers';
import {Requester, ResourceRequester} from 'sulu-admin-bundle/services';
import {translate} from 'sulu-admin-bundle/utils';
import {webspaceStore} from 'sulu-page-bundle/stores';
import AssistantPanel from '../components/AssistantPanel';
import ConversationStore from '../stores/ConversationStore';
import assistantConfig from '../stores/assistantConfig';
import {buildChanges, isEmptyContent, listBlocks, resolveSnippetRefs, snapshotOf} from '../utils/applyContent';
import {createSnippets} from '../utils/snippets';
import {fieldNamesOfType, mergePageTreeRoute, routeParts, shouldGeneratePageTreeSuffix, shouldGenerateRoute} from '../utils/route';
import {handOver, takeOver} from '../utils/handoff';
import {staleSmartContent} from '../utils/smartContent';
import type {NewSnippet} from '../utils/snippets';
import type {Reply, TurnRequest} from '../stores/ConversationStore';
import type {SendRequest} from '../components/AssistantPanel';

const DOCK_STORAGE_KEY = 'eekes_ai_content.dock';
const MODEL_STORAGE_KEY = 'eekes_ai_content.model';

type Applied = {
    changes: {[path: string]: mixed},
    snapshot: {[path: string]: mixed},
    template: ?string,
    previousTemplate: ?string,
};

function readStorage(key: string): ?string {
    try {
        return window.localStorage.getItem(key);
    } catch (error) {
        return undefined;
    }
}

function writeStorage(key: string, value: string) {
    try {
        window.localStorage.setItem(key, value);
    } catch (error) {
        // a blocked storage only costs the remembered preference
    }
}

/**
 * The "AI" button of a page, article or snippet form, and the assistant panel it opens.
 *
 * Content the assistant proposes goes straight into the form - nothing is saved: the preview
 * shows the result and the editor saves or publishes as always. Every applied reply can be
 * undone from the panel.
 */
export default class AssistantToolbarAction extends AbstractFormToolbarAction {
    @observable open: boolean = false;
    @observable dock: 'left' | 'right' = readStorage(DOCK_STORAGE_KEY) === 'left' ? 'left' : 'right';
    @observable modelKey: ?string = readStorage(MODEL_STORAGE_KEY);
    @observable applied: {[runId: string]: 'applied' | 'undone'} = {};
    @observable conversation: ?ConversationStore = undefined;
    @observable excludedSnippets: {[runId: string]: Array<string>} = {};
    @observable createdSnippets: {[runId: string]: {[key: string]: string}} = {};
    @observable snippetState: {[runId: string]: 'creating' | 'created' | 'failed'} = {};
    @observable staleLists: {[runId: string]: Array<string>} = {};
    appliedChanges: {[runId: string]: Applied} = {};
    sourceData: ?Object = undefined;

    constructor(...args: Array<any>) {
        super(...args);

        // a new page gets its id when it is first saved: the conversation goes along to the edit view
        this.disposeSaveReaction = reaction(() => this.resourceFormStore.id, (id) => {
            if (id) {
                this.handleSaved(id);
            }
        });

        const handoff = takeOver(this.resourceFormStore.resourceKey, this.resourceFormStore.id, this.locale);

        if (handoff) {
            this.open = handoff.open;
            this.getConversation().open(handoff.conversationId);
        }
    }

    @computed get locale(): string {
        const locale = this.resourceFormStore.locale;

        return locale ? locale.get() : '';
    }

    @computed get webspaceKey(): ?string {
        const {webspace} = this.router.attributes;

        if (typeof webspace === 'string' && webspace !== '') {
            return webspace;
        }

        const data = this.resourceFormStore.data;

        if (typeof data.mainWebspace === 'string' && data.mainWebspace !== '') {
            return data.mainWebspace;
        }

        const webspaces = webspaceStore.grantedWebspaces;

        return webspaces.length === 1 ? webspaces[0].key : undefined;
    }

    @computed get formKey(): string {
        const {formKey} = this.options;

        return typeof formKey === 'string' ? formKey : this.resourceFormStore.formKey;
    }

    @computed get translateLocales(): Array<string> {
        const {availableLocales} = this.resourceFormStore.data;

        if (!this.resourceFormStore.id || !Array.isArray(availableLocales)) {
            return [];
        }

        return availableLocales.filter((locale) => locale !== this.locale);
    }

    @computed get currentModelKey(): ?string {
        const keys = assistantConfig.models.map((model) => model.key);

        return this.modelKey && keys.includes(this.modelKey) ? this.modelKey : assistantConfig.defaultModel;
    }

    createConversation(): ConversationStore {
        return new ConversationStore(
            {
                formKey: this.formKey,
                locale: this.locale,
                webspace: this.webspaceKey,
                resourceId: this.resourceFormStore.id,
            },
            (reply, request) => this.handleReply(reply, request)
        );
    }

    // also called while rendering the panel: MobX runs in strict mode in the Sulu admin
    getConversation = action((): ConversationStore => {
        if (!this.conversation) {
            this.conversation = this.createConversation();
        }

        return this.conversation;
    });

    handleSaved(id: string | number) {
        const {conversation} = this;

        if (!conversation) {
            return;
        }

        conversation.attach(id);

        if (!conversation.id) {
            return;
        }

        handOver({
            resourceKey: this.resourceFormStore.resourceKey,
            resourceId: String(id),
            locale: this.locale,
            conversationId: conversation.id,
            open: this.open,
        });
    }

    getToolbarItemConfig() {
        if (!assistantConfig.enabled) {
            return undefined;
        }

        return {
            type: 'button',
            label: translate('eekes_ai_content.ai'),
            icon: 'su-magic',
            disabled: this.resourceFormStore.loading,
            active: this.open,
            onClick: action(() => {
                this.open = !this.open;

                if (this.open) {
                    // continue where the editor left off on this page
                    this.getConversation().openLatest();
                }
            }),
        };
    }

    getNode() {
        if (!this.open || !document.body) {
            return null;
        }

        const conversation = this.getConversation();
        const data = toJS(this.resourceFormStore.data);

        return ReactDOM.createPortal(
            <AssistantPanel
                applied={this.applied}
                blocks={listBlocks(data)}
                dock={this.dock}
                formEmpty={isEmptyContent(data)}
                key="eekes-ai-content-assistant"
                modelKey={this.currentModelKey}
                onClose={this.handleClose}
                onModelChange={this.handleModelChange}
                onOpenConversation={this.handleOpenConversation}
                onReapply={this.handleReapply}
                onToggleSnippet={this.handleToggleSnippet}
                snippets={{excluded: this.excludedSnippets, created: this.createdSnippets, state: this.snippetState}}
                staleLists={this.staleLists}
                onReset={this.handleReset}
                onSend={this.handleSend}
                onToggleDock={this.handleToggleDock}
                onUndo={this.handleUndo}
                store={conversation}
                translateLocales={this.translateLocales}
            />,
            document.body
        );
    }

    handleClose = action(() => {
        this.open = false;
    });

    handleToggleDock = action(() => {
        this.dock = this.dock === 'right' ? 'left' : 'right';
        writeStorage(DOCK_STORAGE_KEY, this.dock);
    });

    handleModelChange = action((key: string) => {
        this.modelKey = key;
        writeStorage(MODEL_STORAGE_KEY, key);
    });

    handleReset = action(() => {
        if (this.conversation) {
            this.conversation.destroy();
        }

        // a fresh store right away: creating it later, while rendering, is not an action
        this.conversation = this.createConversation();
        this.applied = {};
        this.appliedChanges = {};
        this.excludedSnippets = {};
        this.createdSnippets = {};
        this.snippetState = {};
        this.staleLists = {};
    });

    handleOpenConversation = (id: ?string) => {
        this.handleReset();

        if (id) {
            this.getConversation().open(id);
        }
    };

    handleSend = (request: SendRequest) => {
        const conversation = this.getConversation();
        const turn: TurnRequest = {
            ...request,
            template: this.resourceFormStore.type || this.resourceFormStore.formKey,
            content: toJS(this.resourceFormStore.data),
            model: this.currentModelKey,
        };

        if (request.action !== 'translate' || !request.sourceLocale) {
            this.sourceData = undefined;
            conversation.send(turn);

            return;
        }

        // the source locale is read here and sent along: the server only knows the form as it is now
        ResourceRequester.get(this.resourceFormStore.resourceKey, {
            id: this.resourceFormStore.id,
            locale: request.sourceLocale,
            webspace: this.webspaceKey,
        }).then((sourceData) => {
            this.sourceData = sourceData;
            conversation.send({...turn, sourceContent: sourceData});
        });
    };

    handleReply(reply: Reply, request: TurnRequest) {
        const runId = this.conversation && this.conversation.messages.length
            ? this.conversation.messages[this.conversation.messages.length - 1].runId
            : undefined;

        if (reply.type !== 'content' || !runId) {
            return;
        }

        const current = toJS(this.resourceFormStore.data);
        const base = request.action === 'translate' && this.sourceData ? this.sourceData : current;
        const changes = buildChanges(reply, current, base);
        const currentTemplate = this.resourceFormStore.type;

        this.appliedChanges[runId] = {
            changes,
            snapshot: snapshotOf(changes, current),
            template: reply.template && reply.template !== currentTemplate ? reply.template : undefined,
            previousTemplate: currentTemplate,
        };

        // new snippets are created in the database on apply, so that waits for the editor's click
        if ((reply.snippets || []).length > 0) {
            return;
        }

        this.apply(runId);
    }

    apply(runId: string) {
        const applied = this.appliedChanges[runId];
        const message = this.conversation ? this.conversation.findMessage(runId) : undefined;
        const snippets = message && message.reply ? message.reply.snippets || [] : [];

        if (!applied) {
            return;
        }

        if (snippets.length > 0) {
            this.createSnippets(runId, snippets)
                .then((created) => this.write(runId, {...applied, changes: resolveSnippetRefs(applied.changes, created)}))
                .catch((error) => {
                    // never leave the Apply button spinning
                    this.setSnippetState(runId, 'failed');
                    log.error('Applying the reply of the AI assistant failed.', error);
                });

            return;
        }

        this.write(runId, applied);
    }

    /**
     * Creates the new snippets the editor kept - each once, also when the reply is applied again -
     * in the locale of the page, as drafts unless configured otherwise. Resolves to their uuids by
     * key; a snippet that fails is left out and reported in the panel.
     */
    createSnippets(runId: string, snippets: Array<NewSnippet>): Promise<{[key: string]: string}> {
        // "requestAction", not "action": that name is MobX's action() in this file
        const requestAction = assistantConfig.publishNewSnippets ? 'publish' : undefined;

        this.setSnippetState(runId, 'creating');

        return createSnippets(
            snippets,
            this.excludedSnippets[runId] || [],
            this.createdSnippets[runId] || {},
            (data) => ResourceRequester.post('snippets', data, {locale: this.locale, action: requestAction})
        ).then(action(({created, failed}) => {
            this.createdSnippets = {...this.createdSnippets, [runId]: created};
            this.setSnippetState(runId, failed ? 'failed' : 'created');

            return created;
        }));
    }

    @action setStaleLists(runId: string, paths: Array<string>) {
        this.staleLists = {...this.staleLists, [runId]: paths};
    }

    @action setSnippetState(runId: string, state: 'creating' | 'created' | 'failed') {
        this.snippetState = {...this.snippetState, [runId]: state};
    }

    handleToggleSnippet = action((runId: string, key: string) => {
        const excluded = this.excludedSnippets[runId] || [];

        this.excludedSnippets = {
            ...this.excludedSnippets,
            [runId]: excluded.includes(key) ? excluded.filter((item) => item !== key) : [...excluded, key],
        };
    });

    write(runId: string, applied: Applied) {
        if (applied.template && this.resourceFormStore.hasTypes) {
            this.resourceFormStore.changeType(applied.template);
        }

        const current = toJS(this.resourceFormStore.data);
        const changes = {...applied.changes};
        const treeRoutes = fieldNamesOfType(toJS(this.resourceFormStore.schema), 'page_tree_route');

        // the assistant picks the page an article's URL sits below; the rest of the URL stays
        treeRoutes.filter((name) => name in changes).forEach((name) => {
            changes[name] = mergePageTreeRoute(changes[name], current[name]);
        });

        const staleLists = staleSmartContent(changes, current);

        this.resourceFormStore.changeMultiple(changes);
        this.setStaleLists(runId, staleLists);

        this.generateRoute(Object.keys(changes));
        treeRoutes.forEach((name) => this.generatePageTreeSuffix(name, Object.keys(changes), current[name]));

        this.setApplied(runId, 'applied');
    }

    /**
     * Fills in the URL the way Sulu's URL field would after an editor typed the title. That field
     * listens to "finished" fields on the form's own inspector, which this toolbar action has no
     * access to (it gets a copy), so the same generation endpoint is called here.
     */
    generateRoute(changedPaths: Array<string>) {
        const partPaths = this.resourceFormStore.getPathsByTag('sulu.rlp.part');
        const routePath = this.resourceFormStore.getPathsByTag('sulu.rlp')[0];

        if (!routePath || !fieldRegistry.has('route')) {
            return;
        }

        const data = toJS(this.resourceFormStore.data);
        const routeName = routePath.startsWith('/') ? routePath.substring(1) : routePath;

        if (!shouldGenerateRoute(partPaths, changedPaths, this.resourceFormStore.id, data[routeName])) {
            return;
        }

        const {generationUrl} = fieldRegistry.getOptions('route');
        const parts = routeParts(partPaths, data);

        if (typeof generationUrl !== 'string' || Object.keys(parts).length === 0) {
            return;
        }

        Requester.post(generationUrl, {
            ...this.resourceFormStore.options,
            parts,
            resourceKey: this.resourceFormStore.resourceKey,
            locale: this.locale,
            resourceId: this.resourceFormStore.id,
            parentId: this.router.attributes.parentId || data.parentId,
        }).then((response) => {
            if (typeof response.resourceLocator === 'string') {
                this.resourceFormStore.change(routeName, response.resourceLocator);
            }
        }).catch((error) => {
            log.warn('The AI assistant could not generate the URL.', error);
        });
    }

    /**
     * Makes the rest of an article's URL below the page the assistant picked, with the endpoint and
     * the parameters Sulu's own page_tree_route field uses.
     */
    generatePageTreeSuffix(name: string, changedPaths: Array<string>, previous: mixed) {
        const partPaths = this.resourceFormStore.getPathsByTag('sulu.rlp.part');
        const data = toJS(this.resourceFormStore.data);
        const value = data[name];

        if (!fieldRegistry.has('page_tree_route')
            || !shouldGeneratePageTreeSuffix(partPaths, changedPaths, this.resourceFormStore.id, previous, value)
        ) {
            return;
        }

        // the page bundle may register its field without the URL; the endpoint is the route field's
        const treeOptions = fieldRegistry.getOptions('page_tree_route') || {};
        const generationUrl = typeof treeOptions.generationUrl === 'string'
            ? treeOptions.generationUrl
            : fieldRegistry.has('route') ? fieldRegistry.getOptions('route').generationUrl : undefined;
        const parts = routeParts(partPaths, data);

        if (typeof generationUrl !== 'string' || Object.keys(parts).length === 0) {
            return;
        }

        Requester.post(generationUrl, {
            ...this.resourceFormStore.options,
            parts,
            resourceKey: this.resourceFormStore.resourceKey,
            locale: this.locale,
            resourceId: this.resourceFormStore.id,
            parentId: value.page.uuid,
            parentKey: 'pages',
            relative: true,
        }).then((response) => {
            if (typeof response.resourceLocator === 'string') {
                this.resourceFormStore.change(name, {...toJS(this.resourceFormStore.data[name]), suffix: response.resourceLocator});
            }
        }).catch((error) => {
            log.warn('The AI assistant could not generate the URL of the article.', error);
        });
    }

    handleReapply = (runId: string) => {
        const message = this.conversation ? this.conversation.findMessage(runId) : undefined;

        // a reply from an earlier session: its changes are worked out against the form as it is now
        if (!this.appliedChanges[runId] && message && message.reply && message.reply.type === 'content') {
            const current = toJS(this.resourceFormStore.data);
            const changes = buildChanges(message.reply, current, current);
            const currentTemplate = this.resourceFormStore.type;

            this.appliedChanges[runId] = {
                changes,
                snapshot: snapshotOf(changes, current),
                template: message.reply.template && message.reply.template !== currentTemplate ? message.reply.template : undefined,
                previousTemplate: currentTemplate,
            };
        }

        this.apply(runId);
    };

    handleUndo = (runId: string) => {
        const applied = this.appliedChanges[runId];

        if (!applied) {
            return;
        }

        if (applied.template && applied.previousTemplate && this.resourceFormStore.hasTypes) {
            this.resourceFormStore.changeType(applied.previousTemplate);
        }

        this.resourceFormStore.changeMultiple(applied.snapshot);
        this.setApplied(runId, 'undone');
    };

    @action setApplied(runId: string, state: 'applied' | 'undone') {
        this.applied = {...this.applied, [runId]: state};
    }

    destroy() {
        this.disposeSaveReaction();

        if (this.conversation) {
            this.conversation.destroy();
        }
    }
}
