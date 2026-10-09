import app from 'flarum/forum/app';
import Button from 'flarum/common/components/Button';
import DiscussionPage from 'flarum/forum/components/DiscussionPage';
import { extend } from 'flarum/common/extend';
import { DataPage, api, link } from './records';
export class PersonalPage extends DataPage {
  async fetch() {
    this.data = (await api('me?page=' + (m.route.param('page') || 1))).data;
  }
  content() {
    const d = this.data;
    const list = (title, rows, path) => m('section.Campus-panel', [m('h2', title), rows.length ? m('ul', rows.map(r => m('li', link(path + r.id, r.title || r.body)))) : m('p', '暂无内容。')]);
    return [m('h1', '我的校园空间'), m('nav.Campus-nav', [link('/u/' + app.session.user.slug(), '公开个人资料与原生发布记录'), link('/settings', '账号、隐私与通知偏好'), link('/following', '关注的讨论'), link('/campus/identity', '校园身份'), link('/campus/cases', '举报与申诉记录')]), m('p', '收藏与个人处理记录仅本人可见。每页最多显示各类 20 条记录。'), list('我的结构化发布', d.published, '/campus/record/'), list('我的内容收藏', d.bookmarks, '/campus/record/'), list('我的普通讨论收藏', d.discussions, '/d/'), m('section.Campus-panel', [m('h2', '我的回答与评论'), d.answers.length ? d.answers.map(p => m('p', link('/d/' + p.discussion_id + '/' + p.number, p.body))) : m('p', '暂无回答或评论。')]), m('nav.Campus-nav', [d.page > 1 ? link('/campus/me?page=' + (d.page - 1), '上一页') : null, [d.published, d.bookmarks, d.discussions, d.answers].some(rows => rows.length === 20) ? link('/campus/me?page=' + (d.page + 1), '下一页') : null])];
  }
}
export function registerBookmarks() {
  extend(DiscussionPage.prototype, 'sidebarItems', function (items) {
    if (!app.session.user || !this.discussion) return;
    const id = this.discussion.id();
    items.add('campus-bookmark', m(Button, {
      className: 'Button',
      onclick: async () => {
        try {
          const old = (await api('discussions/' + id + '/bookmark')).data.bookmarked;
          await api('discussions/' + id + '/bookmark', 'POST', {
            enabled: !old
          });
          app.alerts.show({
            type: 'success'
          }, old ? '已取消收藏' : '已加入我的校园空间收藏');
        } catch (_) {
          app.alerts.show({
            type: 'error'
          }, '收藏失败，请确认内容访问权限。');
        }
      }
    }, '收藏 / 取消收藏'), 5);
  });
}
