// @flow
/*
 * Sulu's smart content field reads its value once, when it is mounted, and fields are keyed by
 * name and blocks by position. A list the assistant changes in a field that is already on screen
 * is stored correctly but keeps showing its old filters until the form is loaded again. These are
 * found here, so the panel can say so. Tested in tests/smartContent.test.js.
 */

const FILTER_KEYS = [
    'audienceTargeting',
    'categories',
    'categoryOperator',
    'dataSource',
    'includeSubFolders',
    'limitResult',
    'presentAs',
    'sortBy',
    'sortMethod',
    'tagOperator',
    'tags',
    'types',
];

function isPlainObject(value: mixed): boolean %checks {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function filtersOf(value: mixed): ?{[key: string]: mixed} {
    if (!isPlainObject(value)) {
        return undefined;
    }

    // $FlowFixMe - checked above
    const keys = Object.keys(value);

    if (keys.length === 0 || !keys.every((key) => FILTER_KEYS.includes(key))) {
        return undefined;
    }

    const filters = {};

    keys.sort().forEach((key) => {
        // $FlowFixMe - checked above
        const item = value[key];

        if (item !== undefined && item !== null && !(Array.isArray(item) && item.length === 0)) {
            filters[key] = item;
        }
    });

    return filters;
}

/**
 * The paths of smart content lists that are on screen with other filters than the change sets.
 */
export function staleSmartContent(changes: {[path: string]: mixed}, current: Object): Array<string> {
    const stale = [];

    const walk = (next: mixed, previous: mixed, path: string) => {
        const nextFilters = filtersOf(next);

        if (nextFilters) {
            // a mounted field without filters holds an empty object
            const previousFilters = isPlainObject(previous) && Object.keys(previous).length === 0 ? {} : filtersOf(previous);

            if (previousFilters && JSON.stringify(previousFilters) !== JSON.stringify(nextFilters)) {
                stale.push(path);
            }

            return;
        }

        if (Array.isArray(next) && Array.isArray(previous)) {
            next.forEach((item, index) => walk(item, previous[index], `${path}[${index}]`));

            return;
        }

        if (isPlainObject(next) && isPlainObject(previous)) {
            // $FlowFixMe - checked above
            Object.keys(next).forEach((key) => walk(next[key], previous[key], `${path}.${key}`));
        }
    };

    Object.keys(changes).forEach((path) => walk(changes[path], (current || {})[path], path));

    return stale;
}
