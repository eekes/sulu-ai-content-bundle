/**
 * @jest-environment jsdom
 */
import React from 'react';
import ReactDOM from 'react-dom';
import {act} from 'react-dom/test-utils';
import QuestionStepper from '../components/QuestionStepper';

const questions = [
    {question: 'Who is it for?', options: ['Customers', 'Partners'], multiple: false},
    {question: 'Which topics?', options: ['Prices', 'Hours', 'Team'], multiple: true},
    {question: 'Deadline?', options: [], multiple: false, remember: true},
];

let container;
let sent;
let sentAnswers;

const buttons = () => Array.from(container.querySelectorAll('button'));
const button = (text) => buttons().find((element) => element.textContent.trim() === text);
const click = (element) => act(() => {
    element.dispatchEvent(new MouseEvent('click', {bubbles: true}));
});
const type = (element, value) => act(() => {
    const setter = Object.getOwnPropertyDescriptor(Object.getPrototypeOf(element), 'value').set;
    setter.call(element, value);
    element.dispatchEvent(new Event('input', {bubbles: true}));
});
const question = () => container.textContent;

beforeEach(() => {
    sent = [];
    sentAnswers = [];
    container = document.createElement('div');
    document.body.appendChild(container);
    act(() => {
        ReactDOM.render(<QuestionStepper disabled={false} onSubmit={(message, answers) => sent.push(message) && sentAnswers.push(answers)} questions={questions} />, container);
    });
});

afterEach(() => {
    ReactDOM.unmountComponentAtNode(container);
    container.remove();
});

test('one question at a time, then a review, then everything in one message', () => {
    expect(question()).toContain('Who is it for?');
    expect(question()).not.toContain('Which topics?');
    expect(button('eekes_ai_content.next').disabled).toBe(true);

    // picking the answer of a one-answer question moves on by itself
    click(button('Customers'));
    expect(question()).toContain('Which topics?');

    // several answers: no auto-advance
    click(button('Prices'));
    click(button('Team'));
    expect(question()).toContain('Which topics?');
    click(button('eekes_ai_content.next'));

    // an open question: type, then next - this one is kept for the whole website
    expect(question()).toContain('Deadline?');
    expect(question()).toContain('eekes_ai_content.remembered_for_site');
    type(container.querySelector('input'), 'End of the month');
    click(button('eekes_ai_content.next'));

    expect(question()).toContain('eekes_ai_content.review_answers');
    expect(question()).toContain('Customers');
    expect(question()).toContain('Prices, Team');
    expect(question()).toContain('End of the month');
    expect(sent).toEqual([]);

    click(button('eekes_ai_content.send_answers'));
    expect(sent).toEqual([
        'My answers:\n\n1. Who is it for?\n   Customers\n2. Which topics?\n   Prices, Team\n3. Deadline?\n   End of the month',
    ]);
    expect(sentAnswers[0]).toEqual([
        {question: 'Who is it for?', answer: 'Customers', remember: false},
        {question: 'Which topics?', answer: 'Prices, Team', remember: false},
        {question: 'Deadline?', answer: 'End of the month', remember: true},
    ]);
});

test('a question can be skipped and changed from the review', () => {
    click(button('eekes_ai_content.skip'));
    click(button('eekes_ai_content.skip'));
    click(button('eekes_ai_content.skip'));
    expect(question()).toContain('eekes_ai_content.skipped');

    // the review links back to the question
    click(buttons().find((element) => element.textContent.includes('Who is it for?')));
    expect(question()).toContain('Who is it for?');
    click(button('Partners'));
    click(button('eekes_ai_content.skip'));
    click(button('eekes_ai_content.skip'));

    click(button('eekes_ai_content.send_answers'));
    expect(sent[0]).toContain('1. Who is it for?\n   Partners');
    expect(sent[0]).toContain('2. Which topics?\n   (skipped - decide this yourself)');
});

test('typing an own answer replaces a picked option of a one-answer question', () => {
    click(button('Customers'));
    click(button('eekes_ai_content.back'));
    expect(question()).toContain('Who is it for?');

    type(container.querySelector('input'), 'Students');
    expect(button('Customers').getAttribute('aria-pressed')).toBe('false');
    click(button('eekes_ai_content.next'));
    click(button('eekes_ai_content.skip'));
    click(button('eekes_ai_content.skip'));

    click(button('eekes_ai_content.send_answers'));
    expect(sent[0]).toContain('1. Who is it for?\n   Students');
});

test('nothing can be picked, typed or sent while the assistant works', () => {
    act(() => {
        ReactDOM.render(<QuestionStepper disabled onSubmit={(message) => sent.push(message)} questions={questions} />, container);
    });

    expect(buttons().every((element) => element.disabled)).toBe(true);
    expect(container.querySelector('input').disabled).toBe(true);
});
