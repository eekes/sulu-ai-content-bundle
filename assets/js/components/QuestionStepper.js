// @flow
import React from 'react';
import classNames from 'classnames';
import {translate} from 'sulu-admin-bundle/utils';
import {answersToMessage, answersToRequest, describeAnswer, emptyAnswer, isAnswered, toggleOption} from '../utils/questions';
import styles from './assistantPanel.scss';
import type {Answer, Question} from '../utils/questions';

type Props = {|
    disabled: boolean,
    onSubmit: (message: string, answers: Array<{question: string, answer: string, remember: boolean}>) => void,
    questions: Array<Question>,
|};

type State = {|
    answers: Array<Answer>,
    note: string,
    step: number,
|};

/**
 * The assistant's questions, one step at a time: pick an answer (or several), or type one of your
 * own, and go to the next. The last step shows every answer - click one to change it - and sends
 * them all in one message.
 *
 * Picking the only answer of a one-answer question moves on by itself, like a quick form.
 */
export default class QuestionStepper extends React.Component<Props, State> {
    constructor(props: Props) {
        super(props);

        this.state = {
            answers: props.questions.map(() => emptyAnswer()),
            note: '',
            step: 0,
        };
    }

    get reviewStep(): number {
        return this.props.questions.length;
    }

    goTo = (step: number) => {
        this.setState({step: Math.max(0, Math.min(step, this.reviewStep))});
    };

    updateAnswer(index: number, answer: Answer) {
        this.setState((state) => ({answers: state.answers.map((current, position) => position === index ? answer : current)}));
    }

    handleOption = (index: number, option: string) => {
        const question = this.props.questions[index];
        const answer = toggleOption(question, this.state.answers[index], option);
        this.updateAnswer(index, answer);

        // a one-answer question with an option picked and nothing typed is done
        if (!question.multiple && answer.selected.length > 0 && answer.custom.trim() === '') {
            this.goTo(index + 1);
        }
    };

    handleCustomChange = (index: number, event: SyntheticEvent<HTMLInputElement>) => {
        const custom = event.currentTarget.value;
        const question = this.props.questions[index];
        const answer = this.state.answers[index];

        // typing your own answer replaces the picked one, unless several answers are allowed
        this.updateAnswer(index, {selected: question.multiple || custom === '' ? answer.selected : [], custom});
    };

