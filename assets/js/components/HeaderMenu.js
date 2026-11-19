// @flow
import React from 'react';
import {action, observable} from 'mobx';
import {observer} from 'mobx-react';
import classNames from 'classnames';
import {Icon} from 'sulu-admin-bundle/components';
import styles from './headerMenu.scss';

export type HeaderMenuItem = {|
    checked?: boolean,
    disabled?: boolean,
    icon?: string,
    label: string,
    onClick: () => void,
|};

export type HeaderMenuSection = {|
    items: Array<HeaderMenuItem>,
    title?: string,
|};

type Props = {|
    label: string,
    sections: Array<HeaderMenuSection>,
    trigger?: ?string,
|};

/**
 * The dropdown in the header of a panel: every choice spelled out instead of a row of icons,
 * sections split by a line and an optional title above one. A checked item gets a check mark.
 * It is drawn inside the panel, so it always stays on top of it.
 */
@observer
class HeaderMenu extends React.Component<Props> {
    @observable open: boolean = false;
    containerRef: ?HTMLDivElement;

    componentDidMount() {
        document.addEventListener('mousedown', this.handleDocumentMouseDown);
        document.addEventListener('keydown', this.handleDocumentKeyDown);
    }

    componentWillUnmount() {
        document.removeEventListener('mousedown', this.handleDocumentMouseDown);
        document.removeEventListener('keydown', this.handleDocumentKeyDown);
    }

    @action close = () => {
        this.open = false;
    };

    @action handleToggle = () => {
        this.open = !this.open;
    };

    handleDocumentMouseDown = (event: MouseEvent) => {
        if (this.open && this.containerRef && event.target instanceof Node && !this.containerRef.contains(event.target)) {
            this.close();
        }
    };

    handleDocumentKeyDown = (event: KeyboardEvent) => {
        if (this.open && event.key === 'Escape') {
            this.close();
        }
    };

    handleItemClick(item: HeaderMenuItem) {
        if (item.disabled) {
            return;
        }

        this.close();
        item.onClick();
    }

    render() {
        const {label, sections, trigger} = this.props;
        const visible = sections.filter((section) => section.items.length > 0);

        return (
            <div className={styles.container} ref={(ref) => { this.containerRef = ref; }}>
                <button
                    aria-expanded={this.open}
                    aria-haspopup="menu"
                    aria-label={label}
                    className={classNames(styles.trigger, {[styles.triggerOpen]: this.open})}
                    onClick={this.handleToggle}
                    title={label}
                    type="button"
                >
                    {trigger && <span className={styles.triggerText}>{trigger}</span>}
                    <Icon name={trigger ? 'su-angle-down' : 'su-more-horizontal'} />
                </button>
                {this.open &&
                    <div className={styles.menu} role="menu">
                        {visible.map((section, sectionIndex) => (
                            <div className={styles.section} key={sectionIndex}>
                                {section.title && <div className={styles.sectionTitle}>{section.title}</div>}
                                {section.items.map((item, itemIndex) => (
                                    <button
                                        aria-checked={item.checked === undefined ? undefined : item.checked}
                                        className={styles.item}
                                        disabled={item.disabled}
                                        key={itemIndex}
                                        onClick={() => this.handleItemClick(item)}
                                        role={item.checked === undefined ? 'menuitem' : 'menuitemradio'}
                                        type="button"
                                    >
                                        <span className={styles.itemIcon}>
                                            {item.checked && <Icon name="su-check" />}
                                            {item.checked === undefined && item.icon && <Icon name={item.icon} />}
                                        </span>
                                        <span>{item.label}</span>
                                    </button>
                                ))}
                            </div>
                        ))}
                    </div>
                }
            </div>
        );
    }
}

export default HeaderMenu;
