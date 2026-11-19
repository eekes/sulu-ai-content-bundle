// @flow
/*
 * The answers of the question steps, and the one message they become. No Sulu imports, so they
 * are tested on their own (tests/questions.test.js).
 */

export type Question = {question: string, options: Array<string>, multiple: boolean, remember?: boolean};

export type Answer = {selected: Array<string>, custom: string};

export const emptyAnswer = (): Answer => ({selected: [], custom: ''});

export function isAnswered(answer: ?Answer): boolean {
    return !!answer && (answer.selected.length > 0 || answer.custom.trim() !== '');
}

/**
 * Picking an option: one question allows one answer (picking again unpicks it), a "multiple"
 * question toggles the option in the list.
 */
export function toggleOption(question: Question, answer: Answer, option: string): Answer {
    const picked = answer.selected.includes(option);

    if (question.multiple) {
        return {
            ...answer,
            selected: picked ? answer.selected.filter((item) => item !== option) : [...answer.selected, option],
        };
    }

    return {...answer, selected: picked ? [] : [option]};
}

/**
 * What the answer reads as: the picked options and the editor's own words, or null when the
 * question was skipped.
 */
export function describeAnswer(answer: ?Answer): ?string {
    if (!answer || !isAnswered(answer)) {
        return null;
    }

    const parts = [...answer.selected];
    const custom = answer.custom.trim();

    if (custom !== '') {
        parts.push(custom);
    }

    return parts.join(', ');
}

/**
 * Every question with its answer, as one message to the assistant. A skipped question is said to
 * be skipped, so the assistant decides it itself instead of asking again.
 */
export function answersToMessage(questions: Array<Question>, answers: Array<?Answer>, note: string = ''): string {
    const lines = questions.map((question, index) => {
        const answer = describeAnswer(answers[index]);

        return `${index + 1}. ${question.question}\n   ${answer === null ? '(skipped - decide this yourself)' : answer}`;
    });

    const message = `My answers:\n\n${lines.join('\n')}`;

    return note.trim() === '' ? message : `${message}\n\nAlso: ${note.trim()}`;
}

/**
 * The answered questions as data, sent next to the message: the server keeps the answers to
 * "remember" questions in the AI context of the webspace.
 */
export function answersToRequest(questions: Array<Question>, answers: Array<?Answer>): Array<{question: string, answer: string, remember: boolean}> {
    const result = [];

    questions.forEach((question, index) => {
        const answer = describeAnswer(answers[index]);

        if (answer !== null) {
            result.push({question: question.question, answer, remember: !!question.remember});
        }
    });

    return result;
}
