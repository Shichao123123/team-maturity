const config = require('../../config');

Component({
  properties: {
    light: {
      type: Boolean,
      value: false,
    },
  },
  data: {
    orgShortName: config.orgShortName,
    orgName: config.orgName,
    copyright: config.copyright,
    orgSite: config.orgSite,
  },
});
