Component({
  properties: {
    tree: {
      type: Array,
      value: [],
    },
    teamId: {
      type: Number,
      value: 0,
    },
    label: {
      type: String,
      value: '',
    },
    orgPath: {
      type: String,
      value: '',
    },
    placeholder: {
      type: String,
      value: '请选择班组',
    },
  },

  data: {
    visible: false,
    /** 当前层级节点列表 */
    options: [],
    /** 面包屑：[{name, children}] */
    crumbs: [],
    stepTitle: '选择公司',
  },

  methods: {
    noop() {},

    open() {
      const tree = this.properties.tree || [];
      this.setData({
        visible: true,
        crumbs: [],
        options: tree,
        stepTitle: this.titleForNodes(tree),
      });
      this.triggerEvent('visibilitychange', { visible: true });
    },

    close() {
      if (!this.data.visible) return;
      this.setData({ visible: false });
      this.triggerEvent('visibilitychange', { visible: false });
    },

    /**
     * 根据当前层节点类型给步骤标题。
     */
    titleForNodes(nodes) {
      const list = nodes || [];
      if (!list.length) return '选择';
      const hasOrg = list.some((n) => n.type === 'org');
      const hasTeam = list.some((n) => n.type === 'team');
      if (hasOrg && !hasTeam) {
        const types = list
          .map((n) => Number(n.org_type || 0))
          .filter((t) => t > 0);
        const t = types.length ? types[0] : 0;
        if (t === 1) return '选择公司';
        if (t === 2) return '选择厂区';
        if (t === 3) return '选择部门';
        return '选择组织';
      }
      if (hasTeam && !hasOrg) return '选择班组';
      return '请选择';
    },

    onBack() {
      const crumbs = (this.data.crumbs || []).slice();
      if (!crumbs.length) {
        this.close();
        return;
      }
      crumbs.pop();
      const options = crumbs.length
        ? crumbs[crumbs.length - 1].children
        : this.properties.tree || [];
      this.setData({
        crumbs,
        options,
        stepTitle: this.titleForNodes(options),
      });
    },

    onPick(e) {
      const item = e.currentTarget.dataset.item;
      if (!item) return;

      if (item.type === 'team' && item.team_id) {
        this.triggerEvent('change', {
          team_id: item.team_id,
          team_name: item.name,
          org_path: item.org_path || '',
        });
        this.close();
        return;
      }

      if (item.type === 'org') {
        const children = item.children || [];
        if (!children.length) {
          wx.showToast({ title: '该组织下暂无班组', icon: 'none' });
          return;
        }
        const crumbs = (this.data.crumbs || []).concat([
          {
            name: item.name,
            children,
          },
        ]);
        this.setData({
          crumbs,
          options: children,
          stepTitle: this.titleForNodes(children),
        });
      }
    },
  },
});
