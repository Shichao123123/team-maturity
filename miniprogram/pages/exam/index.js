const { get, post, setToken } = require('../../utils/request');
const { ensureLogin } = require('../../utils/auth');
const config = require('../../config');

Page({
  data: {
    orgShortName: config.orgShortName,
    batchId: 0,
    batchName: '',
    teams: [],
    teamTree: [],
    teamId: 0,
    teamLabel: '',
    teamOrgPath: '',
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

  /**
   * 兼容旧接口：仅有扁平 teams 时，按 org_path 拼简易树。
   */
  buildTreeFallback(teams) {
    const list = teams || [];
    if (!list.length) return [];
    const root = { childrenMap: {} };

    list.forEach((t) => {
      const parts = String(t.org_path || '')
        .split(/\s*\/\s*/)
        .map((s) => s.trim())
        .filter(Boolean);
      let cursor = root;
      let pathAcc = [];
      parts.forEach((name, idx) => {
        pathAcc.push(name);
        const key = pathAcc.join('/');
        if (!cursor.childrenMap[key]) {
          cursor.childrenMap[key] = {
            id: 'fo-' + key,
            type: 'org',
            name,
            childrenMap: {},
            children: [],
          };
        }
        cursor = cursor.childrenMap[key];
      });
      const teamNode = {
        id: 't' + t.team_id,
        type: 'team',
        name: t.team_name,
        team_id: t.team_id,
        org_id: t.org_id || 0,
        org_path: t.org_path || '',
        children: [],
      };
      if (!cursor.children) cursor.children = [];
      // 暂存到 map 的 team 列表
      if (!cursor._teams) cursor._teams = [];
      cursor._teams.push(teamNode);
    });

    const toArr = (node) => {
      const orgs = Object.keys(node.childrenMap || {}).map((k) => node.childrenMap[k]);
      return orgs.map((o) => {
        const children = toArr(o).concat(o._teams || []);
        return {
          id: o.id,
          type: 'org',
          name: o.name,
          children,
        };
      });
    };

    const tree = toArr(root);
    // 无组织路径的班组挂根
    list.forEach((t) => {
      if (!String(t.org_path || '').trim()) {
        tree.push({
          id: 't' + t.team_id,
          type: 'team',
          name: t.team_name,
          team_id: t.team_id,
          org_id: t.org_id || 0,
          org_path: '',
          children: [],
        });
      }
    });
    return tree;
  },

  applyTeams(data, preferTeamId) {
    const teams = data.teams || [];
    let teamTree = data.team_tree || [];
    if (!teamTree.length && teams.length) {
      teamTree = this.buildTreeFallback(teams);
    }

    let teamId = 0;
    let teamLabel = '';
    let teamOrgPath = '';
    const prefer = preferTeamId > 0 ? preferTeamId : Number(data.team_id || 0) || 0;
    if (prefer > 0) {
      const hit = teams.find((t) => Number(t.team_id) === prefer);
      if (hit) {
        teamId = hit.team_id;
        teamLabel = hit.team_name;
        teamOrgPath = hit.org_path || '';
      }
    }

    this.setData({
      loading: false,
      showForm: true,
      batchName: data.batch_name || this.data.batchName || '',
      teams,
      teamTree,
      teamId,
      teamLabel,
      teamOrgPath,
      name: data.member_name || this.data.name || '',
    });
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
            if (data.teams && data.teams.length) {
              this.applyTeams(data, preferTeamId);
              return;
            }
            return get('/TmMini/batchTeams', { batch_id: batchId }).then((tb) => {
              const tdata = tb.data || {};
              this.applyTeams(
                {
                  batch_name: tdata.batch_name || data.batch_name || '',
                  teams: tdata.teams || [],
                  team_tree: tdata.team_tree || [],
                  member_name: data.member_name || '',
                  team_id: data.team_id || 0,
                },
                preferTeamId
              );
            });
          }
          this.setData({
            loading: false,
            err: body.msg || '当前不可进入测评。若已作答，请从首页查看历史。',
            batchName: data.batch_name || '',
          });
          return;
        }

        this.applyTeams(data, preferTeamId);
      })
      .catch((err) => {
        this.setData({ loading: false, err: err.message || '加载失败' });
      });
  },

  goHome() {
    wx.reLaunch({ url: '/pages/index/index' });
  },

  onTeamPick(e) {
    const d = e.detail || {};
    this.setData({
      teamId: d.team_id || 0,
      teamLabel: d.team_name || '',
      teamOrgPath: d.org_path || '',
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
