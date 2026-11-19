// @flow
/*
 * When the message list of a panel follows the newest message. Updates arrive all the time - the
 * progress of a run, every item of a plan - and pulling the list down on each of them would make
 * it impossible to read further up. Tested in tests/scroll.test.js.
 */

// how close to the end still counts as "at the bottom", in pixels
const NEAR_BOTTOM = 48;

type Scrollable = {scrollTop: number, scrollHeight: number, clientHeight: number};

export function isNearBottom(element: Scrollable): boolean {
    return element.scrollHeight - element.scrollTop - element.clientHeight <= NEAR_BOTTOM;
}

/**
 * Whether to scroll to the end after an update: when the list was at the bottom already, when a
 * conversation was just opened, or when the editor sent a message.
 */
export function followsBottom(before: {nearBottom: boolean, count: number}, count: number, lastRole: ?string): boolean {
    if (before.nearBottom) {
        return true;
    }

    if (before.count === 0 && count > 0) {
        return true;
    }

    return count > before.count && lastRole === 'user';
}

/**
 * For a panel component: call `before()` in getSnapshotBeforeUpdate and `after()` in
 * componentDidUpdate with the messages it shows.
 */
export class BottomFollower {
    count: number = 0;

    before(element: ?Scrollable): ?boolean {
        return element ? isNearBottom(element) : undefined;
    }

    after(element: ?Scrollable, nearBottom: ?boolean, messages: $ReadOnlyArray<{role: string}>) {
        const count = messages.length;
        const last = messages[count - 1];

        if (element && followsBottom({nearBottom: !!nearBottom, count: this.count}, count, last ? last.role : undefined)) {
            element.scrollTop = element.scrollHeight;
        }

        this.count = count;
    }
}
