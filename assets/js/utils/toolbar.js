// @flow
/*
 * Adds a button to the toolbar config of a Sulu view that has no place for toolbar actions of
 * other bundles, such as the page tree. Tested in tests/toolbar.test.js.
 */

/**
 * The config with the button added after the view's own items. A config without items - the
 * empty one a view sets when it goes away - is left as it is, and so is one that has the button.
 */
export function withButton(config: Object, button: Object): Object {
    if (!config || !Array.isArray(config.items)) {
        return config;
    }

    const items = config.items.filter((item) => !item || item.key !== button.key);

    return {...config, items: [...items, button]};
}
