// @flow
import React from 'react';
import {action, observable, toJS} from 'mobx';
import {observer} from 'mobx-react';
import {Button} from 'sulu-admin-bundle/components';
import {translate} from 'sulu-admin-bundle/utils';
import styles from './assistantPanel.scss';

type Block = {type: string, title?: string, purpose: string, snippets?: Array<{title: string, uuid: ?string}>};
type Outline = {template: ?string, templateTitle?: ?string, blocks: Array<Block>};

type Props = {|
    disabled: boolean,
    onApprove: (outline: Outline) => void,
    outline: Outline,
|};

/**
 * The proposed structure, for the editor to adjust before anything is written: reorder, remove
 * and reword blocks, then approve.
 */
@observer
class OutlineEditor extends React.Component<Props> {
    @observable blocks: Array<Block> = [];

    constructor(props: Props) {
        super(props);

        this.blocks = toJS(props.outline.blocks);
    }

    @action move(index: number, offset: number) {
        const target = index + offset;

        if (target < 0 || target >= this.blocks.length) {
            return;
        }

        const [block] = this.blocks.splice(index, 1);
        this.blocks.splice(target, 0, block);
    }

    @action remove(index: number) {
        this.blocks.splice(index, 1);
    }

    @action changePurpose(index: number, purpose: string) {
        this.blocks[index].purpose = purpose;
    }

    handleApprove = () => {
        this.props.onApprove({template: this.props.outline.template, templateTitle: this.props.outline.templateTitle, blocks: toJS(this.blocks)});
    };

    render() {
        const {disabled, outline} = this.props;

        return (
            <div className={styles.outline}>
                {outline.template &&
                    <div className={styles.outlineTemplate}>
                        {translate('eekes_ai_content.template')}: <strong>{outline.templateTitle || outline.template}</strong>
                    </div>
                }
                <ol className={styles.outlineBlocks}>
                    {this.blocks.map((block, index) => (
                        <li className={styles.outlineBlock} key={index}>
                            <div className={styles.outlineBlockHeader}>
                                <span className={styles.outlineType}>{block.title || block.type}</span>
                                <span className={styles.outlineActions}>
                                    <Button
                                        disabled={disabled || index === 0}
                                        icon="su-angle-up"
                                        onClick={() => this.move(index, -1)}
                                        size="small"
                                        skin="icon"
                                    />
                                    <Button
                                        disabled={disabled || index === this.blocks.length - 1}
                                        icon="su-angle-down"
                                        onClick={() => this.move(index, 1)}
                                        size="small"
                                        skin="icon"
                                    />
                                    <Button
                                        disabled={disabled}
                                        icon="su-trash-alt"
                                        onClick={() => this.remove(index)}
                                        size="small"
                                        skin="icon"
                                    />
                                </span>
                            </div>
                            {(block.snippets || []).length > 0 &&
                                <ul className={styles.outlineSnippets}>
                                    {(block.snippets || []).map((snippet, snippetIndex) => (
                                        <li key={snippetIndex}>
                                            <span className={snippet.uuid ? styles.snippetReuse : styles.snippetNew}>
                                                {translate(snippet.uuid ? 'eekes_ai_content.snippet_reuse' : 'eekes_ai_content.snippet_new')}
                                            </span>
                                            {snippet.title}
                                        </li>
                                    ))}
                                </ul>
                            }
                            <textarea
                                className={styles.outlinePurpose}
                                disabled={disabled}
                                onChange={(event) => this.changePurpose(index, event.currentTarget.value)}
                                rows={2}
                                value={block.purpose}
                            />
                        </li>
                    ))}
                </ol>
                <Button disabled={disabled || this.blocks.length === 0} icon="su-check" onClick={this.handleApprove} skin="primary">
                    {translate('eekes_ai_content.write_this_outline')}
                </Button>
            </div>
        );
    }
}

export default OutlineEditor;
