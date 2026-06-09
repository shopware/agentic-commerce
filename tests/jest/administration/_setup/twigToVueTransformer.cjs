const crypto = require('node:crypto');

exports.getCacheKey = function getCacheKey(fileData, filePath, options) {
    return crypto.createHash('md5')
        .update(fileData + filePath + options.configString, 'utf8')
        .digest('hex');
};

exports.process = function process(src) {
    return {
        code: `module.exports = ${JSON.stringify(src)};`,
    };
};
