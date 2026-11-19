// @flow
/*
 * Writing and creating the pages of an approved plan, one at a time. Every request is passed in
 * (the "api"), so this is tested without Sulu (tests/plan.test.js).
 *
 * A planned page gets a conversation of its own, written in one turn. Its new snippets are
 * created, its URL is made the way Sulu's URL field makes it, and the page is created through
 * Sulu's own API as an unpublished draft. The conversation is then linked to the new page, so the
 * assistant on that page continues where the plan left off. When the model asks a question instead
 * of writing, the page is created with its title only and the question waits there.
 *
 * A plan of articles can hold one page ("kind": "page"): the overview the articles go below when
 * it does not exist yet. It is created first, as a page; the articles refer to it as
 * "new:<key>" and go below it once it exists - never below another page instead.
 */
import {buildChanges, resolveSnippetRefs} from './applyContent';
import {createSnippets} from './snippets';

export type PlanItem = {
    title: string,
    template: string,
    templateTitle?: string,
    parent: ?string,
    parentTitle?: ?string,
    brief: string,
    // "page" for the page a plan of articles creates for them to go below
    kind?: ?string,
    key?: ?string,
};

export type TemplateOption = {key: string, title: string, route: ?{name: string, type: 'route' | 'page_tree_route', parts: Array<string>}};

export type PageOption = {uuid: string, title: string, url: ?string, depth: number};

export type ItemState =
    | {state: 'waiting'}
    | {state: 'writing', progress?: ?string}
    | {state: 'creating'}
    // url: of a page the other items go below
    | {state: 'done', id: string, url?: ?string}
    | {state: 'needs_input', id: string, message: string, url?: ?string}
    | {state: 'failed', error: string};

export type PlanContext = {
    formKey: string,
    resourceKey: string,
    locale: string,
    webspace: ?string,
    model: ?string,
    templates: Array<TemplateOption>,
    pages: Array<PageOption>,
};

export type PlanApi = {
    startConversation: (scope: Object) => Promise<string>,
    sendTurn: (conversationId: string, request: Object) => Promise<Object>,
    getTurn: (runId: string) => Promise<Object>,
    wait: (milliseconds: number) => Promise<void>,
    generateUrl: (request: Object) => Promise<string>,
    createSnippet: (data: Object) => Promise<{id: string}>,
    createResource: (data: Object, parentId: ?string) => Promise<{id: string}>,
    attach: (conversationId: string, resourceId: string) => Promise<mixed>,
};

const POLL_INTERVAL = 1200;
const NEW_PREFIX = 'new:';

export type PlanBase = {
    formKey: string,
    resourceKey: string,
    locale: string,
    webspace: ?string,
    model: ?string,
    templates: Array<TemplateOption>,
    pageTemplates: Array<TemplateOption>,
    pages: Array<PageOption>,
};

/**
 * The order the items are created in: the page the others go below first.
 */
export function orderPlan(plan: Array<PlanItem>): Array<PlanItem> {
    return [...plan.filter((item) => item.kind === 'page'), ...plan.filter((item) => item.kind !== 'page')];
}

/**
 * What one item is written and created with. The page of a plan of articles is a page; an item
 * that goes below that page gets its id as parent, once it was created. Until then it is
 * "missing" (the title of the page, or its key when the editor removed it from the plan).
 */
export function prepareItem(
    item: PlanItem,
    items: Array<{item: PlanItem, state: ItemState}>,
    base: PlanBase
): {item: PlanItem, context: PlanContext} | {missing: string} {
    const {pageTemplates, ...context} = base;

    if (item.kind === 'page') {
        return {item, context: {...context, formKey: 'page', resourceKey: 'pages', templates: pageTemplates}};
    }

    const parent = item.parent || '';

    if (!parent.startsWith(NEW_PREFIX)) {
        return {item, context};
    }

    const key = parent.substring(NEW_PREFIX.length);
    const page = items.find((candidate) => candidate.item.kind === 'page' && candidate.item.key === key);

    if (!page) {
        return {missing: key};
    }

    const {state} = page;

    if (state.state !== 'done' && state.state !== 'needs_input') {
        return {missing: page.item.title};
    }

    return {
        item: {...item, parent: state.id},
        context: {...context, pages: [...context.pages, {uuid: state.id, title: page.item.title, url: state.url, depth: 0}]},
    };
}

/**
 * What the writer of one planned page is told: its brief, and the rest of the plan so pages do
 * not repeat each other.
 */
export function writeMessage(item: PlanItem, plan: Array<PlanItem>): string {
    const others = plan
        .filter((other) => other !== item)
        .map((other) => `- ${other.title}${other.brief ? ': ' + other.brief : ''}`);

    return [
        `Write the page "${item.title}".`,
        `Brief: ${item.brief || '(none)'}`,
        others.length > 0 ? `The other pages of the plan, written separately - do not repeat them, link to existing pages where it helps:\n${others.join('\n')}` : '',
    ].filter((part) => part !== '').join('\n\n');
}

/**
 * The values the URL is made from, by property name, from the data of the new page.
 */
export function urlParts(names: Array<string>, data: Object): {[name: string]: string} {
    const parts = {};

    names.forEach((name) => {
        if (typeof data[name] === 'string' && data[name].trim() !== '') {
            parts[name] = data[name];
        }
    });

    return parts;
}

