// @flow
/*
 * Splits a message of the transcript into text and JSON, so the usage log can show the JSON
 * readable - the schema and the form in the prompt, and the model's reply. No Sulu imports, so it
 * is tested on its own (tests/transcript.test.js).
 */

export type Segment = {type: 'text', text: string} | {type: 'json', value: mixed};

/**
 * The end of the JSON value that starts at "start" (an opening brace or bracket), or -1 when it
 * does not close. Strings are skipped, so braces inside them don't count.
 */
function findEnd(text: string, start: number): number {
    const stack = [];
    let inString = false;

    for (let index = start; index < text.length; index++) {
        const char = text[index];

        if (inString) {
            if (char === '\\') {
                index++;
            } else if (char === '"') {
                inString = false;
            }

            continue;
        }

        if (char === '"') {
            inString = true;
        } else if (char === '{' || char === '[') {
            stack.push(char === '{' ? '}' : ']');
        } else if (char === '}' || char === ']') {
            if (stack.pop() !== char) {
                return -1;
            }

            if (stack.length === 0) {
                return index;
            }
        }
    }

    return -1;
}

function pushText(segments: Array<Segment>, text: string) {
    const trimmed = text.replace(/```(json)?/g, '').trim();

    if (trimmed !== '') {
        segments.push({type: 'text', text: trimmed});
    }
}

/**
 * Text and JSON in the order they appear. Only an object, or a list of objects or lists, that
 * parses as JSON counts - so "[year]", "blocks[5]" and "["theme"]" in a sentence stay text.
 */
export function splitJson(text: string): Array<Segment> {
    const segments = [];
    let cursor = 0;
    let index = 0;

    while (index < text.length) {
        const char = text[index];

        // "blocks[5]" is a path in a sentence, not JSON
        if ((char !== '{' && char !== '[') || (index > 0 && /[\w.]/.test(text[index - 1]))) {
            index++;

            continue;
        }

        const end = findEnd(text, index);

        if (end === -1) {
            index++;

            continue;
        }

        let value;

        try {
            value = JSON.parse(text.substring(index, end + 1));
        } catch (error) {
            index++;

            continue;
        }

        // a short list of values reads fine as text; only objects and lists of them are shown as JSON
        const structured = Array.isArray(value)
            ? value.some((item) => item !== null && typeof item === 'object')
            : value !== null && typeof value === 'object' && Object.keys(value).length > 0;

        if (!structured) {
            index = end + 1;

            continue;
        }

        pushText(segments, text.substring(cursor, index));
        segments.push({type: 'json', value});
        cursor = end + 1;
        index = end + 1;
    }

    pushText(segments, text.substring(cursor));

    return segments;
}

export type Token = {type: 'key' | 'string' | 'number' | 'literal' | 'punctuation', text: string};

const TOKEN = /("(?:\\.|[^"\\])*")(\s*:)?|(-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?)|(true|false|null)|([{}[\],:\s]+)/g;

/**
 * Pretty-printed JSON as tokens, for colouring keys, strings, numbers and literals.
 */
export function highlightJson(value: mixed): Array<Token> {
    const json = JSON.stringify(value, null, 2) || '';
    const tokens = [];
    let match;

    TOKEN.lastIndex = 0;

    while ((match = TOKEN.exec(json)) !== null) {
        if (match[1] !== undefined) {
            tokens.push({type: match[2] ? 'key' : 'string', text: match[1]});

            if (match[2]) {
                tokens.push({type: 'punctuation', text: match[2]});
            }
        } else if (match[3] !== undefined) {
            tokens.push({type: 'number', text: match[3]});
        } else if (match[4] !== undefined) {
            tokens.push({type: 'literal', text: match[4]});
        } else {
            tokens.push({type: 'punctuation', text: match[5]});
        }
    }

    return tokens;
}
