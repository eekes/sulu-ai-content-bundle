// @flow
/*
 * The "AI" button in the toolbar of Sulu's page tree.
 *
 * Unlike lists and forms, the page tree (the "sulu_page.page_list" view) has no place for toolbar
 * actions of other bundles: its toolbar is fixed in its own code. So this hooks in from outside,
 * using two internals of sulu-admin-bundle:
 *
 * - the view is wrapped, so it is known when the page tree is on screen, and with which webspace,
 *   locale and selected page;
 * - the toolbar store pool every view hands its toolbar to is wrapped, so the button is added to
 *   the page tree's own items.
 *
 * Should either internal change in a Sulu release, nothing is added (and a warning is logged);
 * the article lists keep their button. Replace this with a toolbar action registry once Sulu has
 * one for the page tree.
 */
import React from 'react';
import ReactDOM from 'react-dom';
import log from 'loglevel';
import {action, observable} from 'mobx';
import {observer} from 'mobx-react';
import {viewRegistry} from 'sulu-admin-bundle/containers';
import {translate} from 'sulu-admin-bundle/utils';
import toolbarStorePool, {DEFAULT_STORE_KEY} from 'sulu-admin-bundle/containers/Toolbar/stores/toolbarStorePool';
import PlannerPanel from '../components/PlannerPanel';
import PlannerStore from '../stores/PlannerStore';
import assistantConfig from '../stores/assistantConfig';
import {withButton} from '../utils/toolbar';

const PAGE_TREE_VIEW = 'sulu_page.page_list';
const PAGE_EDIT_VIEW = 'sulu_page.page_edit_form';
const BUTTON_KEY = 'eekes_ai_content.plan';

class PageTreePlanner {
    @observable open: boolean = false;
    // the page tree on screen, if any
    @observable view: ?Object = undefined;
    @observable store: ?PlannerStore = undefined;
    lastConfig: ?Object = undefined;
    setConfig: ?(key: string, config: Object) => void = undefined;

    get attributes(): Object {
        return this.view ? this.view.props.router.attributes : {};
    }

    button(): Object {
        return {
            key: BUTTON_KEY,
            type: 'button',
            label: translate('eekes_ai_content.ai'),
            icon: 'su-magic',
            active: this.open,
            onClick: this.handleToggle,
        };
    }

    decorate(key: string, config: Object): Object {
        if (key !== DEFAULT_STORE_KEY || !this.view || !assistantConfig.enabled) {
            return config;
        }

        this.lastConfig = config;

        return withButton(config, this.button());
    }

    /**
     * The page tree's toolbar again, with the button in its new state.
     */
    refresh() {
        if (this.setConfig && this.lastConfig && this.view) {
            this.setConfig(DEFAULT_STORE_KEY, withButton(this.lastConfig, this.button()));
        }
    }

    @action handleToggle = () => {
        this.open = !this.open;

        if (this.open) {
            const {webspace, locale, active} = this.attributes;
            const scope = this.store ? this.store.scope : undefined;

            if (!scope || scope.webspace !== webspace || scope.locale !== locale || scope.selectedPage !== active) {
                this.createStore();
            }
        }

        this.refresh();
    };

    @action createStore() {
        const {webspace, locale, active} = this.attributes;

        if (this.store) {
            this.store.destroy();
        }

        this.store = new PlannerStore({
            formKey: 'page',
            resourceKey: 'pages',
            webspace: typeof webspace === 'string' ? webspace : undefined,
            locale: typeof locale === 'string' ? locale : '',
            templateKeys: [],
            selectedPage: typeof active === 'string' && active !== '' ? active : undefined,
        });
        this.store.onCreated = () => this.reloadTree();
    }

    @action attach(view: Object) {
        this.view = view;
    }

    @action detach(view: Object) {
        if (this.view !== view) {
            return;
        }

        this.view = undefined;
        this.open = false;
        this.lastConfig = undefined;

        if (this.store) {
            this.store.destroy();
            this.store = undefined;
        }
    }

    /**
     * Shows a new draft in the tree. The page tree keeps its list store to itself; when it is not
     * where it used to be, the drafts show after the next reload of the page.
     */
    reloadTree() {
        const tree = this.view ? this.view.tree : undefined;
        const listStore = tree ? tree.listStore : undefined;

        if (listStore && typeof listStore.reload === 'function') {
            listStore.reload();
        }
    }

    handleClose = () => {
        if (this.open) {
            this.handleToggle();
        }
    };

    handleOpen = (id: string) => {
        if (!this.view) {
            return;
        }

        const {router} = this.view.props;
        const {webspace, locale} = router.attributes;

        router.navigate(PAGE_EDIT_VIEW, {id, webspace, locale});
    };

    install() {
        if (!(PAGE_TREE_VIEW in viewRegistry.views) || typeof toolbarStorePool.setToolbarConfig !== 'function') {
            log.warn('The AI button cannot be added to the page tree of this Sulu version.');

            return;
        }

        const setConfig = toolbarStorePool.setToolbarConfig;
        this.setConfig = setConfig;
        toolbarStorePool.setToolbarConfig = (key: string, config: Object) => setConfig(key, this.decorate(key, config));

        viewRegistry.views[PAGE_TREE_VIEW] = wrap(viewRegistry.views[PAGE_TREE_VIEW], this);
    }
}

function wrap(PageTree: Object, planner: PageTreePlanner): Object {
    @observer
    class PageTreeWithPlanner extends React.Component<Object> {
        tree: ?Object;

        constructor(props: Object) {
            super(props);

            // before the tree mounts and configures its toolbar
            planner.attach(this);
        }

        componentWillUnmount() {
            planner.detach(this);
        }

        render() {
            const {store} = planner;

            return (
                <>
                    <PageTree {...this.props} ref={(ref) => { this.tree = ref; }} />
                    {planner.open && store && document.body && ReactDOM.createPortal(
                        <PlannerPanel
                                        key={(store.scope.webspace || '') + '/' + store.scope.locale}
                            noun="pages"
                            onClose={planner.handleClose}
                            onOpen={planner.handleOpen}
                            store={store}
                        />,
                        document.body
                    )}
                </>
            );
        }
    }

    return PageTreeWithPlanner;
}

const pageTreePlanner = new PageTreePlanner();

export default pageTreePlanner;
