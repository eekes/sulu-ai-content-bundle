// @flow
import {translate} from 'sulu-admin-bundle/utils';
import assistantConfig from '../stores/assistantConfig';
import type {HeaderMenuSection} from '../components/HeaderMenu';

/**
 * The key of the model a run would use: the editor's choice when it is still configured, the
 * default model otherwise.
 */
export function activeModelKey(key: ?string): ?string {
    const {defaultModel, models} = assistantConfig;

    if (key && models.some((model) => model.key === key)) {
        return key;
    }

    return defaultModel || (models[0] ? models[0].key : undefined);
}

/**
 * The label on the menu button: the active model when there is a choice, nothing otherwise.
 */
export function activeModelLabel(key: ?string): ?string {
    if (assistantConfig.models.length < 2) {
        return undefined;
    }

    const active = activeModelKey(key);
    const model = assistantConfig.models.find((candidate) => candidate.key === active);

    return model ? model.label : undefined;
}

/**
 * The "Choose model" part of the header menu, with a check mark on the active model. Empty with
 * a single model, so the menu leaves it out.
 */
export function modelSection(key: ?string, onChange: (key: string) => void, disabled: boolean): HeaderMenuSection {
    const {models} = assistantConfig;

    if (models.length < 2) {
        return {items: []};
    }

    const active = activeModelKey(key);

    return {
        title: translate('eekes_ai_content.choose_model'),
        items: models.map((model) => ({
            checked: model.key === active,
            disabled,
            label: model.label,
            onClick: () => onChange(model.key),
        })),
    };
}
