// @flow
import React from 'react';
import {action, computed, observable} from 'mobx';
import {observer} from 'mobx-react';
import classNames from 'classnames';
import {Button, Icon, Loader} from 'sulu-admin-bundle/components';
import {translate} from 'sulu-admin-bundle/utils';
import {outlineToMessage} from '../utils/applyContent';
import {BottomFollower} from '../utils/scroll';
import ConversationStore from '../stores/ConversationStore';
import assistantConfig from '../stores/assistantConfig';
import HeaderMenu from './HeaderMenu';
import Markdown from './Markdown';
import OutlineEditor from './OutlineEditor';
import QuestionStepper from './QuestionStepper';
import styles from './assistantPanel.scss';
import {activeModelLabel, modelSection} from '../utils/headerMenu';
import type {HeaderMenuSection} from './HeaderMenu';
import type {Message, Reply, Usage} from '../stores/ConversationStore';

export type SendRequest = {|
    action: string,
    answers?: Array<{question: string, answer: string, remember: boolean}>,
    message: string,
    focusBlock?: ?number,
    focusProperty?: ?string,
    sourceLocale?: ?string,
|};

type Props = {|
    applied: {[runId: string]: 'applied' | 'undone'},
    blocks: Array<{property: string, index: number, type: string, preview: string}>,
    dock: 'left' | 'right',
    formEmpty: boolean,
    modelKey: ?string,
    onClose: () => void,
    onModelChange: (key: string) => void,
    onOpenConversation: (id: ?string) => void,
    onReapply: (runId: string) => void,
    onReset: () => void,
    onSend: (request: SendRequest) => void,
    onToggleDock: () => void,
    onToggleSnippet: (runId: string, key: string) => void,
    onUndo: (runId: string) => void,
    // the smart content lists of an applied reply that keep showing their old filters on screen
    staleLists: {[runId: string]: Array<string>},
    snippets: {
        created: {[runId: string]: {[key: string]: string}},
        excluded: {[runId: string]: Array<string>},
        state: {[runId: string]: 'creating' | 'created' | 'failed'},
    },
    store: ConversationStore,
    translateLocales: Array<string>,
|};

type Mode = 'free' | 'rewrite_block' | 'translate';

/**
 * The assistant next to the form: quick actions to start with, the conversation, and a message
 * box. Content replies are put into the form straight away - the preview shows them - and can be
 * undone from here.
 */
@observer
class AssistantPanel extends React.Component<Props> {
    @observable draft: string = '';
    @observable mode: Mode = 'free';
    @observable focus: ?{property: string, index: number} = undefined;
    @observable sourceLocale: ?string = undefined;
    @observable lastAction: string = 'chat';
    @observable freeTextWhileAsking: boolean = false;
    @observable showHistory: boolean = false;
    messagesRef: ?HTMLDivElement;
    // the list follows new messages only while the editor is at the bottom (utils/scroll.js)
    bottomFollower: BottomFollower = new BottomFollower();

    componentDidMount() {
        this.bottomFollower.after(this.messagesRef, true, this.props.store.messages);
    }

    getSnapshotBeforeUpdate(): ?boolean {
        return this.bottomFollower.before(this.messagesRef);
    }

    componentDidUpdate(prevProps: Props, prevState: mixed, nearBottom: ?boolean) {
        this.bottomFollower.after(this.messagesRef, nearBottom, this.props.store.messages);
    }

    /**
     * While the assistant works or snippets are being created nothing else can be started: no
     * typing, no quick actions, no suggestions, no Apply - one thing at a time.
     */
    @computed get busy(): boolean {
        const {snippets, store} = this.props;

        return store.running || store.loading || Object.keys(snippets.state).some((runId) => snippets.state[runId] === 'creating');
    }

    @computed get mainAction(): string {
        return this.props.formEmpty ? 'create_page' : 'rewrite_page';
    }

    @action send(actionName: string, message: string, extra: Object = {}) {
        const {onSend, store} = this.props;

        if (this.busy) {
            return;
        }

        this.lastAction = actionName;
        onSend({action: actionName, message, ...extra});
        this.draft = '';
        this.freeTextWhileAsking = false;
    }

