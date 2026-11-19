// @flow
import {action, computed, observable} from 'mobx';
import {Requester, resourceRouteRegistry} from 'sulu-admin-bundle/services';
import {translate} from 'sulu-admin-bundle/utils';
import ConversationStore from './ConversationStore';
import {createPlanApi} from '../services/planApi';
import {orderPlan, prepareItem, restoreProgress, runPlannedItem, storableProgress} from '../utils/plan';
import type {ItemState, PageOption, PlanItem, TemplateOption} from '../utils/plan';

const PLAN_OPTIONS = 'eekes_ai_content_plan_options';
const CONVERSATIONS = 'eekes_ai_content_conversations';

export type PlannerScope = {
    formKey: string,
    resourceKey: string,
    webspace: ?string,
    locale: string,
    templateKeys: Array<string>,
    // the page selected in the page tree, where new pages go unless the editor says otherwise
    selectedPage?: ?string,
};

/**
 * The planner: one conversation per webspace that plans several new pages or articles, then
 * writes and creates them as drafts one at a time.
 */
export default class PlannerStore {
    scope: PlannerScope;
    @observable conversation: ConversationStore;
    @observable templates: Array<TemplateOption> = [];
    // for a plan of articles: the templates of the page they go below, when the plan creates it
    @observable pageTemplates: Array<TemplateOption> = [];
    @observable pages: Array<PageOption> = [];
    // every approved plan of the conversation and how far it got, by the run of the plan reply
    @observable progress: {[runId: string]: Array<{item: PlanItem, state: ItemState}>} = {};
    // called whenever a page or article was created, so the list or tree on screen can show it
    onCreated: ?(id: string) => void = undefined;

    constructor(scope: PlannerScope) {
        this.scope = scope;
        this.conversation = this.createConversation();
        this.loadOptions();
    }

    createConversation(): ConversationStore {
        const {formKey, locale, webspace} = this.scope;

        // the planner keeps its own history, per webspace, under a resource id of its own
        return new ConversationStore({formKey, locale, webspace, resourceId: 'plan:' + (webspace || '')});
    }

    @computed get executing(): boolean {
        return Object.keys(this.progress).some((runId) => this.progress[runId].some(
            ({state}) => ['waiting', 'writing', 'creating'].includes(state.state)
        ));
    }

    /**
     * Opens the latest planner conversation of the webspace, with the progress of its plans.
     */
    openLatest(): Promise<void> {
        return this.conversation.openLatest().then(action(() => {
            const progress = {};
            const stored = this.conversation.planProgress || {};

            Object.keys(stored).forEach((runId) => {
                progress[runId] = restoreProgress(stored[runId], {
                    interrupted: translate('eekes_ai_content.plan_interrupted'),
                    notStarted: translate('eekes_ai_content.plan_not_started'),
                });
            });

            this.progress = progress;
        }));
    }

    loadOptions() {
        const {formKey, locale, webspace, templateKeys} = this.scope;

        Requester.get(resourceRouteRegistry.getUrl('list', PLAN_OPTIONS, {formKey, locale, webspace, templateKeys: templateKeys.join(',')}))
            .then(action((options) => {
                this.templates = options.templates || [];
                this.pageTemplates = options.pageTemplates || [];
                this.pages = options.pages || [];
            }))
            .catch(() => undefined);
    }

    send(message: string, model: ?string, answers?: Array<Object>) {
        this.conversation.send({
            action: 'plan_pages',
            message,
            template: '',
            content: {},
            model,
            answers,
            templateKeys: this.scope.templateKeys,
            selectedPage: this.scope.selectedPage,
        });
    }

    @action reset() {
        this.conversation.destroy();
        this.conversation = this.createConversation();
        this.progress = {};
    }

    /**
     * Writes and creates every page of the approved plan, one after the other - a page the others
     * go below first. Its progress is kept with the conversation, so a reload shows what was
     * created instead of the plan again.
     */
    @action execute(plan: Array<PlanItem>, model: ?string, runId: string): Promise<void> {
        plan = orderPlan(plan);
        this.progress = {...this.progress, [runId]: plan.map((item) => ({item, state: {state: 'waiting'}}))};
        this.save(runId);

        return plan.reduce(
            (chain, item, index) => chain.then(() => this.run(runId, index, model)),
            Promise.resolve()
        ).then(() => undefined);
    }

    /**
     * Tries a page that failed - or was interrupted - again.
     */
    retry(runId: string, index: number, model: ?string) {
        this.run(runId, index, model);
    }

    run(runId: string, index: number, model: ?string): Promise<mixed> {
        const {formKey, resourceKey, locale, webspace} = this.scope;
        const items = this.progress[runId];
        const plan = items.map(({item}) => item);
        const base = {formKey, resourceKey, locale, webspace, model, templates: this.templates, pageTemplates: this.pageTemplates, pages: this.pages};
        const prepared = prepareItem(plan[index], items, base);

        if (!prepared.item || !prepared.context) {
            // never below another page instead: it waits for its page, which can be tried again
            this.setState(runId, index, {state: 'failed', error: translate('eekes_ai_content.plan_page_missing', {title: prepared.missing || ''})});

            return Promise.resolve();
        }

        const {item, context} = prepared;

        return runPlannedItem(item, plan, context, createPlanApi(context.resourceKey, locale, webspace), (state) => this.setState(runId, index, state));
    }

    @action setState(runId: string, index: number, state: ItemState) {
        const items = [...this.progress[runId]];
        const previous = items[index].state.state;

        items[index] = {...items[index], state};
        this.progress = {...this.progress, [runId]: items};

        // the passing progress text is not worth a request
        if (previous !== state.state) {
            this.save(runId);
        }

        if ((state.state === 'done' || state.state === 'needs_input') && this.onCreated) {
            this.onCreated(state.id);
        }
    }

    save(runId: string) {
        const {id} = this.conversation;

        if (!id) {
            return;
        }

        Requester.patch(
            resourceRouteRegistry.getUrl('detail', CONVERSATIONS, {id}),
            {planProgress: {runId, items: storableProgress(this.progress[runId])}}
        ).catch(() => undefined);
    }

    destroy() {
        this.conversation.destroy();
    }
}
