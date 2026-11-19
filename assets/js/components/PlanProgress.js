// @flow
import React from 'react';
import {observer} from 'mobx-react';
import {Button, Loader} from 'sulu-admin-bundle/components';
import {translate} from 'sulu-admin-bundle/utils';
import styles from './planner.scss';
import type {ItemState, PlanItem} from '../utils/plan';

type Props = {|
    items: Array<{item: PlanItem, state: ItemState}>,
    // kind: "page" for the page a plan of articles created, which opens in the page form
    onOpen: (id: string, kind: ?string) => void,
    onRetry: (index: number) => void,
|};

/**
 * How far the approved plan is: every page with its state, a link to the created draft and a
 * retry for a page that failed.
 */
@observer
class PlanProgress extends React.Component<Props> {
    renderState(state: ItemState, index: number, kind: ?string) {
        const {onOpen, onRetry} = this.props;

        switch (state.state) {
            case 'writing':
                return <span className={styles.progressState}>{state.progress || translate('eekes_ai_content.plan_writing')}</span>;
            case 'creating':
                return <span className={styles.progressState}>{translate('eekes_ai_content.plan_creating')}</span>;
            case 'done':
                return (
                    <span className={styles.progressState}>
                        <span className={styles.progressDone}>{translate('eekes_ai_content.plan_done')}</span>
                        <Button onClick={() => onOpen(state.id, kind)} size="small" skin="link">{translate('eekes_ai_content.open')}</Button>
                    </span>
                );
            case 'needs_input':
                return (
                    <span className={styles.progressState}>
                        <span>{translate('eekes_ai_content.plan_needs_input')}</span>
                        <Button onClick={() => onOpen(state.id, kind)} size="small" skin="link">{translate('eekes_ai_content.open')}</Button>
                    </span>
                );
            case 'failed':
                return (
                    <span className={styles.progressState}>
                        <span className={styles.progressFailed}>{state.error}</span>
                        <Button onClick={() => onRetry(index)} size="small" skin="link">{translate('eekes_ai_content.retry')}</Button>
                    </span>
                );
            default:
                return <span className={styles.progressState}>{translate('eekes_ai_content.plan_waiting')}</span>;
        }
    }

    render() {
        return (
            <ul className={styles.progress}>
                {this.props.items.map(({item, state}, index) => (
                    <li className={styles.progressItem} key={index}>
                        <span className={styles.progressIcon}>
                            {(state.state === 'writing' || state.state === 'creating') && <Loader size={14} />}
                        </span>
                        <span className={styles.progressTitle}>
                            {item.title}
                            {item.kind === 'page' && <span className={styles.newPage}>{translate('eekes_ai_content.plan_new_page')}</span>}
                        </span>
                        {this.renderState(state, index, item.kind)}
                    </li>
                ))}
            </ul>
        );
    }
}

export default PlanProgress;
