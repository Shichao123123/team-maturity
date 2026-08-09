const { post, getToken, setToken, clearToken } = require('./request');

let loginPromise = null;

// 固定测试账号升级：清掉旧 token（旧逻辑每次 login 都是新 open_id）
const DEV_STABLE_FLAG = 'tm_dev_stable_v1';
try {
  if (!wx.getStorageSync(DEV_STABLE_FLAG)) {
    clearToken();
    wx.setStorageSync(DEV_STABLE_FLAG, '1');
  }
} catch (e) {
  // ignore
}

/**
 * 确保已登录：有 token 直接返回；否则 wx.login → /TmMinilogin/login。
 */
function ensureLogin(force) {
  if (!force && getToken()) {
    return Promise.resolve(getToken());
  }
  if (loginPromise && !force) {
    return loginPromise;
  }
  loginPromise = new Promise((resolve, reject) => {
    wx.login({
      success(loginRes) {
        if (!loginRes.code) {
          loginPromise = null;
          reject(new Error('微信登录失败，请重试'));
          return;
        }
        // 本地测试：传固定 dev_key，与后端固定 open_id 对齐，避免每次登录变成新用户
        post('/TmMinilogin/login', {
          code: loginRes.code,
          dev_key: 'local_tester',
        })
          .then((body) => {
            const token = body.data && body.data.token;
            if (!token) {
              throw new Error(body.msg || '登录未返回 token');
            }
            setToken(token);
            loginPromise = null;
            resolve(token);
          })
          .catch((err) => {
            loginPromise = null;
            reject(err);
          });
      },
      fail() {
        loginPromise = null;
        reject(new Error('无法调用微信登录'));
      },
    });
  });
  return loginPromise;
}

module.exports = {
  ensureLogin,
};
