module.exports = {
    testEnvironment: 'node',
    testMatch: ['<rootDir>/**/tests/**/*.test.js'],
    moduleNameMapper: {
        '\\.(css|scss)$': '<rootDir>/tests/styleMock.js',
        '^sulu-admin-bundle/utils$': '<rootDir>/tests/suluUtilsMock.js',
        '^sulu-admin-bundle/services$': '<rootDir>/tests/suluServicesMock.js',
        '^sulu-admin-bundle/components$': '<rootDir>/tests/suluComponentsMock.js',
    },
};
