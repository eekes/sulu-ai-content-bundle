/**
 * @jest-environment jsdom
 */
import React from 'react';
import ReactDOM from 'react-dom';
import {act} from 'react-dom/test-utils';
import HeaderMenu from '../components/HeaderMenu';
import assistantConfig from '../stores/assistantConfig';
import {activeModelKey, activeModelLabel, modelSection} from '../utils/headerMenu';

let container;
let clicked;

const buttons = () => Array.from(container.querySelectorAll('button'));
const button = (text) => buttons().find((element) => element.textContent.trim() === text);
const trigger = () => container.querySelector('button[aria-haspopup]');
const click = (element) => act(() => {
    element.dispatchEvent(new MouseEvent('click', {bubbles: true}));
});
const menuOpen = () => container.querySelector('[role="menu"]') !== null;

const render = (sections, triggerText) => act(() => {
    ReactDOM.render(<HeaderMenu label="Menu" sections={sections} trigger={triggerText} />, container);
});

const sections = () => [
    {
        items: [
            {icon: 'su-plus', label: 'New conversation', onClick: () => clicked.push('new')},
            {disabled: true, icon: 'su-clock', label: 'Earlier conversations', onClick: () => clicked.push('history')},
        ],
    },
    {
        title: 'Choose model',
        items: [
            {checked: true, label: 'Claude', onClick: () => clicked.push('claude')},
            {checked: false, label: 'GPT', onClick: () => clicked.push('gpt')},
        ],
    },
    {items: []},
];

beforeEach(() => {
    clicked = [];
    container = document.createElement('div');
    document.body.appendChild(container);
    assistantConfig.set({models: [{key: 'claude', label: 'Claude'}, {key: 'gpt', label: 'GPT'}], defaultModel: 'claude'});
});

afterEach(() => {
    ReactDOM.unmountComponentAtNode(container);
    container.remove();
});

describe('HeaderMenu', () => {
    it('opens on the trigger and lists every choice in words', () => {
        render(sections());
        expect(menuOpen()).toBe(false);

        click(trigger());

        expect(menuOpen()).toBe(true);
        expect(container.textContent).toContain('New conversation');
        expect(container.textContent).toContain('Choose model');
        expect(container.querySelectorAll('[role="menu"] > div')).toHaveLength(2);
    });

    it('puts a check mark on the checked item only', () => {
        render(sections());
        click(trigger());

        const checked = container.querySelectorAll('[aria-checked="true"]');
        expect(checked).toHaveLength(1);
        expect(checked[0].textContent).toBe('Claude');
        expect(checked[0].querySelector('[data-icon="su-check"]')).not.toBeNull();
        expect(button('GPT').querySelector('[data-icon]')).toBeNull();
    });

    it('runs the item and closes', () => {
        render(sections());
        click(trigger());
        click(button('GPT'));

        expect(clicked).toEqual(['gpt']);
        expect(menuOpen()).toBe(false);
    });

    it('ignores a disabled item', () => {
        render(sections());
        click(trigger());

        expect(button('Earlier conversations').disabled).toBe(true);
    });

    it('closes on a click outside and on Escape', () => {
        render(sections());
        click(trigger());
        act(() => {
            document.body.dispatchEvent(new MouseEvent('mousedown', {bubbles: true}));
        });
        expect(menuOpen()).toBe(false);

        click(trigger());
        act(() => {
            document.dispatchEvent(new KeyboardEvent('keydown', {key: 'Escape'}));
        });
        expect(menuOpen()).toBe(false);
    });

    it('shows the trigger text with an arrow, or a more icon without it', () => {
        render(sections(), 'Claude');
        expect(trigger().textContent).toBe('Claude');
        expect(trigger().querySelector('[data-icon="su-angle-down"]')).not.toBeNull();

        render(sections(), undefined);
        expect(trigger().querySelector('[data-icon="su-more-horizontal"]')).not.toBeNull();
    });
});

describe('model section', () => {
    it('checks the chosen model and changes it', () => {
        const changed = [];
        const section = modelSection('gpt', (key) => changed.push(key), false);

        expect(section.title).toBe('eekes_ai_content.choose_model');
        expect(section.items.map((item) => [item.label, item.checked])).toEqual([['Claude', false], ['GPT', true]]);

        section.items[0].onClick();
        expect(changed).toEqual(['claude']);
    });

    it('falls back to the default model for an unknown or missing choice', () => {
        expect(activeModelKey('removed')).toBe('claude');
        expect(activeModelKey(undefined)).toBe('claude');
        expect(activeModelLabel('gpt')).toBe('GPT');
    });

    it('disables the models while the assistant works', () => {
        expect(modelSection('claude', () => {}, true).items.every((item) => item.disabled)).toBe(true);
    });

    it('leaves the models out with a single model', () => {
        assistantConfig.set({models: [{key: 'claude', label: 'Claude'}], defaultModel: 'claude'});

        expect(modelSection('claude', () => {}, false).items).toEqual([]);
        expect(activeModelLabel('claude')).toBeUndefined();
    });
});
