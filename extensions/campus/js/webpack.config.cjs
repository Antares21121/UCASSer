const config = require('flarum-webpack-config');
process.chdir(__dirname);
module.exports = {
  ...config(),
  context: __dirname
};