/**
 * Writes and creates one page of the plan; reports every step through onState and resolves to the
 * final state. It never rejects: a failure is a state.
 */
export function runPlannedItem(
    item: PlanItem,
    plan: Array<PlanItem>,
    context: PlanContext,
    api: PlanApi,
    onState: (state: ItemState) => void
): Promise<ItemState> {
    const finish = (state: ItemState) => {
        onState(state);

        return state;
    };

    const poll = (turn: Object): Promise<Object> => {
        if (turn.finished) {
            return Promise.resolve(turn);
        }

        onState({state: 'writing', progress: turn.progress});

        return api.wait(POLL_INTERVAL).then(() => api.getTurn(turn.id)).then(poll);
    };

    let conversationId = '';

    onState({state: 'writing'});

    return api.startConversation({formKey: context.formKey, locale: context.locale, webspace: context.webspace, resourceId: null})
        .then((id) => {
            conversationId = id;

            return api.sendTurn(id, {
                action: 'write_planned',
                message: writeMessage(item, plan),
                template: item.template,
                content: {title: item.title},
                model: context.model,
            });
        })
        .then(poll)
        .then((turn) => {
            if (turn.status === 'failed') {
                throw new Error(turn.error || 'The assistant failed.');
            }

            onState({state: 'creating'});

            return build(turn.reply, item, context, api).then((data) => ({reply: turn.reply, data}));
        })
        .then(({reply, data}) => api.createResource(data, item.parent).then((resource) => ({reply, data, resource})))
        .then(({reply, data, resource}) => api.attach(conversationId, resource.id)
            .catch(() => undefined)
            .then(() => {
                // the page the other items go below: they need its URL for theirs
                const extra = item.kind === 'page' ? {url: pageUrl(data, context)} : {};

                return finish(reply.type === 'content'
                    ? {state: 'done', id: resource.id, ...extra}
                    : {state: 'needs_input', id: resource.id, message: reply.message || '', ...extra});
            }))
        .catch((error) => finish({state: 'failed', error: error instanceof Error ? error.message : 'The page could not be created.'}));
}

/**
 * The data of the new page: the content of the reply (new snippets created and selected), the
 * planned title, the template and the URL.
 */
function build(reply: Object, item: PlanItem, context: PlanContext, api: PlanApi): Promise<Object> {
    const content = reply && reply.type === 'content' ? reply : {properties: {}};

    return createSnippets(content.snippets || [], [], {}, api.createSnippet)
        .then(({created}) => {
            const data = resolveSnippetRefs(buildChanges(content, {}, {}), created);
            const templateKey = content.template || item.template;
            const template = context.templates.find((candidate) => candidate.key === templateKey);

            data.template = templateKey;
            data.title = typeof data.title === 'string' && data.title.trim() !== '' ? data.title : item.title;

            return url(data, item, template, context, api).then(() => data);
        });
}

function pageUrl(data: Object, context: PlanContext): ?string {
    const template = context.templates.find((candidate) => candidate.key === data.template);
    const route = template ? template.route : undefined;

    return route && typeof data[route.name] === 'string' ? data[route.name] : undefined;
}

function url(data: Object, item: PlanItem, template: ?TemplateOption, context: PlanContext, api: PlanApi): Promise<void> {
    const route = template ? template.route : undefined;

    if (!route || !item.parent) {
        return Promise.resolve();
    }

    const parts = urlParts(route.parts, data);
    const request: Object = {parts, resourceKey: context.resourceKey, locale: context.locale, parentId: item.parent};

    if (context.webspace) {
        request.webspace = context.webspace;
    }

    if (route.type === 'page_tree_route') {
        const parent = context.pages.find((page) => page.uuid === item.parent);

        return api.generateUrl({...request, parentKey: 'pages', relative: true}).then((suffix) => {
            data[route.name] = {page: {uuid: item.parent, path: parent ? parent.url : undefined}, suffix};
        });
    }

    return api.generateUrl(request).then((resourceLocator) => {
        data[route.name] = resourceLocator;
    });
}

/**
 * The progress of a plan as stored with its conversation, shown again after a reload. An item
 * that was waiting or being written when the planner was left was never created: it becomes a
 * failed item that can be tried again.
 */
export function restoreProgress(
    items: Array<{item: PlanItem, state: Object}>,
    reasons: {interrupted: string, notStarted: string}
): Array<{item: PlanItem, state: ItemState}> {
    const {interrupted, notStarted} = reasons;

    return items.map(({item, state}) => {
        if ((state.state === 'done' || state.state === 'needs_input') && typeof state.id === 'string') {
            const extra = typeof state.url === 'string' ? {url: state.url} : {};

            return {
                item,
                state: state.state === 'done'
                    ? {state: 'done', id: state.id, ...extra}
                    : {state: 'needs_input', id: state.id, message: state.message || '', ...extra},
            };
        }

        if (state.state === 'failed') {
            return {item, state: {state: 'failed', error: state.error || interrupted}};
        }

        // an item still waiting for its turn was never written; one being written or created was
        return {item, state: {state: 'failed', error: state.state === 'waiting' ? notStarted : interrupted}};
    });
}

/**
 * What is stored of the progress: the item and the state, without the passing progress text.
 */
export function storableProgress(items: Array<{item: PlanItem, state: ItemState}>): Array<Object> {
    return items.map(({item, state}) => {
        const {progress, ...stored} = (state: Object);

        return {item, state: stored};
    });
}
