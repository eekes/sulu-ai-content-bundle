import {highlightJson, splitJson} from '../utils/transcript';

describe('splitJson', () => {
    test('a reply that is only JSON is one JSON segment', () => {
        expect(splitJson('{"type": "message", "message": "Hi"}')).toEqual([
            {type: 'json', value: {type: 'message', message: 'Hi'}},
        ]);
    });

    test('a code fence around the JSON is dropped', () => {
        expect(splitJson('Here you go:\n```json\n{"a": 1}\n```')).toEqual([
            {type: 'text', text: 'Here you go:'},
            {type: 'json', value: {a: 1}},
        ]);
    });

    test('text and JSON blocks keep their order', () => {
        const prompt = 'Action: rework this page.\n\nCurrent content of the form:\n{\n  "title": "Bread {fresh}",\n  "blocks": [{"type": "text", "_ref": 0}]\n}\n\nRequest of the editor: Shorter';

        expect(splitJson(prompt)).toEqual([
            {type: 'text', text: 'Action: rework this page.\n\nCurrent content of the form:'},
            {type: 'json', value: {title: 'Bread {fresh}', blocks: [{type: 'text', _ref: 0}]}},
            {type: 'text', text: 'Request of the editor: Shorter'},
        ]);
    });

    test('brackets in a sentence are not JSON', () => {
        expect(splitJson('Since [year] we bake. Arrays look like [] and {}.')).toEqual([
            {type: 'text', text: 'Since [year] we bake. Arrays look like [] and {}.'},
        ]);
    });

    test('paths and short lists in a sentence stay text', () => {
        const repair = 'Fix these problems:\n- blocks[5].button: "#contact" is not an absolute URL.\n- Allowed: ["light", "dark"] or [1, 2].';

        expect(splitJson(repair)).toEqual([{type: 'text', text: repair}]);
    });

    test('a list of objects is JSON', () => {
        expect(splitJson('Blocks:\n[{"type": "hero"}]')).toEqual([
            {type: 'text', text: 'Blocks:'},
            {type: 'json', value: [{type: 'hero'}]},
        ]);
    });

    test('JSON that does not close stays text', () => {
        expect(splitJson('{"type": "content", "message": ')).toEqual([
            {type: 'text', text: '{"type": "content", "message":'},
        ]);
    });
});

describe('highlightJson', () => {
    test('keys, strings, numbers and literals are told apart', () => {
        const tokens = highlightJson({title: 'Hi', count: 2, ok: true, none: null});
        const typed = tokens.filter((token) => token.type !== 'punctuation');

        expect(typed).toEqual([
            {type: 'key', text: '"title"'},
            {type: 'string', text: '"Hi"'},
            {type: 'key', text: '"count"'},
            {type: 'number', text: '2'},
            {type: 'key', text: '"ok"'},
            {type: 'literal', text: 'true'},
            {type: 'key', text: '"none"'},
            {type: 'literal', text: 'null'},
        ]);
        expect(tokens.map((token) => token.text).join('')).toBe(JSON.stringify({title: 'Hi', count: 2, ok: true, none: null}, null, 2));
    });

    test('quotes inside strings survive', () => {
        const tokens = highlightJson({text: 'He said "hi"'});

        expect(tokens.find((token) => token.type === 'string').text).toBe('"He said \\"hi\\""');
    });
});
