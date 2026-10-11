const config = require('flarum-webpack-config');
process.chdir(__dirname);
const base = config();
// Sources live directly in js/. Apply Flarum's native async-module loader here
// as well, so lazy core modals and composers load their registered chunks.
base.module.rules.find(rule => String(rule.loader).includes('autoChunkNameLoader')).include = __dirname;
module.exports = {
  ...base,
  context: __dirname
};