    handleSubmit = () => {
        const message = this.draft.trim();

        if (this.mode === 'rewrite_block') {
            if (!this.focus) {
                return;
            }

            this.send('rewrite_block', message, {focusBlock: this.focus.index, focusProperty: this.focus.property});

            return;
        }

        if (this.mode === 'translate') {
            if (!this.sourceLocale) {
                return;
            }

            this.send('translate', message, {sourceLocale: this.sourceLocale});
            this.setMode('free');

            return;
        }

        if (message === '') {
            return;
        }

        const {store} = this.props;
        this.send(store.empty ? this.mainAction : this.followUpAction, message);
    };

    /**
     * While the steps of a question round are open they are the way to answer, so the message box
     * makes way for a link to it - two send buttons would be one too many.
     */
    @computed get questionsOpen(): boolean {
        const {store} = this.props;
        const reply = store.lastReply;
        const last = store.messages[store.messages.length - 1];

        return !store.running && !this.freeTextWhileAsking && !!reply && reply.type === 'questions'
            && reply.questions.length > 0 && !!last && last.role === 'assistant';
    }

    @action handleToggleHistory = () => {
        this.showHistory = !this.showHistory;

        if (this.showHistory) {
            this.props.store.loadHistory();
        }
    };

    @action handleOpenConversation = (id: ?string) => {
        this.showHistory = false;
        this.props.onOpenConversation(id);
    };

    renderHistory() {
        const {store} = this.props;

        return (
            <div className={styles.history}>
                <button className={styles.historyNew} onClick={() => this.handleOpenConversation(undefined)} type="button">
                    <Icon name="su-plus" /> {translate('eekes_ai_content.new_conversation')}
                </button>
                {store.history.length === 0 &&
                    <p className={styles.hint}>{translate('eekes_ai_content.no_history')}</p>
                }
                {store.history.map((item) => (
                    <button
                        className={classNames(styles.historyItem, {[styles.historyItemActive]: item.id === store.id})}
                        key={item.id}
                        onClick={() => this.handleOpenConversation(item.id)}
                        type="button"
                    >
                        <span className={styles.historyTitle}>{item.title || translate('eekes_ai_content.untitled_conversation')}</span>
                        <small>
                            {new Date(item.updatedAt).toLocaleString()} · {translate('eekes_ai_content.turns', {count: item.turns})}
                        </small>
                    </button>
                ))}
            </div>
        );
    }

    @action handleFreeText = () => {
        this.freeTextWhileAsking = true;
    };

    @computed get followUpAction(): string {
        return ['create_page', 'rewrite_page', 'rewrite_block'].includes(this.lastAction) ? this.lastAction : this.mainAction;
    }

    handleKeyDown = (event: SyntheticKeyboardEvent<HTMLTextAreaElement>) => {
        if (event.key === 'Enter' && (event.metaKey || event.ctrlKey)) {
            event.preventDefault();
            this.handleSubmit();
        }
    };

    @action setMode(mode: Mode) {
        this.mode = mode;
        this.focus = undefined;
        this.sourceLocale = mode === 'translate' ? this.props.translateLocales[0] : undefined;
    }

    @action handleFocusChange = (event: SyntheticEvent<HTMLSelectElement>) => {
        const [property, index] = event.currentTarget.value.split('#');
        this.focus = property ? {property, index: parseInt(index)} : undefined;
    };

    @action handleSourceLocaleChange = (event: SyntheticEvent<HTMLSelectElement>) => {
        this.sourceLocale = event.currentTarget.value || undefined;
    };

    @action handleDraftChange = (event: SyntheticEvent<HTMLTextAreaElement>) => {
        this.draft = event.currentTarget.value;
    };

    @computed get menuSections(): Array<HeaderMenuSection> {
        const {dock, modelKey, onModelChange, onReset, onToggleDock} = this.props;

        return [
            {
                items: [
                    {
                        disabled: this.busy,
                        icon: 'su-plus',
                        label: translate('eekes_ai_content.new_conversation'),
                        onClick: onReset,
                    },
                    {
                        disabled: this.busy,
                        icon: 'su-clock',
                        label: translate(this.showHistory ? 'eekes_ai_content.back_to_conversation' : 'eekes_ai_content.earlier_conversations'),
                        onClick: this.handleToggleHistory,
                    },
                    {
                        icon: dock === 'right' ? 'su-arrow-left' : 'su-arrow-right',
                        label: translate(dock === 'right' ? 'eekes_ai_content.dock_left' : 'eekes_ai_content.dock_right'),
                        onClick: onToggleDock,
                    },
                ],
            },
            modelSection(modelKey, onModelChange, this.busy),
        ];
    }

