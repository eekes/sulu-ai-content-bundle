// @flow
import React from 'react';
import type {Node} from 'react';
import {action, computed, observable} from 'mobx';
import {observer} from 'mobx-react';
import classNames from 'classnames';
import {Button, Icon, Loader} from 'sulu-admin-bundle/components';
import {translate} from 'sulu-admin-bundle/utils';
import assistantConfig from '../stores/assistantConfig';
import {activeModelLabel, modelSection} from '../utils/headerMenu';
import {BottomFollower} from '../utils/scroll';
import HeaderMenu from './HeaderMenu';
import Markdown from './Markdown';
import PlanEditor from './PlanEditor';
import PlanProgress from './PlanProgress';
import QuestionStepper from './QuestionStepper';
import styles from './assistantPanel.scss';
import plannerStyles from './planner.scss';
import type {HeaderMenuSection} from './HeaderMenu';
import type PlannerStore from '../stores/PlannerStore';
import type {Message} from '../stores/ConversationStore';

const MODEL_STORAGE_KEY = 'eekes_ai_content.model';

type Props = {|
    // a select of the webspace, for an article list in a project with several webspaces
    headerExtra?: Node,
    noun: 'pages' | 'articles',
    onClose?: () => void,
    onOpen: (id: string, kind: ?string) => void,
    store: PlannerStore,
|};

function storedModel(): ?string {
    try {
        return window.localStorage.getItem(MODEL_STORAGE_KEY);
    } catch (error) {
        return undefined;
    }
}

/**
 * The planner: a conversation that proposes several new pages or articles, the plan to adjust,
 * and the progress while every one of them is written and created as a draft.
 */
@observer
class PlannerPanel extends React.Component<Props> {
    @observable text: string = '';
    @observable freeText: boolean = false;
    @observable modelKey: ?string = storedModel();
    messagesRef: ?HTMLElement;
    // the list follows new messages only while the editor is at the bottom (utils/scroll.js)
    bottomFollower: BottomFollower = new BottomFollower();

    componentDidMount() {
        this.props.store.openLatest();
        this.bottomFollower.after(this.messagesRef, true, this.props.store.conversation.messages);
    }

    getSnapshotBeforeUpdate(): ?boolean {
        return this.bottomFollower.before(this.messagesRef);
    }

    componentDidUpdate(prevProps: Props, prevState: mixed, nearBottom: ?boolean) {
        this.bottomFollower.after(this.messagesRef, nearBottom, this.props.store.conversation.messages);
    }

    @computed get model(): ?string {
        const keys = assistantConfig.models.map((model) => model.key);

        return this.modelKey && keys.includes(this.modelKey) ? this.modelKey : assistantConfig.defaultModel;
    }

    @computed get selectedTitle(): ?string {
        const {store} = this.props;
        const page = store.pages.find((candidate) => candidate.uuid === store.scope.selectedPage);

        return page ? page.title : undefined;
    }

    @computed get busy(): boolean {
        const {conversation} = this.props.store;

        return conversation.running || conversation.loading || this.props.store.executing;
    }

    @computed get questionsOpen(): boolean {
        const reply = this.props.store.conversation.lastReply;
        const messages = this.props.store.conversation.messages;
        const last = messages[messages.length - 1];

        return !this.freeText && !!reply && reply.type === 'questions' && reply.questions.length > 0
            && !!last && last.role === 'assistant';
    }

    @action handleTextChange = (event: SyntheticInputEvent<HTMLTextAreaElement>) => {
        this.text = event.currentTarget.value;
    };

    @action handleModelChange = (key: string) => {
        this.modelKey = key;

        try {
            window.localStorage.setItem(MODEL_STORAGE_KEY, this.modelKey);
        } catch (error) {
            // only the remembered choice is lost
        }
    };

    @action send = (text: string, answers?: Array<Object>) => {
        if (this.busy || (text.trim() === '' && !answers)) {
            return;
        }

        this.props.store.send(text.trim(), this.model, answers);
        this.text = '';
        this.freeText = false;
    };

    handleKeyDown = (event: SyntheticKeyboardEvent<HTMLTextAreaElement>) => {
        if (event.key === 'Enter' && (event.metaKey || event.ctrlKey)) {
            event.preventDefault();
            this.send(this.text);
        }
    };

    @action handleFreeText = () => {
        this.freeText = true;
    };

    @computed get menuSections(): Array<HeaderMenuSection> {
        return [
            {
                items: [{
                    disabled: this.busy,
                    icon: 'su-plus',
                    label: translate('eekes_ai_content.new_plan'),
                    onClick: this.handleReset,
                }],
            },
            modelSection(this.model, this.handleModelChange, this.busy),
        ];
    }

    @action handleReset = () => {
        this.props.store.reset();
        this.freeText = false;
    };

    handleApprove = (runId: string, plan: Array<Object>) => {
        this.props.store.execute(plan, this.model, runId);
    };

    handleRetry = (runId: string, index: number) => {
        this.props.store.retry(runId, index, this.model);
    };

