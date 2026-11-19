/**
 * @jest-environment jsdom
 */
import React from 'react';
import ReactDOM from 'react-dom';
import {act} from 'react-dom/test-utils';
import PlanProgress from '../components/PlanProgress';

let container;
let opened;
let retried;

const click = (element) => act(() => {
    element.dispatchEvent(new MouseEvent('click', {bubbles: true}));
});

const item = (title) => ({title, template: 'default', parent: 'services', brief: ''});

function render(items) {
    act(() => {
        ReactDOM.render(<PlanProgress items={items} onOpen={(id) => opened.push(id)} onRetry={(index) => retried.push(index)} />, container);
    });

    return Array.from(container.querySelectorAll('li'));
}

beforeEach(() => {
    container = document.createElement('div');
    document.body.appendChild(container);
    opened = [];
    retried = [];
});

afterEach(() => {
    ReactDOM.unmountComponentAtNode(container);
    container.remove();
});

test('the loader sits left of the item that is being worked on, and only there', () => {
    const [writing, waiting] = render([
        {item: item('Bread'), state: {state: 'writing', progress: 'Looking for pages to link to'}},
        {item: item('Cakes'), state: {state: 'waiting'}},
    ]);

    expect(writing.firstElementChild.querySelector('[data-loader]')).not.toBeNull();
    expect(writing.children[1].textContent).toBe('Bread');
    expect(writing.textContent).toContain('Looking for pages to link to');
    expect(waiting.querySelector('[data-loader]')).toBeNull();
    expect(waiting.textContent).toBe('Cakes' + 'eekes_ai_content.plan_waiting');
});

test('a created draft says so and opens, a failed one can be tried again', () => {
    const [done, failed] = render([
        {item: item('Bread'), state: {state: 'done', id: 'page-1'}},
        {item: item('Cakes'), state: {state: 'failed', error: 'Budget reached.'}},
    ]);

    const state = done.children[2];
    expect(state.children[0].textContent).toBe('eekes_ai_content.plan_done');
    expect(state.children[1].textContent).toBe('eekes_ai_content.open');

    click(state.children[1]);
    expect(opened).toEqual(['page-1']);

    click(failed.querySelector('button'));
    expect(retried).toEqual([1]);
    expect(failed.textContent).toContain('Budget reached.');
});
