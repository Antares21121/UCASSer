import app from 'flarum/forum/app';
import Button from './button';
import DiscussionPage from 'flarum/forum/components/DiscussionPage';
import { extend } from 'flarum/common/extend';
import { DataPage, api, link } from './records';
import { pageHeading, emptyState, errorMessage } from './ui';
export class PersonalPage extends DataPage {
  requiresLogin = true;
  async fetch() {
    this.data = (await api('me?page=' + (this.params.page || 1))).data;
  }
  content() {
    const d = this.data;
    const list = (title, rows, path) => m('section.Campus-panel', [m('h2', title), rows.length ? m('ul.Campus-list', rows.map(r => m('li', link(path + r.id, r.title || r.body)))) : emptyState('暂无内容', '浏览你感兴趣的话题，发布或收藏后就能在这里找到。')]);
    return [pageHeading('我的校园空间', '收藏、反馈和处理记录仅本人可见。每页展示各类最近 20 条记录。', link('/campus/publish?type=information', '分享校园信息', {
      className: 'Button Button--primary'
    })), m('div.Campus-actions', [link('/u/' + app.session.user.slug(), '公开个人资料'), link('/settings', '账号、隐私与通知偏好'), link('/following', '关注的讨论'), link('/campus/identity', '校园身份'), link('/campus/cases', '举报与申诉记录')]), m('div.Campus-columns', [list('我的结构化发布', d.published, '/campus/record/'), list('我的内容收藏', d.bookmarks, '/campus/record/'), list('我的普通讨论收藏', d.discussions, '/d/'), m('section.Campus-panel', [m('h2', '我的回答与评论'), d.answers.length ? m('ul.Campus-list', d.answers.map(p => m('li', link('/d/' + p.discussion_id + '/' + p.number, p.body.length > 180 ? p.body.slice(0, 180) + '…' : p.body)))) : emptyState('暂无回答或评论', '参与友善讨论，让经验帮助更多同学。', link('/campus/records?type=question', '浏览问答'))])]), m('nav.Campus-pagination', {
      'aria-label': '个人记录分页'
    }, [d.page > 1 ? link('/campus/me?page=' + (d.page - 1), '上一页') : null, [d.published, d.bookmarks, d.discussions, d.answers].some(rows => rows.length === 20) ? link('/campus/me?page=' + (d.page + 1), '下一页') : null, m('span', '第 ' + d.page + ' 页')])];
  }
}
const bookmarkStates = new Map();
export function registerBookmarks() {
  extend(DiscussionPage.prototype, 'sidebarItems', function (items) {
    if (!app.session.user || !this.discussion || this.discussion.attribute('campusRecord')?.id) return;
    const id = this.discussion.id(),
      key = app.session.user.id() + ':' + id;
    if (!bookmarkStates.has(key)) {
      const entry = {
        loading: true
      };
      bookmarkStates.set(key, entry);
      api('discussions/' + id + '/bookmark').then(r => entry.bookmarked = r.data.bookmarked).catch(() => entry.error = true).finally(() => {
        entry.loading = false;
        m.redraw();
      });
    }
    const entry = bookmarkStates.get(key);
    items.add('campus-bookmark', m(Button, {
      className: 'Button',
      icon: entry.bookmarked ? 'fas fa-bookmark' : 'far fa-bookmark',
      loading: entry.loading,
      disabled: entry.loading,
      onclick: async () => {
        if (entry.loading) return;
        entry.loading = true;
        try {
          if (entry.error) {
            entry.bookmarked = (await api('discussions/' + id + '/bookmark')).data.bookmarked;
            entry.error = false;
          }
          entry.bookmarked = (await api('discussions/' + id + '/bookmark', 'POST', {
            enabled: !entry.bookmarked
          })).data.bookmarked;
          app.alerts.show({
            type: 'success'
          }, entry.bookmarked ? '已加入我的校园空间收藏' : '已取消收藏');
        } catch (e) {
          app.alerts.show({
            type: 'error'
          }, errorMessage(e, '收藏失败，请稍后重试。'));
        } finally {
          entry.loading = false;
          m.redraw();
        }
      }
    }, entry.bookmarked ? '取消收藏讨论' : '收藏讨论'), 5);
  });
}
