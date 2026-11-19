import {BottomFollower, followsBottom, isNearBottom} from '../utils/scroll';

function element(scrollTop, scrollHeight = 1000, clientHeight = 400) {
    return {scrollTop, scrollHeight, clientHeight};
}

test('a list scrolled to the end, or almost, is near the bottom', () => {
    expect(isNearBottom(element(600))).toBe(true);
    expect(isNearBottom(element(570))).toBe(true);
    expect(isNearBottom(element(300))).toBe(false);
    expect(isNearBottom(element(0, 300, 400))).toBe(true);
});

test('an update leaves the list where the editor scrolled to', () => {
    expect(followsBottom({nearBottom: false, count: 4}, 4, 'assistant')).toBe(false);
});

test('a reply that arrives while the editor reads further up does not pull the list down', () => {
    expect(followsBottom({nearBottom: false, count: 4}, 5, 'assistant')).toBe(false);
});

test('a list that was at the bottom stays there', () => {
    expect(followsBottom({nearBottom: true, count: 4}, 4, 'assistant')).toBe(true);
    expect(followsBottom({nearBottom: true, count: 4}, 5, 'assistant')).toBe(true);
});

test('a message of the editor and a conversation just opened go to the bottom', () => {
    expect(followsBottom({nearBottom: false, count: 4}, 5, 'user')).toBe(true);
    expect(followsBottom({nearBottom: false, count: 0}, 6, 'assistant')).toBe(true);
});

test('the follower keeps the position through progress updates and follows the editor', () => {
    const follower = new BottomFollower();
    const list = element(0);
    const messages = [{role: 'user'}, {role: 'assistant'}];

    follower.after(list, follower.before(list), messages);
    expect(list.scrollTop).toBe(1000);

    list.scrollTop = 100;
    follower.after(list, follower.before(list), messages);
    follower.after(list, follower.before(list), [...messages, {role: 'assistant'}]);
    expect(list.scrollTop).toBe(100);

    follower.after(list, follower.before(list), [...messages, {role: 'assistant'}, {role: 'user'}]);
    expect(list.scrollTop).toBe(1000);
});