    renderQuickActions() {
        const {blocks, formEmpty, translateLocales} = this.props;

        return (
            <div className={styles.intro}>
                <p>{translate(formEmpty ? 'eekes_ai_content.intro_empty' : 'eekes_ai_content.intro')}</p>
                {!assistantConfig.catalog &&
                    <p className={styles.hint}>{translate('eekes_ai_content.catalog_missing')}</p>
                }
                <div className={styles.quickActions}>
                    <button
                        className={styles.quickAction}
                        disabled={this.busy}
                        onClick={() => this.send(this.mainAction, '')}
                        type="button"
                    >
                        <Icon name="su-magic" />
                        <span>
                            <strong>{translate(formEmpty ? 'eekes_ai_content.action.create_page' : 'eekes_ai_content.action.rewrite_page')}</strong>
                            <small>{translate(formEmpty ? 'eekes_ai_content.quick.create_page' : 'eekes_ai_content.quick.rewrite_page')}</small>
                        </span>
                    </button>
                    {blocks.length > 0 &&
                        <button className={styles.quickAction} disabled={this.busy} onClick={() => this.setMode('rewrite_block')} type="button">
                            <Icon name="su-pen" />
                            <span>
                                <strong>{translate('eekes_ai_content.action.rewrite_block')}</strong>
                                <small>{translate('eekes_ai_content.quick.rewrite_block')}</small>
                            </span>
                        </button>
                    }
                    {!formEmpty &&
                        <button className={styles.quickAction} disabled={this.busy} onClick={() => this.send('seo', '')} type="button">
                            <Icon name="su-search" />
                            <span>
                                <strong>{translate('eekes_ai_content.action.seo')}</strong>
                                <small>{translate('eekes_ai_content.quick.seo')}</small>
                            </span>
                        </button>
                    }
                    {translateLocales.length > 0 &&
                        <button className={styles.quickAction} disabled={this.busy} onClick={() => this.setMode('translate')} type="button">
                            <Icon name="su-language" />
                            <span>
                                <strong>{translate('eekes_ai_content.action.translate')}</strong>
                                <small>{translate('eekes_ai_content.quick.translate')}</small>
                            </span>
                        </button>
                    }
                </div>
            </div>
        );
    }

    renderSnippets(reply: Reply, runId: string) {
        const {onToggleSnippet, snippets} = this.props;
        const excluded = snippets.excluded[runId] || [];
        const created = snippets.created[runId] || {};
        const state = snippets.state[runId];

        return (
            <div className={styles.snippets}>
                <div className={styles.snippetsTitle}>
                    {translate('eekes_ai_content.new_snippets', {count: reply.snippets.length})}
                </div>
                {reply.snippets.map((snippet) => {
                    const title = typeof snippet.properties.title === 'string' ? snippet.properties.title : snippet.key;
                    const done = !!created[snippet.key];

                    return (
                        <label className={classNames(styles.snippet, {[styles.snippetOff]: excluded.includes(snippet.key)})} key={snippet.key}>
                            <input
                                checked={!excluded.includes(snippet.key)}
                                disabled={done || this.busy}
                                onChange={() => onToggleSnippet(runId, snippet.key)}
                                type="checkbox"
                            />
                            <span>
                                <strong>{title}</strong>
                                <small>{snippet.template}{done ? ` · ${translate('eekes_ai_content.snippet_created')}` : ''}</small>
                            </span>
                        </label>
                    );
                })}
                <div className={styles.snippetsNote}>
                    {state === 'failed'
                        ? translate('eekes_ai_content.snippets_failed')
                        : translate(assistantConfig.publishNewSnippets ? 'eekes_ai_content.snippets_published' : 'eekes_ai_content.snippets_drafts')}
                </div>
            </div>
        );
    }

