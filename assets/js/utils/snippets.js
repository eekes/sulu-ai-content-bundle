// @flow
/*
 * Creating the new snippets of a reply, one after the other. The request itself is passed in, so
 * this is tested without Sulu (tests/snippets.test.js).
 */

export type NewSnippet = {key: string, template: string, properties: Object};

type Post = (data: Object) => Promise<{id: string}>;

/**
 * Creates every snippet that is not left out and not created yet, and resolves to the uuids by
 * key - also those created before - plus whether any of them failed. It never rejects: a snippet
 * that fails is simply not in the result, so its references are dropped.
 */
export function createSnippets(
    snippets: Array<NewSnippet>,
    excluded: Array<string>,
    alreadyCreated: {[key: string]: string},
    post: Post
): Promise<{created: {[key: string]: string}, failed: boolean}> {
    const created = {...alreadyCreated};
    let failed = false;
    const pending = snippets.filter((snippet) => !excluded.includes(snippet.key) && !created[snippet.key]);

    return pending.reduce(
        (chain, snippet) => chain
            .then(() => post({template: snippet.template, ...snippet.properties}))
            .then((response) => {
                created[snippet.key] = response.id;
            })
            .catch(() => {
                failed = true;
            }),
        Promise.resolve()
    ).then(() => ({created, failed}));
}
