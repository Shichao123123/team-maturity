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
    expanded: {},
    rows: [],
  },

  observers: {
    'tree, expanded': function () {
      this.refreshRows();
    },
  },

  methods: {
    noop() {},

    open() {
      const tree = this.properties.tree || [];
      const expanded = this.defaultExpanded(tree);
      this.setData({ visible: true, expanded }, () => this.refreshRows());
    },

    close() {
      this.setData({ visible: false });
    },

    defaultExpanded(nodes) {
      const map = {};
      const walk = (list, depth) => {
        (list || []).forEach((n) => {
          if (n.type === 'org' && n.children && n.children.length) {
            // 默认展开前两级，方便找班组
            if (depth < 2) {
              map[n.id] = true;
            }
            walk(n.children, depth + 1);
          }
        });
      };
      walk(nodes, 0);
      return map;
    },

    refreshRows() {
      const tree = this.properties.tree || [];
      const expanded = this.data.expanded || {};
      this.setData({ rows: this.flatten(tree, 0, expanded) });
    },

    flatten(nodes, depth, expanded) {
      const rows = [];
      (nodes || []).forEach((n) => {
        const hasChildren =
          n.type === 'org' && Array.isArray(n.children) && n.children.length > 0;
        rows.push({
          id: n.id,
          type: n.type,
          name: n.name,
          depth,
          team_id: n.team_id || 0,
          org_path: n.org_path || '',
          hasChildren,
          expanded: !!expanded[n.id],
        });
        if (hasChildren && expanded[n.id]) {
          rows.push.apply(rows, this.flatten(n.children, depth + 1, expanded));
        }
      });
      return rows;
    },

    onRowTap(e) {
      const item = e.currentTarget.dataset.item;
      if (!item) return;
      if (item.type === 'org') {
        if (!item.hasChildren) return;
        const expanded = Object.assign({}, this.data.expanded);
        expanded[item.id] = !expanded[item.id];
        this.setData({ expanded }, () => this.refreshRows());
        return;
      }
      if (item.type === 'team' && item.team_id) {
        this.triggerEvent('change', {
          team_id: item.team_id,
          team_name: item.name,
          org_path: item.org_path || '',
        });
        this.setData({ visible: false });
      }
    },
  },
});
