// @flow
import React from 'react';
import {parseMarkdown} from '../utils/markdown';
import styles from './markdown.scss';
import type {Inline} from '../utils/markdown';

function renderInline(nodes: Array<Inline>): Array<React$Node> {
    return nodes.map((node, index) => {
        switch (node.type) {
            case 'strong':
                return <strong key={index}>{renderInline(node.children)}</strong>;
            case 'em':
                return <em key={index}>{renderInline(node.children)}</em>;
            case 'code':
                return <code key={index}>{node.text}</code>;
            case 'link':
                return <a href={node.href} key={index} rel="noopener noreferrer" target="_blank">{renderInline(node.children)}</a>;
            default:
                return node.text;
        }
    });
}

type Props = {|
    text: string,
|};

/**
 * A message of the assistant with its Markdown formatting - built from parsed data, never from
 * HTML, so a reply cannot inject markup.
 */
export default function Markdown({text}: Props) {
    return (
        <div className={styles.markdown}>
            {parseMarkdown(text).map((block, index) => {
                if (block.type === 'heading') {
                    return <p className={styles.heading} key={index}>{renderInline(block.children)}</p>;
                }

                if (block.type === 'list') {
                    const items = block.items.map((item, itemIndex) => <li key={itemIndex}>{renderInline(item)}</li>);

                    return block.ordered ? <ol key={index}>{items}</ol> : <ul key={index}>{items}</ul>;
                }

                return <p key={index}>{renderInline(block.children)}</p>;
            })}
        </div>
    );
}
