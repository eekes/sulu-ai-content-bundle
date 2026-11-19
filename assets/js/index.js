// @flow
import {viewRegistry} from 'sulu-admin-bundle/containers';
import {initializer} from 'sulu-admin-bundle/services';
import {formToolbarActionRegistry, listToolbarActionRegistry} from 'sulu-admin-bundle/views';
import AssistantToolbarAction from './toolbarActions/AssistantToolbarAction';
import PlanListToolbarAction from './toolbarActions/PlanListToolbarAction';
import pageTreePlanner from './toolbarActions/pageTreePlanner';
import WebspaceContextForm from './views/WebspaceContextForm';
import RunDetail from './views/RunDetail';
import AssistantPanel from './components/AssistantPanel';
import ConversationStore from './stores/ConversationStore';
import assistantConfig from './stores/assistantConfig';
import * as applyContent from './utils/applyContent';

initializer.addUpdateConfigHook('eekes_ai_content', (config: Object, initialized: boolean) => {
    assistantConfig.set(config || {});

    if (initialized) {
        return;
    }

    formToolbarActionRegistry.add('eekes_ai_content.assistant', AssistantToolbarAction);
    viewRegistry.add('eekes_ai_content.webspace_context', WebspaceContextForm);
    viewRegistry.add('eekes_ai_content.run_detail', RunDetail);
    listToolbarActionRegistry.add('eekes_ai_content.plan', PlanListToolbarAction);
});

// after the page bundle's own hook, which registers the page tree view
initializer.addUpdateConfigHook('sulu_page', (config: Object, initialized: boolean) => {
    if (!initialized) {
        pageTreePlanner.install();
    }
});

export {
    AssistantToolbarAction,
    AssistantPanel,
    PlanListToolbarAction,
    ConversationStore,
    WebspaceContextForm,
    RunDetail,
    applyContent,
    assistantConfig,
};
