import Button from 'flarum/common/components/Button';
export default class CampusButton extends Button {
  static initAttrs(attrs) {
    super.initAttrs(attrs);
    attrs.className = attrs.className || 'Button';
  }
}
