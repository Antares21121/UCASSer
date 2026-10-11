const {defineConfig} = require('@playwright/test');
module.exports = defineConfig({testDir:'./tests/browser', timeout:60000, workers:1, expect:{timeout:10000}, use:{baseURL:process.env.FORUM_BROWSER_URL || 'http://127.0.0.1:8080', headless:true, channel:process.env.FORUM_BROWSER_CHANNEL || undefined}, reporter:'list'});
