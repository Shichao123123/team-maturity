const { get } = require('../../utils/request');
const { ensureLogin } = require('../../utils/auth');
const config = require('../../config');

Page({
  data: {
    appName: config.appName,
    orgShortName: config.orgShortName,
    orgGroup: config.orgGroup,
    loading: true,
    list: [],
    err: '',
    rules: [
      '扫码进入测评批次后，选择所属班组并填写真实姓名，即可开始答题。',
      '题目按指标逐题作答，选中选项后自动保存；可随时返回修改未提交前的答案。',
      '须答完本场全部题目后方可提交；提交后答卷锁定，不可再修改。',
      '提交后可查看个人得分、成熟度等级、薄弱指标及答题明细。',
      '同一微信账号在同一批次仅保留一份答卷；已参与过的批次可从下方列表再次查看。',
    ],
    notices: [
      '请在管理员规定的测评时间内完成并提交，逾期可能无法继续作答。',
      '请如实作答，勿代答或随意填写，结果将用于班组安全管理分析。',
      '姓名请与现场身份一致，便于后续结果核对与赋能改进。',

      '个人结果仅展示本人情况，不含班组成绩排名等信息。',
    ],
  },

  onShow() {
    this.loadList();
  },

  onPullDownRefresh() {
    this.loadList().finally(() => wx.stopPullDownRefresh());
  },

  loadList() {
    const seq = (this._loadSeq = (this._loadSeq || 0) + 1);
    this.setData({ loading: true, err: '' });
    return ensureLogin()
      .then(() => get('/TmMini/myBatches'))
      .then((body) => {
        if (seq !== this._loadSeq) return;
        const list = (body.data || []).map((row) => {
          const status = Number(row.paper_status);
          const batchStatus = Number(row.status);
          let status_tag = status === 1 ? '已提交' : '答题中';
          let status_class = status === 1 ? 'tag-done' : 'tag-warn';
          if (status !== 1 && batchStatus === 2) {
            status_tag = '未完成';
            status_class = 'tag-warn';
          }
          return Object.assign({}, row, {
            status_tag,
            status_class,
            score_text:
              status === 1 && row.personal_score != null
                ? String(row.personal_score)
                : '—',
          });
        });
        this.setData({ list, loading: false });
      })
      .catch((err) => {
        if (seq !== this._loadSeq) return;
        this.setData({
          loading: false,
          err: err.message || '加载失败',
          list: [],
        });
      });
  },

  openItem(e) {
    const item = e.currentTarget.dataset.item;
    if (!item) return;
    if (Number(item.paper_status) === 1) {
      wx.navigateTo({
        url: '/pages/result/index?batch_id=' + item.batch_id,
      });
      return;
    }
    if (!item.can_continue && Number(item.status) === 2) {
      wx.showToast({ title: '该批次已结束且未提交', icon: 'none' });
      return;
    }
    wx.navigateTo({
      url:
        '/pages/paper/index?paper_id=' +
        item.paper_id +
        '&batch_id=' +
        item.batch_id +
        '&team_id=' +
        (item.team_id || ''),
    });
  },
});
