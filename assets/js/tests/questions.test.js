import {answersToMessage, answersToRequest, describeAnswer, emptyAnswer, isAnswered, toggleOption} from '../utils/questions';

const single = {question: 'Who is it for?', options: ['Customers', 'Partners'], multiple: false};
const multiple = {question: 'Which topics?', options: ['Prices', 'Hours', 'Team'], multiple: true};

describe('toggleOption', () => {
    test('a one-answer question keeps one option, picking it again clears it', () => {
        let answer = toggleOption(single, emptyAnswer(), 'Customers');
        expect(answer.selected).toEqual(['Customers']);

        answer = toggleOption(single, answer, 'Partners');
        expect(answer.selected).toEqual(['Partners']);

        answer = toggleOption(single, answer, 'Partners');
        expect(answer.selected).toEqual([]);
    });

    test('a multiple question toggles every option', () => {
        let answer = toggleOption(multiple, emptyAnswer(), 'Prices');
        answer = toggleOption(multiple, answer, 'Team');
        expect(answer.selected).toEqual(['Prices', 'Team']);

        answer = toggleOption(multiple, answer, 'Prices');
        expect(answer.selected).toEqual(['Team']);
    });
});

describe('answers', () => {
    test('an answer is picked options and own words', () => {
        expect(isAnswered(emptyAnswer())).toBe(false);
        expect(isAnswered({selected: [], custom: '  '})).toBe(false);
        expect(describeAnswer({selected: ['Prices', 'Team'], custom: ' and parking '})).toBe('Prices, Team, and parking');
        expect(describeAnswer(emptyAnswer())).toBeNull();
    });

    test('every question becomes one numbered message, skipped ones included', () => {
        const message = answersToMessage(
            [single, multiple, {question: 'Deadline?', options: [], multiple: false}],
            [{selected: ['Customers'], custom: ''}, {selected: ['Prices', 'Hours'], custom: ''}, emptyAnswer()],
            ' Keep it short. '
        );

        expect(message).toBe(
            'My answers:\n\n'
            + '1. Who is it for?\n   Customers\n'
            + '2. Which topics?\n   Prices, Hours\n'
            + '3. Deadline?\n   (skipped - decide this yourself)\n\n'
            + 'Also: Keep it short.'
        );
    });
});

describe('answersToRequest', () => {
    test('only answered questions, with the remember flag the assistant set', () => {
        const name = {question: 'Company name?', options: [], multiple: false, remember: true};

        expect(answersToRequest(
            [name, single, multiple],
            [{selected: [], custom: 'Brood & Zo'}, emptyAnswer(), {selected: ['Prices'], custom: ''}]
        )).toEqual([
            {question: 'Company name?', answer: 'Brood & Zo', remember: true},
            {question: 'Which topics?', answer: 'Prices', remember: false},
        ]);
    });
});
