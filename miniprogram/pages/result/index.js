const { get, post } = require('../../utils/request');
const { ensureLogin } = require('../../utils/auth');
const config = require('../../config');

Page({
  data: {
    orgShortName: config.orgShortName,
    loading: true,
    err: '',
    batchName: '',
    memberName: '',
    teamName: '',
    personalScore: '—',
    personalLevel: '',
    personalFeature: '',
    questionCnt: 0,
    fullCnt: 0,
    submittedAt: '',
    measures: [],
    personalWeak: [],
    answers: [],
    showAnswers: true,
    canRetake: false,
    retaking: false,
  },

  onLoad(query) {
    const batchId = parseInt(query.batch_id || '0', 10) || 0;
    this.batchId = batchId;
    if (batchId <= 0) {
      this.setData({ loading: false, err: '缺少批次参数' });
      return;
    }
    this.loadResult();
  },

  loadResult() {
    this.setData({ loading: true, err: '' });
    ensureLogin()
      .then(() => get('/TmMini/myResult', { batch_id: this.batchId }))
      .then((body) => {
        const d = body.data || {};
        const personal = d.personal || {};
        const team = d.team || {};
        const score =
          personal.score != null && personal.score !== ''
            ? personal.score
            : d.personal_score;
        this.setData({
          loading: false,
          batchName: d.batch_name || '',
          memberName: d.member_name || '',
          teamName: team.team_name || '',
          personalScore: score != null && score !== '' ? String(score) : '—',
          personalLevel: personal.level_name || '',
          personalFeature: personal.feature_desc || '',
          questionCnt: personal.question_cnt || 0,
          fullCnt: personal.full_cnt || 0,
          submittedAt: d.submitted_at || '',
          measures: d.measures || [],
          personalWeak: d.personal_weak || [],
          answers: d.answers || [],
          canRetake: !!d.can_retake,
        });
      })
      .catch((err) => {
        this.setData({ loading: false, err: err.message || '加载结果失败' });
      });
  },

  toggleAnswers() {
    this.setData({ showAnswers: !this.data.showAnswers });
  },

  retakeExam() {
    if (!this.data.canRetake || this.data.retaking) return;
    wx.showModal({
      title: '重新测评',
      content: '测试模式将清空本场答卷并重新作答，确认继续？',
      success: (res) => {
        if (!res.confirm) return;
        this.setData({ retaking: true });
        post('/TmMini/resetPaper', { batch_id: this.batchId })
          .then((body) => {
            const paper = body.data || {};
            wx.redirectTo({
              url:
                '/pages/paper/index?paper_id=' +
                (paper.paper_id || '') +
                '&batch_id=' +
                this.batchId,
            });
          })
          .catch((err) => {
            wx.showToast({ title: err.message || '重置失败', icon: 'none' });
          })
          .finally(() => {
            this.setData({ retaking: false });
          });
      },
    });
  },

  goHome() {
    wx.reLaunch({ url: '/pages/index/index' });
  },
});

