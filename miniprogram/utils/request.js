const config = require('../config');

function getToken() {
  return wx.getStorageSync('tm_token') || '';
}

function setToken(token) {
  if (token) {
    wx.setStorageSync('tm_token', token);
  }
}

function clearToken() {
  wx.removeStorageSync('tm_token');
}

/**
 * 统一请求。
 * 成功：code===200，或 code===202 且 success===true（已提交只读类接口）。
 * 失败：含 code===202 且 success===false（如答卷已锁仍改题）。
 */
function request(options) {
  const method = (options.method || 'GET').toUpperCase();
  const url = config.baseUrl + options.url;
  // 不用自定义 header 传 token，避免真机调试触发 OPTIONS 预检被后端 405
  const header = Object.assign(
    {
      'Content-Type': 'application/x-www-form-urlencoded',
    },
    options.header || {}
  );
  const data = Object.assign({}, options.data || {});
  const token = getToken();
  if (token && data.token == null) {
    data.token = token;
  }

  return new Promise((resolve, reject) => {
    wx.request({
      url,
      method,
      data,
      header,
      success(res) {
        const body = res.data || {};
        if (typeof body !== 'object') {
          reject(new Error('服务返回异常'));
          return;
        }
        if (body.code === 401) {
          clearToken();
          if (!options._retried) {
            try {
              const { ensureLogin } = require('./auth');
              ensureLogin(true)
                .then(() => {
                  const retryOpts = Object.assign({}, options, { _retried: true });
                  return request(retryOpts);
                })
                .then(resolve)
                .catch(() => {
                  reject(
                    Object.assign(new Error(body.msg || '未登录'), {
                      code: 401,
                      body,
                    })
                  );
                });
              return;
            } catch (e) {
              // fall through
            }
          }
          reject(Object.assign(new Error(body.msg || '未登录'), { code: 401, body }));
          return;
        }
        // 仅 success===true 视为成功；避免把「答卷已锁」的 202 当成保存成功
        if (body.success === true && (body.code === 200 || body.code === 202)) {
          resolve(body);
          return;
        }
        if (body.code === 200 && body.success !== false) {
          resolve(body);
          return;
        }
        reject(Object.assign(new Error(body.msg || '请求失败'), { code: body.code, body }));
      },
      fail(err) {
        reject(new Error((err && err.errMsg) || '网络错误'));
      },
    });
  });
}

function get(url, data) {
  return request({ url, method: 'GET', data });
}

function post(url, data) {
  return request({ url, method: 'POST', data });
}

module.exports = {
  request,
  get,
  post,
  getToken,
  setToken,
  clearToken,
};
