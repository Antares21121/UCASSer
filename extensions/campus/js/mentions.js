import app from 'flarum/forum/app';
import Model from 'flarum/common/Model';
import User from 'flarum/common/models/User';
import UserCard from 'flarum/forum/components/UserCard';
import Avatar from 'flarum/common/components/Avatar';
import usernameHelper from 'flarum/common/helpers/username';
import highlight from 'flarum/common/helpers/highlight';
import { extend } from 'flarum/common/extend';

// Permanent numeric display suffix on the users resource.
User.prototype.campusSuffix = Model.attribute('campusSuffix');
User.prototype.campusLevel = Model.attribute('campusLevel');
User.prototype.campusXp = Model.attribute('campusXp');

export const displaySuffix = user => {
  const suffix = user?.campusSuffix?.();
  return suffix ? '#' + suffix : '';
};
export const displayLevel = user => {
  const level = user?.campusLevel?.();
  return level ? 'Lv.' + level : '';
};

export default function registerMentions() {
  // The mention autocomplete shows avatar, display name and the permanent
  // suffix, and inserts the native token carrying the stable user id, so the
  // picked account is unambiguous even when two users share a name.
  Promise.resolve(import('flarum/mentions/forum/mentionables/UserMention')).then(({ default: UserMention }) => {
    const clean = name => String(name || '').replace(/"/g, '');
    extend(UserMention.prototype, 'suggestion', function (user, typed) {
      const suffix = displaySuffix(user);
      return [m(Avatar, { user }), usernameHelper(user, name => highlight(name, typed)), suffix ? m('span.Campus-mentionSuffix', suffix) : null];
    });
    extend(UserMention.prototype, 'replacement', function (user) {
      const suffix = displaySuffix(user);
      const label = clean(user.displayName()) + (suffix ? ' ' + suffix : '');
      return this.format.format(label, '', user.id());
    });
    extend(UserMention.prototype, 'matches', function (model, typed) {
      if (!typed) return false;
      const suffix = displaySuffix(model);
      const names = [model.username(), model.displayName()];
      if (suffix) names.push(suffix);
      return names.some(name => String(name).toLowerCase().substr(0, typed.length) === typed);
    });
  }).catch(() => {});

  // User cards (profile page and hover cards): show the permanent suffix and
  // the experience baseline so equal display names stay distinguishable.
  extend(UserCard.prototype, 'profileItems', function (items) {
    const user = this.attrs.user;
    if (!user) return;
    const suffix = displaySuffix(user);
    const level = displayLevel(user);
    if (!suffix && !level) return;
    items.add('campus-profile-tags', m('div.Campus-profileTags', [
      suffix ? m('span.Campus-profileTag', [m('small', '账号编号'), m('strong', suffix)]) : null,
      level ? m('span.Campus-profileTag', [m('small', '经验等级'), m('strong', level)]) : null
    ]), 5);
  });
}
