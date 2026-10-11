import app from 'flarum/forum/app';
import IndexPage from 'flarum/forum/components/IndexPage';
import IdentityPage from './identity';
import ModerationPage from './moderation';
import CoursesPage from './courses';
import registerNotifications from './notifications';
import { registerBookmarks } from './personal';
import { CasesPage, registerReports } from './cases';
import GovernancePage from './governance';
import { DataPage, api, pageHeading, link } from './ui';
import { registerNative, NativeRecordsPage, RecordRedirect, ComposerEntry, NativeCollectionsPage } from './native';
class RulesPage extends DataPage {
  async fetch() {
    this.data = (await api('rules')).data;
  }
  content() {
    return [pageHeading('社区公约与治理', '友善交流、尊重隐私，让可靠的信息与知识留在校园。'), m('section.Campus-panel', [m('h2', '社区公约'), m('p.Campus-text', this.data.rules)]), m('section.Campus-panel', [m('h2', '治理说明'), m('p.Campus-text', this.data.governance), link('/campus/governance', '查看公开治理记录')])];
  }
}
app.initializers.add('ucasser-campus', () => {
  registerNative();
  registerNotifications();
  registerBookmarks();
  registerReports();
  const routes = {
    campus: ['/campus', IndexPage],
    'campus.homeAlias': ['/campus', IndexPage],
    'campus.rules': ['/campus/rules', RulesPage],
    'campus.identity': ['/campus/identity', IdentityPage],
    'campus.moderation': ['/campus/moderation', ModerationPage],
    'campus.records': ['/campus/records', NativeRecordsPage],
    'campus.record': ['/campus/record/:id', RecordRedirect],
    'campus.publish': ['/campus/publish', ComposerEntry],
    'campus.courses': ['/campus/courses', CoursesPage],
    'campus.me': ['/campus/me', NativeCollectionsPage],
    'campus.search': ['/campus/search', NativeRecordsPage],
    'campus.cases': ['/campus/cases', CasesPage],
    'campus.governance': ['/campus/governance', GovernancePage]
  };
  for (const [name, [path, component]] of Object.entries(routes)) app.routes[name] = {
    path,
    component
  };
});