    renderUsage(usage: ?Usage) {
        if (!usage) {
            return null;
        }

        const parts = [`${usage.tokens.total.toLocaleString()} tokens`];

        if (usage.cost !== null && usage.cost !== undefined) {
            parts.push(`${usage.currency} ${usage.cost.toFixed(4)}`);
        }

        if (usage.durationMs) {
            parts.push(`${(usage.durationMs / 1000).toFixed(1)}s`);
        }

        parts.push(usage.model);

        return <div className={styles.usage}>{parts.join(' · ')}</div>;
    }

    renderReply(reply: Reply, message: Message, isLast: boolean) {
        const {applied, onReapply, onUndo, store} = this.props;
        const state = message.runId ? applied[message.runId] : undefined;

        return (
            <div>
                {reply.message && <Markdown text={reply.message} />}

                {reply.type === 'questions' && reply.questions.length > 0 && (isLast
                    ? <QuestionStepper
                        disabled={this.busy}
                        key={message.runId}
                        onSubmit={(text, answers) => this.send(this.followUpAction, text, {answers})}
                        questions={reply.questions}
                    />
                    : <ul className={styles.questions}>
                        {reply.questions.map((question, index) => <li key={index}>{question.question}</li>)}
                    </ul>
                )}

                {reply.type === 'outline' && reply.outline &&
                    <OutlineEditor
                        disabled={!isLast || this.busy}
                        onApprove={(outline) => this.send(this.mainAction, outlineToMessage(outline))}
                        outline={reply.outline}
                    />
                }

                {reply.type === 'content' &&
                    <div className={styles.content}>
                        <div className={styles.changed}>
                            {translate('eekes_ai_content.changed_fields')}: {[
                                ...Object.keys(reply.properties || {}),
                                ...(Object.keys(reply.seo || {}).length > 0 ? ['SEO'] : []),
                                ...(Object.keys(reply.excerpt || {}).length > 0 ? [translate('eekes_ai_content.excerpt')] : []),
                                ...(reply.template ? [translate('eekes_ai_content.template')] : []),
                            ].join(', ') || '-'}
                        </div>
                        {(reply.snippets || []).length > 0 && message.runId && state !== 'applied' &&
                            <div className={styles.notice}>{translate('eekes_ai_content.apply_to_create')}</div>
                        }
                        {(reply.snippets || []).length > 0 && message.runId && this.renderSnippets(reply, message.runId)}
                        {state === 'applied' && message.runId && (this.props.staleLists[message.runId] || []).length > 0 &&
                            <div className={styles.notice}>
                                {translate('eekes_ai_content.smart_content_reload', {fields: (this.props.staleLists[message.runId] || []).join(', ')})}
                            </div>
                        }
                        {reply.warnings.length > 0 &&
                            <ul className={styles.warnings}>
                                {reply.warnings.map((warning, index) => <li key={index}>{warning}</li>)}
                            </ul>
                        }
                        {message.runId &&
                            <div className={styles.contentActions}>
                                {state === 'applied'
                                    ? <Button disabled={this.busy} icon="su-process" onClick={() => onUndo(message.runId || '')} size="small" skin="secondary">
                                        {translate('eekes_ai_content.undo')}
                                    </Button>
                                    : <Button
                                        disabled={this.busy}
                                        icon="su-check"
                                        loading={this.props.snippets.state[message.runId || ''] === 'creating'}
                                        onClick={() => onReapply(message.runId || '')}
                                        size="small"
                                        skin="primary"
                                    >
                                        {translate('eekes_ai_content.apply')}
                                    </Button>
                                }
                                <span className={styles.appliedState}>
                                    {translate(state === 'applied'
                                        ? 'eekes_ai_content.applied'
                                        : message.restored && !state ? 'eekes_ai_content.earlier_session' : 'eekes_ai_content.not_applied')}
                                </span>
                            </div>
                        }
                    </div>
                }

                {isLast && reply.type !== 'questions' && reply.suggestions.length > 0 &&
                    <div className={styles.chips}>
                        {reply.suggestions.map((suggestion) => (
                            <button
                                className={styles.chip}
                                disabled={this.busy}
                                key={suggestion}
                                onClick={() => this.send(this.followUpAction, suggestion)}
                                type="button"
                            >
                                {suggestion}
                            </button>
                        ))}
                    </div>
                }
            </div>
        );
    }

