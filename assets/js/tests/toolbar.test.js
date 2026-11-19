import {withButton} from '../utils/toolbar';

const button = {key: 'eekes_ai_content.plan', type: 'button', label: 'AI'};

test('the button comes after the items of the view', () => {
    const toggle = {type: 'toggler', label: 'Show ghost pages'};
    const config = {items: [toggle], locale: {value: 'en'}};

    expect(withButton(config, button)).toEqual({items: [toggle, button], locale: {value: 'en'}});
    expect(config.items).toEqual([toggle]);
});

test('a config set again keeps one button, with its new state', () => {
    const once = withButton({items: [{type: 'toggler'}]}, button);

    expect(withButton(once, {...button, active: true}).items).toEqual([{type: 'toggler'}, {...button, active: true}]);
});

test('an empty config is left alone', () => {
    expect(withButton({}, button)).toEqual({});
    expect(withButton(undefined, button)).toBeUndefined();
});
