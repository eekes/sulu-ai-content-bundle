// @flow
/*
 * Saving a new page, article or snippet replaces the add view with the edit view, and with it the
 * toolbar action and its panel. The conversation is handed over through the session storage, so
 * the edit view opens it again - and the panel too, if it was open. Tested in tests/handoff.test.js.
 */

export type Handoff = {resourceKey: string, resourceId: string, locale: string, conversationId: string, open: boolean};

const STORAGE_KEY = 'eekes_ai_content.handoff';

type Storage = {getItem: (key: string) => ?string, setItem: (key: string, value: string) => void, removeItem: (key: string) => void};

function sessionStorage(): ?Storage {
    try {
        return window.sessionStorage;
    } catch (error) {
        return undefined;
    }
}

export function handOver(handoff: Handoff, storage: ?Storage = sessionStorage()) {
    try {
        storage && storage.setItem(STORAGE_KEY, JSON.stringify(handoff));
    } catch (error) {
        // without a storage the history of the page still has the conversation
    }
}

/**
 * The conversation handed over to this resource, if any. It is taken: a second view of the same
 * resource starts fresh.
 */
export function takeOver(resourceKey: string, resourceId: ?string | ?number, locale: string, storage: ?Storage = sessionStorage()): ?Handoff {
    if (!storage || !resourceId) {
        return undefined;
    }

    try {
        const handoff = JSON.parse(storage.getItem(STORAGE_KEY) || 'null');

        if (!handoff || typeof handoff !== 'object') {
            return undefined;
        }

        storage.removeItem(STORAGE_KEY);

        if (handoff.resourceKey !== resourceKey || handoff.resourceId !== String(resourceId) || handoff.locale !== locale) {
            return undefined;
        }

        return typeof handoff.conversationId === 'string' ? {...handoff, open: !!handoff.open} : undefined;
    } catch (error) {
        return undefined;
    }
}
