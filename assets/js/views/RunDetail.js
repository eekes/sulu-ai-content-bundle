// @flow
import React from 'react';
import {action, observable} from 'mobx';
import {observer} from 'mobx-react';
import classNames from 'classnames';
import {Loader} from 'sulu-admin-bundle/components';
import {withToolbar} from 'sulu-admin-bundle/containers';
import {ResourceRequester} from 'sulu-admin-bundle/services';
import userStore from 'sulu-admin-bundle/stores/userStore/userStore';
import {translate} from 'sulu-admin-bundle/utils';
import Markdown from '../components/Markdown';
import {highlightJson, splitJson} from '../utils/transcript';
import styles from './runDetail.scss';
import type {ViewProps} from 'sulu-admin-bundle/containers';

const RESOURCE_KEY = 'eekes_ai_content_runs';

const formatNumber = (value: ?number) => (typeof value === 'number' ? value.toLocaleString() : '-');

const formatDate = (value: ?string) => (value ? new Date(value).toLocaleString() : '-');

const formatCost = (usage: Object) => (
    usage.cost === null || usage.cost === undefined ? '-' : `${usage.currency} ${usage.cost.toFixed(4)}`
);

const formatDuration = (milliseconds: ?number) => (
    typeof milliseconds === 'number' ? `${(milliseconds / 1000).toFixed(1)} s` : '-'
);

function JsonBlock({value}: {value: mixed}) {
    return (
        <pre className={styles.code}>
            {highlightJson(value).map((token, index) => (
                token.type === 'punctuation'
                    ? token.text
                    : <span className={styles[token.type]} key={index}>{token.text}</span>
            ))}
        </pre>
    );
}

/**
 * A message of the transcript: its text as text, and every JSON in it pretty-printed.
 */
function TranscriptMessage({content}: {content: string}) {
    return splitJson(content).map((segment, index) => (
        segment.type === 'json'
            ? <JsonBlock key={index} value={segment.value} />
            : <div className={styles.transcriptText} key={index}>{segment.text}</div>
    ));
}

/**
 * One run of the usage log as a page to read: what was asked, what came back, what it cost and
 * the conversation it belongs to. A log entry has nothing to edit, so there are no form fields.
 */
@observer
class RunDetail extends React.Component<ViewProps> {
    @observable run: ?Object = undefined;
    @observable error: ?string = undefined;

    componentDidMount() {
        const {id} = this.props.router.attributes;
        const locale = userStore.user ? userStore.user.locale : 'en';

        ResourceRequester.get(RESOURCE_KEY, {id, locale})
            .then(action((run) => {
                this.run = run;
            }))
            .catch(action(() => {
                this.error = translate('eekes_ai_content.run_not_found');
            }));
    }

    renderStat(label: string, value: string, detail?: ?string, text: boolean = false) {
        return (
            <div className={styles.stat}>
                <div className={styles.statLabel}>{label}</div>
                <div className={classNames(styles.statValue, {[styles.statValueText]: text})}>{value}</div>
                {detail && <div className={styles.statDetail}>{detail}</div>}
            </div>
        );
    }

    renderReply(reply: ?Object) {
        if (!reply) {
            return <p className={styles.muted}>-</p>;
        }

        const properties = Object.keys(reply.properties || {});

        return (
            <div>
                {reply.message && <Markdown text={reply.message} />}

                {reply.type === 'questions' && (reply.questions || []).length > 0 &&
                    <ol className={styles.list}>
                        {reply.questions.map((question, index) => (
                            <li key={index}>
                                {question.question}
                                {question.remember && <span className={styles.tag}>{translate('eekes_ai_content.remembered')}</span>}
                                {question.options.length > 0 && <div className={styles.muted}>{question.options.join(' · ')}</div>}
                            </li>
                        ))}
                    </ol>
                }

                {reply.type === 'outline' && reply.outline &&
                    <ol className={styles.list}>
                        {reply.outline.blocks.map((block, index) => (
                            <li key={index}><strong>{block.title || block.type}</strong> - {block.purpose}</li>
                        ))}
                    </ol>
                }

                {reply.type === 'content' &&
                    <div>
                        <p className={styles.muted}>
                            {translate('eekes_ai_content.changed_fields')}: {[
                                ...properties,
                                ...(Object.keys(reply.seo || {}).length > 0 ? ['SEO'] : []),
                                ...(Object.keys(reply.excerpt || {}).length > 0 ? [translate('eekes_ai_content.excerpt')] : []),
                            ].join(', ') || '-'}
                        </p>
                        <details className={styles.details}>
                            <summary>{translate('eekes_ai_content.show_content')}</summary>
                            <JsonBlock value={{template: reply.template, properties: reply.properties, seo: reply.seo, excerpt: reply.excerpt}} />
                        </details>
                    </div>
                }

                {(reply.warnings || []).length > 0 &&
                    <ul className={styles.warnings}>
                        {reply.warnings.map((warning, index) => <li key={index}>{warning}</li>)}
                    </ul>
                }
            </div>
        );
    }

