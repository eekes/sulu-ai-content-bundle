// @flow
/*
 * Pure functions that turn a reply of the assistant into changes of the form. No Sulu imports,
 * so they are tested on their own (tests/applyContent.test.js).
 */

export const BLOCK_REF = '_ref';

const isPlainObject = (value: mixed): boolean %checks => (
    typeof value === 'object' && value !== null && !Array.isArray(value)
);

const isBlockList = (value: mixed): boolean => (
    Array.isArray(value)
    && value.length > 0
    && value.every((item) => isPlainObject(item) && typeof item.type === 'string')
);

const clone = (value: mixed): any => JSON.parse(JSON.stringify(value === undefined ? null : value));

/**
 * The new version of a block list. A block that refers to an existing one ("_ref") starts from that
 * block - so its settings and the properties the assistant never sees are kept - and gets the new
 * values on top. Nested block lists are merged the same way, against the nested list of that block.
 */
export function mergeBlocks(proposed: Array<Object>, base: mixed): Array<Object> {
    const baseList = Array.isArray(base) ? base : [];

    return proposed.map((block) => {
        const {[BLOCK_REF]: ref, ...values} = block;
        const original = typeof ref === 'number' ? baseList[ref] : undefined;
        const start = isPlainObject(original) && original.type === values.type ? clone(original) : {};

        return mergeObject(values, start);
    });
}

function mergeObject(values: Object, start: Object): Object {
    const result = {...start};

    Object.keys(values).forEach((key) => {
        const value = values[key];

        result[key] = isBlockList(value) ? mergeBlocks(value, start[key]) : value;
    });

    return result;
}

/**
 * The values to put in the form for a content reply, by data path: the changed properties (block
 * lists merged against what the form holds now), and the SEO and excerpt fields merged into what
 * is there.
 *
 * "base" is the data the "_ref"s of the reply refer to: the current form, or the source locale
 * when translating.
 *
 * All three are copied to plain data first: a reply kept in the panel's store, or a form read
 * without toJS(), is MobX-observable, and in MobX 4 an observable array is no array - its block
 * lists would not be recognised (and a list would end up in the form as an object).
 */
export function buildChanges(observableReply: Object, observableCurrent: Object, observableBase: Object = observableCurrent): {[path: string]: mixed} {
    const reply = clone(observableReply) || {};
    const current = clone(observableCurrent) || {};
    const base = clone(observableBase) || {};
    const changes = {};
    const properties = isPlainObject(reply.properties) ? reply.properties : {};

    Object.keys(properties).forEach((name) => {
        const value = properties[name];

        changes[name] = isBlockList(value) ? mergeBlocks(value, base[name]) : value;
    });

    ['seo', 'excerpt'].forEach((group) => {
        const values = reply[group];

        if (isPlainObject(values) && Object.keys(values).length > 0) {
            changes[group] = {...(isPlainObject(current[group]) ? current[group] : {}), ...values};
        }
    });

    return changes;
}

/**
 * The values to restore for an undo: what the changed paths held before.
 */
export function snapshotOf(changes: {[path: string]: mixed}, current: Object): {[path: string]: mixed} {
    const snapshot = {};

    Object.keys(changes).forEach((path) => {
        snapshot[path] = current[path] === undefined ? null : clone(current[path]);
    });

    return snapshot;
}

/**
 * Every block of every block list in the form, for the "rework one block" picker.
 */
export function listBlocks(data: Object): Array<{property: string, index: number, type: string, preview: string}> {
    const blocks = [];

    Object.keys(data || {}).forEach((property) => {
        const value = data[property];

        if (!isBlockList(value)) {
            return;
        }

        value.forEach((block, index) => {
            blocks.push({property, index, type: block.type, preview: previewOf(block)});
        });
    });

    return blocks;
}

function previewOf(block: Object): string {
    for (const key of Object.keys(block)) {
        const value = block[key];

        if (key !== 'type' && typeof value === 'string' && value.trim() !== '') {
            const text = value.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();

            return text.length > 60 ? text.substring(0, 57) + '...' : text;
        }
    }

    return '';
}

/**
 * Whether the form holds no content yet, which turns the first action into "write this page".
 *
 * The form data also holds what Sulu keeps next to the content (author, dates, template, SEO), so
 * "empty" means: no blocks and no longer text in any property.
 */
export function isEmptyContent(data: Object): boolean {
    if (listBlocks(data).length > 0) {
        return false;
    }

    return Object.keys(data || {}).every((key) => {
        const value = data[key];

        return ['title', 'url'].includes(key) || typeof value !== 'string' || value.replace(/<[^>]*>/g, '').trim().length < 80;
    });
}

/**
 * An approved outline, as the message that asks the assistant to write it.
 */
export function outlineToMessage(outline: {template: ?string, blocks: Array<{type: string, title?: string, purpose: string, snippets?: Array<{title: string, uuid: ?string}>}>}): string {
    const lines = outline.blocks.map((block, index) => {
        const snippets = (block.snippets || []).map((snippet) => (
            snippet.uuid ? `\n   - reuse "${snippet.title}" (${snippet.uuid})` : `\n   - new: "${snippet.title}"`
        ));

        // the name reads well in the conversation, the key is what the assistant writes in "type"
        const name = block.title && block.title !== block.type ? `${block.title} (${block.type})` : block.type;

        return `${index + 1}. ${name}: ${block.purpose}${snippets.join('')}`;
    });
    const template = outline.template ? ` (template "${outline.template}")` : '';

    return `Write the page with this outline${template}:\n${lines.join('\n')}`;
}

export const NEW_SNIPPET = 'new:';

// "new:faq-1" - a key without spaces, so a text that happens to start with "new:" is left alone
const SNIPPET_REF = /^new:[\w.-]+$/;
const isSnippetRef = (value: mixed): boolean %checks => typeof value === 'string' && SNIPPET_REF.test(value);

/**
 * Replaces the "new:<key>" references of the reply with the uuids of the snippets that were
 * created. A reference to a snippet the editor left out (or that failed) is removed from a list
 * and emptied in a single selection.
 */
export function resolveSnippetRefs(value: mixed, created: {[key: string]: string}): any {
    if (Array.isArray(value)) {
        return value
            .filter((item) => !isSnippetRef(item) || created[String(item).substring(NEW_SNIPPET.length)])
            .map((item) => resolveSnippetRefs(item, created));
    }

    if (typeof value === 'string' && isSnippetRef(value)) {
        return created[value.substring(NEW_SNIPPET.length)] || null;
    }

    if (value !== null && typeof value === 'object') {
        const result = {};

        Object.keys(value).forEach((key) => {
            result[key] = resolveSnippetRefs((value: Object)[key], created);
        });

        return result;
    }

    return value;
}
