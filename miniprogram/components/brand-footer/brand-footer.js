const config = require('../../config');

Component({
  properties: {
    light: {
      type: Boolean,
      value: false,
    },
  },
  data: {
    copyright: config.copyright,
    orgSite: config.orgSite,
  },
});
