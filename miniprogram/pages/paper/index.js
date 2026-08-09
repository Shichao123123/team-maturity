const { get, post } = require('../../utils/request');
const { ensureLogin } = require('../../utils/auth');

Page({
  data: {
    batchId: 0,
    paperId: 0,
    loading: true,
    saving: false,
    submitting: false,
    readonly: false,
    err: '',
    total: 0,
    answered: 0,
    index: 0,
    questions: [],
    current: null,
    progressText: '0/0',
    progressPct: 0,
    saveTip: '',
  },

  onLoad(query) {
    const batchId = parseInt(query.batch_id || '0', 10) || 0;
    const paperId = parseInt(query.paper_id || '0', 10) || 0;
    const teamId = parseInt(query.team_id || '0', 10) || 0;
    this.setData({ batchId, paperId });
    this._teamId = teamId;
    this._saveSeq = 0;
    this._savePromise = Promise.resolve();
    this.loadPaper();
  },

  loadPaper() {
    this.setData({ loading: true, err: '' });
    ensureLogin()
      .then(() => get('/TmMini/profile'))
      .then((profileBody) => {
        const session = (profileBody.data && profileBody.data.session) || {};
        const member = (profileBody.data && profileBody.data.member) || {};
        const batchId = this.data.batchId || session.batch_id || 0;
        const teamId = this._teamId || session.team_id || member.team_id || 0;
        const name = session.name || member.name || '';
        const memberId = session.member_id || member.id || 0;
        if (!batchId) {
          throw new Error('缺少批次信息');
        }
        // 已有答卷时后端可凭 wx_user 找回，不必强求本页带齐班组/姓名
        return post('/TmMini/startPaper', {
          batch_id: batchId,
          team_id: teamId || 0,
          name: name || '',
          member_id: memberId || 0,
        });
      })
      .then((body) => {
        const data = body.data || {};
        const questions = data.questions || [];
        let index = 0;
        for (let i = 0; i < questions.length; i++) {
          if (!questions[i].selected_option_id) {
            index = i;
            break;
          }
          if (i === questions.length - 1) {
            index = i;
          }
        }
        const readonly = body.code === 202 || Number(data.status) === 1;
        this.setData({
          loading: false,
          batchId: data.batch_id || this.data.batchId,
          paperId: data.paper_id,
          questions,
          total: data.total || questions.length,
          answered: data.answered || 0,
          index,
          readonly,
        });
        this.refreshCurrent();
        if (readonly) {
          wx.showModal({
            title: '答卷已提交',
            content: '本场测评已提交，将查看结果',
            showCancel: false,
            success: () => {
              wx.redirectTo({
                url: '/pages/result/index?batch_id=' + this.data.batchId,
              });
            },
          });
        }
      })
      .catch((err) => {
        const action = err && err.body && err.body.data && err.body.data.action;
        if (action === 'home') {
          wx.showToast({ title: '测评已结束，返回首页', icon: 'none' });
          setTimeout(() => {
            wx.reLaunch({ url: '/pages/index/index' });
          }, 500);
          return;
        }
        this.setData({ loading: false, err: err.message || '加载试卷失败' });
      });
  },

  refreshCurrent() {
    const { questions, index, total, answered } = this.data;
    const current = questions[index] || null;
    const pct = total > 0 ? Math.round((answered / total) * 100) : 0;
    this.setData({
      current,
      progressText: answered + '/' + total,
      progressPct: pct,
    });
    wx.setNavigationBarTitle({
      title: total ? '第 ' + (index + 1) + ' / ' + total + ' 题' : '答题',
    });
  },

  /**
   * 保存当前题；返回 Promise。保存完成前禁止翻题。
   * @param {number} optionId
   * @param {boolean} autoNext 保存成功后是否自动下一题
   */
  saveAnswer(optionId, autoNext) {
    if (this.data.readonly) {
      return Promise.resolve(false);
    }
    const q = this.data.current;
    if (!q || !optionId) {
      return Promise.resolve(false);
    }
    if (Number(q.selected_option_id) === Number(optionId) && !this.data.saving) {
      // 已选同一选项：若要求下一题则直接翻
      if (autoNext) {
        this.goNextIfReady();
      }
      return Promise.resolve(true);
    }

    const questions = this.data.questions.slice();
    const idx = this.data.index;
    const prevSelected = q.selected_option_id;
    questions[idx] = Object.assign({}, q, { selected_option_id: optionId });
    const seq = ++this._saveSeq;
    this.setData({
      questions,
      current: questions[idx],
      saving: true,
      saveTip: '正在保存…',
    });

    const job = post('/TmMini/answer', {
      paper_id: this.data.paperId,
      question_id: q.question_id,
      option_id: optionId,
    })
      .then((body) => {
        if (seq !== this._saveSeq) {
          return true;
        }
        const prog = body.data || {};
        this.setData({
          answered: prog.answered != null ? prog.answered : this.data.answered,
          saving: false,
          saveTip: '已保存',
        });
        this.refreshCurrent();
        if (autoNext) {
          setTimeout(() => {
            if (seq === this._saveSeq && !this.data.saving) {
              this.goNextIfReady();
            }
          }, 120);
        } else {
          setTimeout(() => {
            if (this.data.saveTip === '已保存') {
              this.setData({ saveTip: '' });
            }
          }, 800);
        }
        return true;
      })
      .catch((err) => {
        if (seq !== this._saveSeq) {
          return false;
        }
        questions[idx] = Object.assign({}, q, { selected_option_id: prevSelected });
        this.setData({
          questions,
          current: questions[idx],
          saving: false,
          saveTip: '',
        });
        if (err.code === 202) {
          this.setData({ readonly: true });
          wx.showToast({ title: '答卷已锁定', icon: 'none' });
          return false;
        }
        wx.showToast({ title: err.message || '保存失败，请重选', icon: 'none' });
        return false;
      });

    // 供提交等待：失败时也要带着 false 往下传，避免保存失败仍弹提交
    this._savePromise = job;
    return job;
  },

  chooseOption(e) {
    if (this.data.readonly || this.data.saving) {
      return;
    }
    const optionId = Number(e.currentTarget.dataset.id);
    // 选中后只保存，不自动跳下一题（由用户点「下一题」）
    this.saveAnswer(optionId, false);
  },

  goNextIfReady() {
    if (this.data.saving) {
      return;
    }
    if (this.data.index >= this.data.total - 1) {
      this.setData({ saveTip: '' });
      return;
    }
    this.setData({ index: this.data.index + 1, saveTip: '' });
    this.refreshCurrent();
  },

  prevQ() {
    if (this.data.saving) {
      wx.showToast({ title: '请等待保存完成', icon: 'none' });
      return;
    }
    if (this.data.index <= 0) return;
    this.setData({ index: this.data.index - 1, saveTip: '' });
    this.refreshCurrent();
  },

  nextQ() {
    if (this.data.saving) {
      wx.showToast({ title: '请等待保存完成', icon: 'none' });
      return;
    }
    const cur = this.data.current;
    if (!cur || !cur.selected_option_id) {
      wx.showToast({ title: '请先选择本题答案', icon: 'none' });
      return;
    }
    this.goNextIfReady();
  },

  submitPaper() {
    if (this.data.readonly || this.data.submitting) return;
    const run = () => {
      const { answered, total, paperId, batchId } = this.data;
      if (answered < total) {
        wx.showToast({
          title: '还有 ' + (total - answered) + ' 题未作答',
          icon: 'none',
        });
        return;
      }
      wx.showModal({
        title: '确认提交',
        content: '提交后不可修改，是否提交本场答卷？',
        success: (res) => {
          if (!res.confirm) return;
          this.setData({ submitting: true });
          post('/TmMini/submit', { paper_id: paperId })
            .then(() => {
              wx.redirectTo({
                url: '/pages/result/index?batch_id=' + batchId,
              });
            })
            .catch((err) => {
              wx.showToast({ title: err.message || '提交失败', icon: 'none' });
            })
            .finally(() => {
              this.setData({ submitting: false });
            });
        },
      });
    };

    if (this.data.saving) {
      wx.showToast({ title: '正在保存，请稍候…', icon: 'none' });
      this._savePromise.then((ok) => {
        if (ok === false) {
          wx.showToast({ title: '保存失败，请重选后再提交', icon: 'none' });
          return;
        }
        run();
      });
      return;
    }
    run();
  },
});
