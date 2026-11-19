// @flow
import {fieldRegistry} from 'sulu-admin-bundle/containers';
import {Requester, ResourceRequester, resourceRouteRegistry} from 'sulu-admin-bundle/services';
import assistantConfig from '../stores/assistantConfig';
import type {PlanApi} from '../utils/plan';

const CONVERSATIONS = 'eekes_ai_content_conversations';
const TURNS = 'eekes_ai_content_turns';

/**
 * The requests utils/plan.js makes, through Sulu's own APIs: the assistant's conversations and
 * turns, the URL generation of Sulu's URL field, and the snippet, page and article resources.
 * Nothing is published: pages and articles are created as drafts.
 */
export function createPlanApi(resourceKey: string, locale: string, webspace: ?string): PlanApi {
    return {
        startConversation: (scope) => Requester.post(resourceRouteRegistry.getUrl('list', CONVERSATIONS), scope)
            .then((conversation) => conversation.id),
        sendTurn: (conversationId, request) => Requester.post(resourceRouteRegistry.getUrl('list', TURNS, {conversationId}), request),
        getTurn: (runId) => Requester.get(resourceRouteRegistry.getUrl('detail', TURNS, {id: runId})),
        wait: (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds)),
        generateUrl: (request) => {
            const options = fieldRegistry.has('route') ? fieldRegistry.getOptions('route') : {};
            const generationUrl = typeof options.generationUrl === 'string' ? options.generationUrl : '/admin/api/resource-locators';

            return Requester.post(generationUrl, request).then((response) => response.resourceLocator);
        },
        createSnippet: (data) => ResourceRequester.post(
            'snippets',
            data,
            {locale, action: assistantConfig.publishNewSnippets ? 'publish' : undefined}
        ),
        createResource: (data, parentId) => ResourceRequester.post(
            resourceKey,
            data,
            resourceKey === 'pages' ? {locale, webspace, parentId} : {locale}
        ),
        attach: (conversationId, resourceId) => Requester.patch(resourceRouteRegistry.getUrl('detail', CONVERSATIONS, {id: conversationId}), {resourceId}),
    };
}
