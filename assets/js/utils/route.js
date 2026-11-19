// @flow
/*
 * Which URL Sulu would generate for the form after the assistant changed it. No Sulu imports, so
 * it is tested on its own (tests/route.test.js).
 */

/**
 * The parts of the URL - the values of the fields tagged "sulu.rlp.part", usually the title - by
 * property name, as the generation endpoint expects them.
 */
export function routeParts(partPaths: Array<string>, data: Object): {[name: string]: string} {
    const parts = {};

    partPaths.forEach((path) => {
        const name = path.startsWith('/') ? path.substring(1) : path;
        const value = name.split('/').reduce((current, key) => (current && typeof current === 'object' ? current[key] : undefined), data);

        if (typeof value === 'string' && value.trim() !== '') {
            parts[name] = value;
        }
    });

    return parts;
}

/**
 * Whether the URL should follow the changes, the way Sulu's own URL field decides it: only for a
 * page that has not been saved yet or has no URL yet, and only when a part of it changed.
 */
export function shouldGenerateRoute(
    partPaths: Array<string>,
    changedPaths: Array<string>,
    resourceId: mixed,
    currentRoute: mixed
): boolean {
    if (partPaths.length === 0) {
        return false;
    }

    const changed = partPaths.some((path) => changedPaths.includes(path.startsWith('/') ? path.substring(1) : path));
    const routeIsEmpty = typeof currentRoute !== 'string' || currentRoute.trim() === '';

    return changed && (!resourceId || routeIsEmpty);
}

/**
 * The top-level properties of a form schema of the given type, also inside sections - for the
 * URL field of an article (page_tree_route), which carries no tag.
 */
export function fieldNamesOfType(schema: ?Object, type: string): Array<string> {
    const names = [];

    const walk = (entries: ?Object) => {
        Object.keys(entries || {}).forEach((name) => {
            const entry = (entries || {})[name];

            if (!entry || typeof entry !== 'object') {
                return;
            }

            if (entry.type === 'section') {
                walk(entry.items);
            } else if (entry.type === type) {
                names.push(name);
            }
        });
    };

    walk(schema);

    return names;
}

/**
 * The assistant only picks the page an article's URL sits below; the rest of the URL (the
 * suffix) stays what it was.
 */
export function mergePageTreeRoute(next: mixed, current: mixed): mixed {
    if (!next || typeof next !== 'object' || Array.isArray(next)) {
        return next;
    }

    const kept = current && typeof current === 'object' && !Array.isArray(current) ? current : {};

    return {...kept, page: next.page};
}

function pageUuid(value: mixed): ?string {
    // $FlowFixMe - a page tree route value or anything else
    const page = value && typeof value === 'object' ? value.page : undefined;

    return page && typeof page.uuid === 'string' && page.uuid !== '' ? page.uuid : undefined;
}

/**
 * Whether the rest of an article's URL should be made, the way Sulu's own field decides it: a
 * page to sit below, an article that is new or has no suffix yet, and a title or page that changed.
 */
export function shouldGeneratePageTreeSuffix(
    partPaths: Array<string>,
    changedPaths: Array<string>,
    resourceId: mixed,
    previous: mixed,
    next: mixed
): boolean {
    const uuid = pageUuid(next);

    if (!uuid || partPaths.length === 0) {
        return false;
    }

    // $FlowFixMe - checked by pageUuid
    const suffix = next.suffix;
    const suffixIsEmpty = typeof suffix !== 'string' || suffix.trim() === '';
    const partChanged = partPaths.some((path) => changedPaths.includes(path.startsWith('/') ? path.substring(1) : path));

    return (!resourceId || suffixIsEmpty) && (partChanged || pageUuid(previous) !== uuid);
}
