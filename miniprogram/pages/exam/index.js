const { get, post, setToken } = require('../../utils/request');
const { ensureLogin } = require('../../utils/auth');
const config = require('../../config');

Page({
  data: {
    orgShortName: config.orgShortName,
    batchId: 0,
    batchName: '',
    teams: [],
    teamIndex: -1,
    teamId: 0,
    teamLabel: '',
    name: '',
    loading: true,
    submitting: false,
    err: '',
    showForm: false,
  },

  onLoad(query) {
    let batchId = parseInt(query.batch_id || query.batchId || '0', 10) || 0;
    const teamId = parseInt(query.team_id || '0', 10) || 0;
    // 正式小程序码：参数在 scene（如 b=12 或 batch_id=12）
    if (!batchId && query.scene) {
      batchId = this.parseBatchIdFromScene(query.scene);
    }
    this.setData({ batchId });
    if (batchId <= 0) {
      this.setData({
        loading: false,
        err: '缺少批次参数。请扫描测评二维码进入。',
      });
      return;
    }
    this.bootstrap(batchId, teamId);
  },

  parseBatchIdFromScene(scene) {
    try {
      const raw = decodeURIComponent(String(scene || ''));
      let m = raw.match(/(?:^|[?&])(?:batch_id|b)=(\d+)/i);
      if (m) return parseInt(m[1], 10) || 0;
      m = raw.match(/^(\d+)$/);
      return m ? parseInt(m[1], 10) || 0 : 0;
    } catch (e) {
      return 0;
    }
  },

  bootstrap(batchId, preferTeamId) {
    this.setData({ loading: true, err: '', showForm: false });
    ensureLogin()
      .then(() => get('/TmMini/entryStatus', { batch_id: batchId }))
      .then((body) => {
        const data = body.data || {};
        const action = data.action || 'register';
        wx.setNavigationBarTitle({
          title: data.batch_name || '进入测评',
        });

        // 已做过题：不再要求选班组填姓名
        if (action === 'result') {
          wx.redirectTo({
            url: '/pages/result/index?batch_id=' + batchId,
          });
          return;
        }
        if (action === 'continue') {
          wx.redirectTo({
            url:
              '/pages/paper/index?paper_id=' +
              (data.paper_id || '') +
              '&batch_id=' +
              batchId +
              '&team_id=' +
              (data.team_id || ''),
          });
          return;
        }
        if (action === 'home') {
          // 测试模式仍可继续；否则回首页看历史
          if (data.can_retake && data.paper_id) {
            wx.redirectTo({
              url:
                '/pages/paper/index?paper_id=' +
                data.paper_id +
                '&batch_id=' +
                batchId +
                '&team_id=' +
                (data.team_id || ''),
            });
            return;
          }
          wx.showToast({ title: '测评已结束，正在进入首页', icon: 'none' });
          setTimeout(() => {
            wx.reLaunch({ url: '/pages/index/index' });
          }, 400);
          return;
        }
        if (action === 'blocked') {
          if (data.can_retake) {
            // 测试模式：仍展示登记表单
            const teams = data.teams && data.teams.length
              ? data.teams
              : [];
            this.setData({
              loading: false,
              showForm: true,
              batchName: data.batch_name || '',
              teams,
              name: data.member_name || '',
            });
            if (!teams.length) {
              // 补拉班组列表
              return get('/TmMini/batchTeams', { batch_id: batchId }).then((tb) => {
                const tdata = tb.data || {};
                this.setData({
                  teams: tdata.teams || [],
                  batchName: tdata.batch_name || data.batch_name || '',
                });
              });
            }
            return;
          }
          this.setData({
            loading: false,
            err: body.msg || '当前不可进入测评。若已作答，请从首页查看历史。',
            batchName: data.batch_name || '',
          });
          return;
        }

        // register：展示表单（默认选中第一项，避免 picker 显示了但 teamId 仍为 0）
        const teams = data.teams || [];
        let teamIndex = -1;
        if (preferTeamId > 0) {
          teamIndex = teams.findIndex((t) => Number(t.team_id) === preferTeamId);
        } else if (data.team_id > 0) {
          teamIndex = teams.findIndex((t) => Number(t.team_id) === Number(data.team_id));
        }
        if (teamIndex < 0 && teams.length > 0) {
          teamIndex = 0;
        }
        const picked = teamIndex >= 0 ? teams[teamIndex] : null;
        this.setData({
          loading: false,
          showForm: true,
          batchName: data.batch_name || '',
          teams,
          teamIndex,
          teamId: picked ? picked.team_id : 0,
          teamLabel: picked
            ? picked.team_name + (picked.org_path ? ' · ' + picked.org_path : '')
            : '',
          name: data.member_name || '',
        });
      })
      .catch((err) => {
        this.setData({ loading: false, err: err.message || '加载失败' });
      });
  },

  goHome() {
    wx.reLaunch({ url: '/pages/index/index' });
  },

  onTeamChange(e) {
    const idx = Number(e.detail.value);
    const team = this.data.teams[idx];
    if (!team) return;
    this.setData({
      teamIndex: idx,
      teamId: team.team_id,
      teamLabel: team.team_name + (team.org_path ? ' · ' + team.org_path : ''),
    });
  },

  onNameInput(e) {
    this.setData({ name: e.detail.value || '' });
  },

  submitEnter() {
    const batchId = this.data.batchId;
    const teamId = this.data.teamId;
    const name = (this.data.name || '').trim();
    const submitting = this.data.submitting;
    if (submitting) return;
    if (!teamId) {
      wx.showToast({ title: '请选择班组', icon: 'none' });
      return;
    }
    if (!name) {
      wx.showToast({ title: '请填写姓名', icon: 'none' });
      return;
    }
    this.setData({ submitting: true, name });
    ensureLogin()
      .then(() =>
        post('/TmMini/enterBatch', {
          batch_id: batchId,
          team_id: teamId,
          name,
        })
      )
      .then((body) => {
        if (body.data && body.data.token) {
          setToken(body.data.token);
        }
        const paperStatus = body.data && Number(body.data.paper_status);
        if (paperStatus === 1) {
          wx.redirectTo({
            url: '/pages/result/index?batch_id=' + batchId,
          });
          return null;
        }
        return post('/TmMini/startPaper', {
          batch_id: batchId,
          team_id: teamId,
          name,
          member_id: (body.data && body.data.member_id) || 0,
        });
      })
      .then((paperBody) => {
        if (!paperBody) return;
        const paper = paperBody.data || {};
        if (paperBody.code === 202 || Number(paper.status) === 1) {
          wx.redirectTo({
            url: '/pages/result/index?batch_id=' + batchId,
          });
          return;
        }
        wx.redirectTo({
          url:
            '/pages/paper/index?paper_id=' +
            paper.paper_id +
            '&batch_id=' +
            batchId,
        });
      })
      .catch((err) => {
        wx.showToast({ title: err.message || '进入失败', icon: 'none', duration: 2500 });
      })
      .finally(() => {
        this.setData({ submitting: false });
      });
  },
});
