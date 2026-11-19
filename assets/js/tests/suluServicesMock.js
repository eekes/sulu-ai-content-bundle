// The requester of sulu-admin-bundle, recording its calls; the route registry builds readable URLs.
const calls = [];

const respond = (method) => (url, data) => {
    calls.push({method, url, data});

    return Promise.resolve(module.exports.responses.shift() || {});
};

module.exports = {
    calls,
    responses: [],
    Requester: {get: respond('GET'), post: respond('POST'), put: respond('PUT'), patch: respond('PATCH')},
    resourceRouteRegistry: {
        getUrl: (type, resourceKey, params = {}) => `${type}:${resourceKey}` + (Object.keys(params).length ? ' ' + JSON.stringify(params) : ''),
    },
};
