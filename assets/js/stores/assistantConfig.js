// @flow
import {action, observable} from 'mobx';

type Model = {key: string, label: string};

/**
 * What the admin config of the bundle says: whether the assistant is configured, the models an
 * editor can pick and the budget of this month.
 */
class AssistantConfig {
    @observable enabled: boolean = false;
    @observable models: Array<Model> = [];
    @observable defaultModel: ?string = undefined;
    @observable catalog: boolean = false;
    @observable budget: ?{spent: number, limit: number} = undefined;
    @observable canViewUsage: boolean = false;
    @observable publishNewSnippets: boolean = true;

    @action set(config: Object) {
        this.enabled = !!config.enabled;
        this.models = config.models || [];
        this.defaultModel = config.defaultModel;
        this.catalog = !!config.catalog;
        this.budget = config.budget || undefined;
        this.canViewUsage = !!config.canViewUsage;
        this.publishNewSnippets = config.publishNewSnippets !== false;
    }
}

export default new AssistantConfig();
