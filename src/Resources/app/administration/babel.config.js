// Ensures @babel/preset-typescript runs before @babel/preset-env so that
// TypeScript constructor parameter properties (public readonly) are stripped
// before the class transform runs. This fixes compatibility with newer trunk
// admin source files that use this pattern.
module.exports = {
    presets: [
        ['@babel/preset-typescript', { allExtensions: true }],
        ['@babel/preset-env', { targets: { node: 'current' } }],
    ],
    plugins: [],
};
