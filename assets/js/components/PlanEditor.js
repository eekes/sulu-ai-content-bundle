// @flow
import React from 'react';
import {action, observable, toJS} from 'mobx';
import {observer} from 'mobx-react';
import {Button} from 'sulu-admin-bundle/components';
import {translate} from 'sulu-admin-bundle/utils';
import styles from './planner.scss';
import type {PageOption, PlanItem, TemplateOption} from '../utils/plan';

type Props = {|
    disabled: boolean,
    onApprove: (plan: Array<PlanItem>) => void,
    // for a plan of articles: the templates of the page they go below, when the plan creates it
    pageTemplates: Array<TemplateOption>,
    pages: Array<PageOption>,
    plan: Array<PlanItem>,
    templates: Array<TemplateOption>,
|};

/**
 * The proposed pages, for the editor to adjust before anything is written: rename, change the
 * template or the page they go below, reword the brief, reorder and remove. Approving writes and
 * creates them as drafts. The page a plan of articles creates for them is marked as a new page,
 * with page templates, and offered as the page the articles go below.
 */
@observer
class PlanEditor extends React.Component<Props> {
    @observable items: Array<PlanItem> = [];

    constructor(props: Props) {
        super(props);

        this.items = toJS(props.plan);
    }

    @action change(index: number, key: string, value: string) {
        this.items[index] = {...this.items[index], [key]: value};
    }

    @action move(index: number, offset: number) {
        const target = index + offset;

        if (target < 0 || target >= this.items.length) {
            return;
        }

        const [item] = this.items.splice(index, 1);
        this.items.splice(target, 0, item);
    }

    @action remove(index: number) {
        this.items.splice(index, 1);
    }

    handleApprove = () => {
        this.props.onApprove(toJS(this.items).filter((item) => item.title.trim() !== ''));
    };

    templateOptions(item: PlanItem): Array<{key: string, title: string}> {
        const {pageTemplates, templates} = this.props;
        const options = item.kind === 'page' ? pageTemplates : templates;

        // the plan may name a template the options do not know (yet): keep it selectable
        return options.some((template) => template.key === item.template)
            ? options
            : [{key: item.template, title: item.templateTitle || item.template}, ...options];
    }

    render() {
        const {disabled, pages} = this.props;
        // the new page of the plan, for the other items to go below
        const newPages = this.items.filter((item) => item.kind === 'page' && item.key);

        return (
            <div className={styles.plan}>
                {this.items.map((item, index) => (
                    <div className={styles.planItem} key={index}>
                        <div className={styles.planItemHeader}>
                            <input
                                className={styles.planTitle}
                                disabled={disabled}
                                onChange={(event) => this.change(index, 'title', event.currentTarget.value)}
                                value={item.title}
                            />
                            {item.kind === 'page' && <span className={styles.newPage}>{translate('eekes_ai_content.plan_new_page')}</span>}
                            <Button disabled={disabled || index === 0} icon="su-angle-up" onClick={() => this.move(index, -1)} size="small" skin="icon" />
                            <Button disabled={disabled || index === this.items.length - 1} icon="su-angle-down" onClick={() => this.move(index, 1)} size="small" skin="icon" />
                            <Button disabled={disabled} icon="su-trash-alt" onClick={() => this.remove(index)} size="small" skin="icon" />
                        </div>
                        <label className={styles.planField}>
                            {translate('eekes_ai_content.template')}
                            <select disabled={disabled} onChange={(event) => this.change(index, 'template', event.currentTarget.value)} value={item.template}>
                                {this.templateOptions(item).map((template) => <option key={template.key} value={template.key}>{template.title}</option>)}
                            </select>
                        </label>
                        <label className={styles.planField}>
                            {translate('eekes_ai_content.plan_parent')}
                            <select disabled={disabled} onChange={(event) => this.change(index, 'parent', event.currentTarget.value)} value={item.parent || ''}>
                                {!pages.some((page) => page.uuid === item.parent) && !newPages.some((page) => 'new:' + (page.key || '') === item.parent) &&
                                    <option value={item.parent || ''}>{item.parentTitle || item.parent}</option>
                                }
                                {item.kind !== 'page' && newPages.map((page) => (
                                    <option key={'new:' + (page.key || '')} value={'new:' + (page.key || '')}>
                                        {page.title} ({translate('eekes_ai_content.plan_new_page')})
                                    </option>
                                ))}
                                {pages.map((page) => (
                                    <option key={page.uuid} value={page.uuid}>{'— '.repeat(page.depth)}{page.title || page.url}</option>
                                ))}
                            </select>
                        </label>
                        <textarea
                            className={styles.planBrief}
                            disabled={disabled}
                            onChange={(event) => this.change(index, 'brief', event.currentTarget.value)}
                            rows={3}
                            value={item.brief}
                        />
                    </div>
                ))}
                <div className={styles.planNote}>{translate('eekes_ai_content.plan_note')}</div>
                <Button disabled={disabled || this.items.length === 0} icon="su-check" onClick={this.handleApprove} skin="primary">
                    {translate('eekes_ai_content.create_drafts', {count: this.items.length})}
                </Button>
            </div>
        );
    }
}

export default PlanEditor;
