// The translator of sulu-admin-bundle, reduced to "key {params}" so tests can assert on keys.
module.exports = {
    translate: (key, params) => (params ? `${key} ${JSON.stringify(params)}` : key),
};