    handleCustomKeyDown = (index: number, event: SyntheticKeyboardEvent<HTMLInputElement>) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            this.goTo(index + 1);
        }
    };

    handleNoteChange = (event: SyntheticEvent<HTMLTextAreaElement>) => {
        this.setState({note: event.currentTarget.value});
    };

    handleSkip = () => {
        const {step} = this.state;
        this.updateAnswer(step, emptyAnswer());
        this.goTo(step + 1);
    };

    handleSubmit = () => {
        const {onSubmit, questions} = this.props;
        const {answers, note} = this.state;

        onSubmit(answersToMessage(questions, answers, note), answersToRequest(questions, answers));
    };

    renderProgress() {
        const {questions} = this.props;
        const {answers, step} = this.state;

        return (
            <div className={styles.stepperProgress}>
                {questions.map((question, index) => (
                    <button
                        aria-label={question.question}
                        disabled={this.props.disabled}
                        className={classNames(styles.stepperDot, {
                            [styles.stepperDotActive]: index === step,
                            [styles.stepperDotDone]: index !== step && isAnswered(answers[index]),
                        })}
                        key={index}
                        onClick={() => this.goTo(index)}
                        type="button"
                    />
                ))}
                <button
                    aria-label={translate('eekes_ai_content.review_answers')}
                    disabled={this.props.disabled}
                    className={classNames(styles.stepperDot, styles.stepperDotReview, {[styles.stepperDotActive]: step === this.reviewStep})}
                    onClick={() => this.goTo(this.reviewStep)}
                    type="button"
                />
            </div>
        );
    }

    renderQuestion(index: number) {
        const {disabled, questions} = this.props;
        const question = questions[index];
        const answer = this.state.answers[index];

        return (
            <div className={styles.stepperBody}>
                <div className={styles.stepperCounter}>
                    {translate('eekes_ai_content.question_of', {current: index + 1, total: questions.length})}
                    {question.multiple && <span> · {translate('eekes_ai_content.pick_several')}</span>}
                </div>
                <div className={styles.stepperQuestion}>{question.question}</div>
                {question.remember &&
                    <div className={styles.stepperRemember}>{translate('eekes_ai_content.remembered_for_site')}</div>
                }

                <div className={styles.stepperOptions}>
                    {question.options.map((option) => {
                        const picked = answer.selected.includes(option);

                        return (
                            <button
                                aria-pressed={picked}
                                className={classNames(styles.stepperOption, {[styles.stepperOptionPicked]: picked})}
                                disabled={disabled}
                                key={option}
                                onClick={() => this.handleOption(index, option)}
                                type="button"
                            >
                                <span className={classNames(styles.stepperMark, {[styles.stepperMarkMultiple]: question.multiple})} />
                                {option}
                            </button>
                        );
                    })}
                </div>

                <input
                    className={styles.stepperCustom}
                    disabled={disabled}
                    onChange={(event) => this.handleCustomChange(index, event)}
                    onKeyDown={(event) => this.handleCustomKeyDown(index, event)}
                    placeholder={translate(question.options.length > 0 ? 'eekes_ai_content.own_answer' : 'eekes_ai_content.your_answer')}
                    type="text"
                    value={answer.custom}
                />

                <div className={styles.stepperActions}>
                    <button
                        className={styles.stepperLink}
                        disabled={disabled || index === 0}
                        onClick={() => this.goTo(index - 1)}
                        type="button"
                    >
                        {translate('eekes_ai_content.back')}
                    </button>
                    <span>
                        <button className={styles.stepperLink} disabled={disabled} onClick={this.handleSkip} type="button">
                            {translate('eekes_ai_content.skip')}
                        </button>
                        <button
                            className={styles.stepperNext}
                            disabled={disabled || !isAnswered(answer)}
                            onClick={() => this.goTo(index + 1)}
                            type="button"
                        >
                            {translate('eekes_ai_content.next')}
                        </button>
                    </span>
                </div>
            </div>
        );
    }

    renderReview() {
        const {disabled, questions} = this.props;
        const {answers, note} = this.state;

        return (
            <div className={styles.stepperBody}>
                <div className={styles.stepperCounter}>{translate('eekes_ai_content.review_answers')}</div>
                <ol className={styles.stepperReview}>
                    {questions.map((question, index) => {
                        const answer = describeAnswer(answers[index]);

                        return (
                            <li key={index}>
                                <button className={styles.stepperReviewItem} disabled={disabled} onClick={() => this.goTo(index)} type="button">
                                    <span className={styles.stepperReviewQuestion}>{question.question}</span>
                                    <span className={classNames(styles.stepperReviewAnswer, {[styles.stepperReviewSkipped]: answer === null})}>
                                        {answer === null ? translate('eekes_ai_content.skipped') : answer}
                                    </span>
                                </button>
                            </li>
                        );
                    })}
                </ol>
                <textarea
                    className={styles.stepperNote}
                    disabled={disabled}
                    onChange={this.handleNoteChange}
                    placeholder={translate('eekes_ai_content.anything_else')}
                    rows={2}
                    value={note}
                />
                <div className={styles.stepperActions}>
                    <button className={styles.stepperLink} disabled={disabled} onClick={() => this.goTo(this.reviewStep - 1)} type="button">
                        {translate('eekes_ai_content.back')}
                    </button>
                    <button className={styles.stepperNext} disabled={disabled} onClick={this.handleSubmit} type="button">
                        {translate('eekes_ai_content.send_answers')}
                    </button>
                </div>
            </div>
        );
    }

    render() {
        const {step} = this.state;

        return (
            <div className={styles.stepper}>
                {this.renderProgress()}
                {step >= this.reviewStep ? this.renderReview() : this.renderQuestion(step)}
            </div>
        );
    }
}
