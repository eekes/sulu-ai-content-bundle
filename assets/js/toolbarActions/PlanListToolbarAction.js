// @flow
import React from 'react';
import ReactDOM from 'react-dom';
import {action, observable} from 'mobx';
import {AbstractListToolbarAction} from 'sulu-admin-bundle/views';
import {translate} from 'sulu-admin-bundle/utils';
import {webspaceStore} from 'sulu-page-bundle/stores';
import PlannerPanel from '../components/PlannerPanel';
import PlannerStore from '../stores/PlannerStore';
import assistantConfig from '../stores/assistantConfig';
import plannerStyles from '../components/planner.scss';

const PAGE_EDIT_VIEW = 'sulu_page.page_edit_form';

/**
 * The "AI" button of an article list: plans several new articles of the list's templates and
 * creates them as drafts, with their URL below the page the plan puts them.
 */
export default class PlanListToolbarAction extends AbstractListToolbarAction {
    @observable open: boolean = false;
    @observable webspace: ?string = undefined;
    @observable store: ?PlannerStore = undefined;

    get locale(): string {
        const {locale} = this.listStore.observableOptions;

        return locale ? String(locale.get()) : '';
    }

    get templateKeys(): Array<string> {
        const {templateKeys} = this.options;

        return typeof templateKeys === 'string' && templateKeys !== '' ? templateKeys.split(',') : [];
    }

    @action createStore() {
        if (this.store) {
            this.store.destroy();
        }

        const webspaces = webspaceStore.grantedWebspaces;
        const webspace = this.webspace || (webspaces.length > 0 ? webspaces[0].key : undefined);

        this.webspace = webspace;
        this.store = new PlannerStore({
            formKey: typeof this.options.formKey === 'string' ? this.options.formKey : 'article',
            resourceKey: this.listStore.resourceKey,
            webspace,
            locale: this.locale,
            templateKeys: this.templateKeys,
        });
        // every new draft shows up in the list right away
        this.store.onCreated = () => this.listStore.reload();
    }

    getToolbarItemConfig() {
        if (!assistantConfig.enabled) {
            return undefined;
        }

        return {
            type: 'button',
            label: translate('eekes_ai_content.ai'),
            icon: 'su-magic',
            active: this.open,
            onClick: action(() => {
                this.open = !this.open;

                if (this.open && (!this.store || this.store.scope.locale !== this.locale)) {
                    this.createStore();
                }
            }),
        };
    }

    @action handleClose = () => {
        this.open = false;
    };

    @action handleWebspaceChange = (event: SyntheticInputEvent<HTMLSelectElement>) => {
        this.webspace = event.currentTarget.value;
        this.createStore();
    };

    handleOpen = (id: string, kind: ?string) => {
        const {editView} = this.options;

        // the page the plan created for its articles to go below
        if (kind === 'page') {
            this.router.navigate(PAGE_EDIT_VIEW, {id, webspace: this.webspace, locale: this.locale});

            return;
        }

        if (typeof editView === 'string') {
            this.router.navigate(editView, {id, locale: this.locale});
        }
    };

    getNode() {
        const {store} = this;

        if (!this.open || !store || !document.body) {
            return null;
        }

        const webspaces = webspaceStore.grantedWebspaces;

        return ReactDOM.createPortal(
            <PlannerPanel
                headerExtra={webspaces.length > 1 &&
                    <select className={plannerStyles.webspace} disabled={store.executing} onChange={this.handleWebspaceChange} value={this.webspace || ''}>
                        {webspaces.map((webspace) => <option key={webspace.key} value={webspace.key}>{webspace.name}</option>)}
                    </select>
                }
                key={(this.webspace || '') + '/' + this.locale}
                noun="articles"
                onClose={this.handleClose}
                onOpen={this.handleOpen}
                store={store}
            />,
            document.body
        );
    }

    destroy() {
        if (this.store) {
            this.store.destroy();
        }
    }
}