    renderConversation(run: Object) {
        const {conversation} = run;

        if (!conversation || conversation.messages.length === 0) {
            return null;
        }

        return (
            <section className={styles.section}>
                <h2>{translate('eekes_ai_content.conversation')}</h2>
                <div className={styles.conversation}>
                    {conversation.messages.map((message, index) => (
                        <div
                            className={classNames(styles.bubble, styles[message.role], {[styles.current]: message.runId === run.id})}
                            key={index}
                        >
                            {message.role === 'assistant'
                                ? this.renderReply(message.reply)
                                : message.content.split('\n').map((line, lineIndex) => <p key={lineIndex}>{line}</p>)
                            }
                            {message.usage &&
                                <div className={styles.bubbleUsage}>
                                    {formatNumber(message.usage.tokens.total)} tokens · {formatCost(message.usage)} · {formatDuration(message.usage.durationMs)}
                                </div>
                            }
                        </div>
                    ))}
                </div>
            </section>
        );
    }

    render() {
        const {run} = this;

        if (this.error) {
            return <div className={styles.page}><p>{this.error}</p></div>;
        }

        if (!run) {
            return <div className={styles.page}><Loader /></div>;
        }

        const {usage} = run;
        const where = [run.webspaceKey, run.locale, run.formKey, run.resourceId].filter(Boolean).join(' · ');

        return (
            <div className={styles.page}>
                <header className={styles.header}>
                    <div>
                        <h1>{run.actionLabel}</h1>
                        <div className={styles.muted}>
                            {formatDate(run.createdAt)} · {run.user || '-'}{where ? ` · ${where}` : ''}
                        </div>
                    </div>
                    <span className={classNames(styles.status, styles[run.status])}>{run.statusLabel}</span>
                </header>

                <div className={styles.stats}>
                    {this.renderStat(
                        translate('eekes_ai_content.total_tokens'),
                        formatNumber(usage.tokens.total),
                        `${formatNumber(usage.tokens.input)} in · ${formatNumber(usage.tokens.output)} out`
                    )}
                    {this.renderStat(
                        translate('eekes_ai_content.cache_read_tokens'),
                        formatNumber(usage.tokens.cacheRead),
                        `${formatNumber(usage.tokens.cacheWrite)} ${translate('eekes_ai_content.written_to_cache')}`
                    )}
                    {this.renderStat(translate('eekes_ai_content.cost'), formatCost(usage))}
                    {this.renderStat(translate('eekes_ai_content.duration'), formatDuration(usage.durationMs))}
                    {this.renderStat(translate('eekes_ai_content.requests'), formatNumber(usage.requests))}
                    {this.renderStat(translate('eekes_ai_content.model'), usage.model, run.modelKey, true)}
                </div>

                {run.error &&
                    <div className={styles.errorBox}>{run.error}</div>
                }

                <section className={styles.section}>
                    <h2>{translate('eekes_ai_content.what_was_asked')}</h2>
                    <div className={styles.card}>
                        {run.prompt
                            ? run.prompt.split('\n').map((line, index) => <p key={index}>{line}</p>)
                            : <p className={styles.muted}>{translate('eekes_ai_content.no_request')}</p>
                        }
                    </div>
                </section>

                <section className={styles.section}>
                    <h2>{translate('eekes_ai_content.reply')}</h2>
                    <div className={styles.card}>{this.renderReply(run.reply)}</div>
                </section>

                {run.toolCalls.length > 0 &&
                    <section className={styles.section}>
                        <h2>{translate('eekes_ai_content.tool_calls')}</h2>
                        <ul className={styles.tools}>
                            {run.toolCalls.map((call, index) => (
                                <li key={index}>
                                    <code>{call.name}</code>
                                    {Object.keys(call.arguments || {}).length > 0 &&
                                        <span className={styles.muted}> {JSON.stringify(call.arguments)}</span>
                                    }
                                </li>
                            ))}
                        </ul>
                    </section>
                }

                {this.renderConversation(run)}

                {run.transcript &&
                    <section className={styles.section}>
                        <h2>{translate('eekes_ai_content.transcript')}</h2>
                        {run.transcript.map((message, index) => (
                            <details className={styles.details} key={index} open={message.role !== 'system'}>
                                <summary>{message.role}</summary>
                                <div className={styles.transcriptMessage}>
                                    <TranscriptMessage content={message.content} />
                                </div>
                            </details>
                        ))}
                    </section>
                }
            </div>
        );
    }
}

export default withToolbar(RunDetail, function() {
    const {router} = this.props;
    const {backView} = router.route.options;

    return {
        backButton: {
            onClick: () => router.navigate(backView),
        },
    };
});