    renderMessages() {
        const {store} = this.props;
        const lastAssistant = store.messages.map((message) => message.role).lastIndexOf('assistant');

        return store.messages.map((message, index) => (
            <div
                className={classNames(styles.message, styles[message.role])}
                key={index}
            >
                {message.role === 'assistant' && message.reply
                    ? this.renderReply(message.reply, message, index === lastAssistant)
                    : message.content.split('\n').map((line, lineIndex) => <p key={lineIndex}>{line}</p>)
                }
                {this.renderUsage(message.usage)}
            </div>
        ));
    }

    renderComposer() {
        const {blocks, store, translateLocales} = this.props;
        const placeholder = translate(
            this.mode === 'translate'
                ? 'eekes_ai_content.placeholder_translate'
                : this.mode === 'rewrite_block' ? 'eekes_ai_content.placeholder_block' : 'eekes_ai_content.placeholder'
        );

        return (
            <div className={styles.composer}>
                {this.mode === 'rewrite_block' &&
                    <div className={styles.focus}>
                        <select disabled={this.busy} onChange={this.handleFocusChange} value={this.focus ? `${this.focus.property}#${this.focus.index}` : ''}>
                            <option value="">{translate('eekes_ai_content.choose_block')}</option>
                            {blocks.map((block) => (
                                <option key={`${block.property}#${block.index}`} value={`${block.property}#${block.index}`}>
                                    {`${block.index + 1}. ${block.type}${block.preview ? ' - ' + block.preview : ''}`}
                                </option>
                            ))}
                        </select>
                        <Button icon="su-times" onClick={() => this.setMode('free')} size="small" skin="icon" />
                    </div>
                }
                {this.mode === 'translate' &&
                    <div className={styles.focus}>
                        <span>{translate('eekes_ai_content.translate_from')}</span>
                        <select disabled={this.busy} onChange={this.handleSourceLocaleChange} value={this.sourceLocale || ''}>
                            {translateLocales.map((locale) => <option key={locale} value={locale}>{locale}</option>)}
                        </select>
                        <Button icon="su-times" onClick={() => this.setMode('free')} size="small" skin="icon" />
                    </div>
                }
                <textarea
                    className={styles.input}
                    disabled={this.busy}
                    onChange={this.handleDraftChange}
                    onKeyDown={this.handleKeyDown}
                    placeholder={placeholder}
                    rows={3}
                    value={this.draft}
                />
                <div className={styles.composerFooter}>
                    <span className={styles.shortcut}>{translate('eekes_ai_content.send_shortcut')}</span>
                    <Button
                        disabled={this.busy || (this.mode === 'free' && this.draft.trim() === '') || (this.mode === 'rewrite_block' && !this.focus)}
                        icon="su-magic"
                        loading={this.busy}
                        onClick={this.handleSubmit}
                        skin="primary"
                    >
                        {translate('eekes_ai_content.send')}
                    </Button>
                </div>
            </div>
        );
    }

    render() {
        const {dock, modelKey, onClose, store} = this.props;

        return (
            <aside className={classNames(styles.panel, styles[dock])}>
                <header className={styles.header}>
                    <Icon className={styles.headerIcon} name="su-magic" />
                    <span className={styles.title}>{translate('eekes_ai_content.assistant')}</span>
                    <HeaderMenu
                        label={translate('eekes_ai_content.menu')}
                        sections={this.menuSections}
                        trigger={activeModelLabel(modelKey)}
                    />
                    <Button icon="su-times" onClick={onClose} size="small" skin="icon" />
                </header>

                {this.showHistory && this.renderHistory()}

                <div
                    className={classNames(styles.messages, {[styles.hidden]: this.showHistory})}
                    ref={(ref) => { this.messagesRef = ref; }}
                >
                    {store.loading && <div className={styles.running}><Loader size={20} /></div>}
                    {store.empty && !store.loading && this.renderQuickActions()}
                    {this.renderMessages()}
                    {store.running &&
                        <div className={styles.running}>
                            <Loader size={20} />
                            <span>
                                {store.progress || translate('eekes_ai_content.thinking')}
                                {store.toolCalls.length > 0 &&
                                    <small>{store.toolCalls.join(', ')}</small>
                                }
                            </span>
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
                    : this.renderComposer()
                }
            </aside>
        );
    }
}

export default AssistantPanel;
