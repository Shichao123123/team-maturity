const { ensureLogin } = require('./utils/auth');
const config = require('./config');

App({
  globalData: {
    appName: config.appName,
    user: null,
    session: null,
  },

  onLaunch() {
    ensureLogin().catch((err) => {
      console.warn('auto login', err.message || err);
    });
  },

  ensureLogin(force) {
    return ensureLogin(force);
  },
});