    /**
     * A plan: its progress once it was approved, the editor while it is the latest reply, a list
     * of its titles otherwise.
     */
    renderPlan(plan: Array<Object>, runId: ?string, isLast: boolean) {
        const {onOpen, store} = this.props;
        const progress = runId ? store.progress[runId] : undefined;

        if (runId && progress) {
            return <PlanProgress items={progress} onOpen={onOpen} onRetry={(index) => this.handleRetry(runId, index)} />;
        }

        if (isLast && runId) {
            return (
                <PlanEditor
                    disabled={this.busy}
                    key={runId}
                    onApprove={(items) => this.handleApprove(runId, items)}
                    pageTemplates={store.pageTemplates}
                    pages={store.pages}
                    plan={plan}
                    templates={store.templates}
                />
            );
        }

        return (
            <ul className={plannerStyles.planSummary}>
                {plan.map((item, index) => <li key={index}>{item.title}</li>)}
            </ul>
        );
    }

    renderMessage(message: Message, isLast: boolean) {
        const {store} = this.props;
        const reply = message.reply;

        if (message.role !== 'assistant' || !reply) {
            return message.content.split('\n').map((line, index) => <p key={index}>{line}</p>);
        }

        return (
            <div>
                {reply.message && <Markdown text={reply.message} />}

                {reply.type === 'questions' && reply.questions.length > 0 && (isLast
                    ? <QuestionStepper
                        disabled={this.busy}
                        key={message.runId}
                        onSubmit={(text, answers) => this.send(text, answers)}
                        questions={reply.questions}
                    />
                    : <ul className={styles.questions}>
                        {reply.questions.map((question, index) => <li key={index}>{question.question}</li>)}
                    </ul>
                )}

                {reply.type === 'plan' && (reply.plan || []).length > 0 && this.renderPlan(reply.plan || [], message.runId, isLast)}

                {(reply.warnings || []).length > 0 &&
                    <ul className={styles.warnings}>
                        {reply.warnings.map((warning, index) => <li key={index}>{warning}</li>)}
                    </ul>
                }

                {isLast && reply.type !== 'questions' && reply.type !== 'plan' && reply.suggestions.length > 0 &&
                    <div className={styles.chips}>
                        {reply.suggestions.map((suggestion) => (
                            <button className={styles.chip} disabled={this.busy} key={suggestion} onClick={() => this.send(suggestion)} type="button">
                                {suggestion}
                            </button>
                        ))}
                    </div>
                }
            </div>
        );
    }

    render() {
        const {headerExtra, noun, onClose, store} = this.props;
        const {conversation} = store;
        const lastAssistant = conversation.messages.map((message) => message.role).lastIndexOf('assistant');

        return (
            <aside className={classNames(styles.panel, styles.right)}>
                <header className={styles.header}>
                    <Icon className={styles.headerIcon} name="su-magic" />
                    <span className={styles.title}>{translate('eekes_ai_content.planner')}</span>
                    {headerExtra}
                    <HeaderMenu
                        label={translate('eekes_ai_content.menu')}
                        sections={this.menuSections}
                        trigger={activeModelLabel(this.model)}
                    />
                    {onClose && <Button icon="su-times" onClick={onClose} size="small" skin="icon" />}
                </header>

                <div className={styles.messages} ref={(ref) => { this.messagesRef = ref; }}>
                    {conversation.loading && <div className={styles.running}><Loader size={20} /></div>}
                    {conversation.empty && !conversation.loading &&
                        <p className={styles.intro}>{translate(noun === 'articles' ? 'eekes_ai_content.plan_intro_articles' : 'eekes_ai_content.plan_intro')}</p>
                    }
                    {conversation.empty && !conversation.loading && this.selectedTitle &&
                        <p className={styles.intro}>{translate('eekes_ai_content.plan_below', {title: this.selectedTitle})}</p>
                    }
                    {conversation.messages.map((message, index) => (
                        <div className={classNames(styles.message, styles[message.role])} key={index}>
                            {this.renderMessage(message, index === lastAssistant)}
                        </div>
                    ))}
                    {conversation.running &&
                        <div className={styles.running}>
                            <Loader size={20} />
                            <span>{conversation.progress || translate('eekes_ai_content.thinking')}</span>
                        </div>
                    }
                </div>

                {assistantConfig.budget &&
                    <div className={styles.budget}>
                        {translate('eekes_ai_content.budget', {
                            spent: assistantConfig.budget.spent.toFixed(2),
                            limit: assistantConfig.budget.limit.toFixed(2),
                        })}
                    </div>
                }

                {this.questionsOpen
                    ? <div className={styles.composer}>
                        <button className={styles.stepperLink} disabled={this.busy} onClick={this.handleFreeText} type="button">
                            {translate('eekes_ai_content.answer_freely')}
                        </button>
                    </div>
                    : <div className={styles.composer}>
                        <textarea
                            className={styles.input}
                            disabled={this.busy}
                            onChange={this.handleTextChange}
                            onKeyDown={this.handleKeyDown}
                            placeholder={translate(noun === 'articles' ? 'eekes_ai_content.plan_placeholder_articles' : 'eekes_ai_content.plan_placeholder')}
                            rows={3}
                            value={this.text}
                        />
                        <div className={styles.composerFooter}>
                            <span className={styles.shortcut}>{translate('eekes_ai_content.send_shortcut')}</span>
                            <Button disabled={this.busy || this.text.trim() === ''} icon="su-magic" onClick={() => this.send(this.text)} skin="primary">
                                {translate('eekes_ai_content.send')}
                            </Button>
                        </div>
                    </div>
                }
            </aside>
        );
    }
}

export default PlannerPanel;
