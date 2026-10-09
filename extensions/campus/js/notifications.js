import app from 'flarum/forum/app';
import Notification from 'flarum/forum/components/Notification';
import { extend } from 'flarum/common/extend';
class CampusNotification extends Notification {
  icon() {
    return 'fas fa-graduation-cap';
  }
  href() {
    return app.forum.attribute('baseUrl') + '/campus/record/' + this.attrs.notification.content()?.record_id;
  }
  content() {
    return {
      accepted: '你的回答已被提问者采纳',
      feedback: '你提交的反馈已有处理结果'
    }[this.attrs.notification.content()?.action] || '校园内容有新的处理结果';
  }
  excerpt() {
    return '';
  }
}
class AccountNotification extends Notification {
  icon() {
    return 'fas fa-shield-alt';
  }
  href() {
    return app.forum.attribute('baseUrl') + (this.attrs.notification.content()?.action === 'identity' ? '/campus/identity' : '/campus/cases?id=' + this.attrs.notification.content()?.target);
  }
  content() {
    return this.attrs.notification.content()?.action === 'identity' ? '你的校园身份申请已有处理结果' : '你的举报或相关处置已有处理结果';
  }
  excerpt() {
    return '';
  }
}
export default function register() {
  app.notificationComponents.campusUpdate = CampusNotification;
  app.notificationComponents.campusAccount = AccountNotification;
  extend('flarum/forum/components/NotificationGrid', 'notificationTypes', function (items) {
    items.add('campusUpdate', {
      name: 'campusUpdate',
      label: '校园问答采纳与反馈结果',
      icon: 'fas fa-graduation-cap'
    }, 10);
    items.add('campusAccount', {
      name: 'campusAccount',
      label: '身份与举报处理结果',
      icon: 'fas fa-shield-alt'
    }, 9);
  });
}
