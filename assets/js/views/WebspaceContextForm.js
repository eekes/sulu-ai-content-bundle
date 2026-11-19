// @flow
import React from 'react';
import {computed, observable} from 'mobx';
import {observer} from 'mobx-react';
import {Form} from 'sulu-admin-bundle/views';
import {ResourceStore} from 'sulu-admin-bundle/stores';
import userStore from 'sulu-admin-bundle/stores/userStore/userStore';
import type {ViewProps} from 'sulu-admin-bundle/containers';
import type {IObservableValue} from 'mobx/lib/mobx';

/**
 * The "AI context" tab of a webspace: the standard Form view, with a resource store of its own.
 *
 * The Form view expects a parent ResourceTabs view to hand it a store, and the webspace tabs are a
 * plain Tabs view - so this view builds the store. The id of the record is the webspace key.
 */
@observer
class WebspaceContextForm extends React.Component<ViewProps> {
    resourceStore: ResourceStore;
    locale: IObservableValue<?string> = observable.box();

    @computed get webspaceKey(): string {
        const {webspace} = this.props.router.attributes;

        if (typeof webspace !== 'string') {
            throw new Error('The "webspace" router attribute must be a string!');
        }

        return webspace;
    }

    @computed.struct get locales(): Array<string> {
        const {webspace} = (this.props: Object);

        if (!webspace || !webspace.allLocalizations) {
            return [];
        }

        return webspace.allLocalizations.map((localization) => localization.localization);
    }

    @computed get defaultLocale(): ?string {
        return this.locales.includes(userStore.contentLocale) ? userStore.contentLocale : this.locales[0];
    }

    constructor(props: ViewProps) {
        super(props);

        const {resourceKey} = this.props.route.options;

        this.props.router.bind('locale', this.locale, this.defaultLocale);
        this.resourceStore = new ResourceStore(resourceKey, this.webspaceKey, {locale: this.locale});
    }

    componentWillUnmount() {
        this.resourceStore.destroy();
    }

    render() {
        return <Form {...this.props} locales={this.locales} resourceStore={this.resourceStore} />;
    }
}

export default WebspaceContextForm;
