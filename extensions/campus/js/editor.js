import app from 'flarum/forum/app';
import TextEditor from 'flarum/common/components/TextEditor';
import { extend, override } from 'flarum/common/extend';
import { api, errorMessage, symbol } from './ui';

const isMessageEditor = editor => typeof editor.attrs.composer?.body?.componentClass?.prototype?.recipientUnavailable === 'function';
// The structured campus composer keeps its own fields and preview; the
// reduced toolbar applies to plain public discussion and reply editors.
const isStructuredComposer = editor => typeof editor.attrs.composer?.body?.componentClass?.prototype?.loadCourses === 'function';
const isPublicEditor = editor => !isMessageEditor(editor) && !isStructuredComposer(editor);

const imageButton = function () {
  return m('label.Campus-postImageButton', {
    title: '上传本地图片（PNG、JPEG、WebP，不超过 5 MB）',
    'aria-label': '上传本地图片',
    'aria-busy': Boolean(this.campusPostUploading)
  }, [symbol('image'), m('span', this.campusPostUploading ? '上传中…' : '图片'), m('input', {
    type: 'file',
    accept: 'image/png,image/jpeg,image/webp',
    disabled: this.campusPostUploading || this.attrs.disabled,
    onchange: async event => {
      const file = event.target.files?.[0];
      event.target.value = '';
      if (!file) return;
      if (!['image/png', 'image/jpeg', 'image/webp'].includes(file.type) || file.size > 5 * 1024 * 1024) {
        app.alerts.show({
          type: 'error'
        }, '请选择不超过 5 MB 的 PNG、JPEG 或 WebP 图片。');
        return;
      }
      this.campusPostUploading = true;
      m.redraw();
      try {
        const data = await new Promise((resolve, reject) => {
          const reader = new FileReader();
          reader.onload = () => resolve(reader.result);
          reader.onerror = () => reject(new Error('读取图片失败'));
          reader.readAsDataURL(file);
        });
        const response = await api('post-images', 'POST', {
          data
        });
        const url = new URL(response.data.url, app.forum.attribute('baseUrl')).href;
        const alt = (file.name || '图片').replace(/\.[a-z0-9]+$/i, '').slice(0, 80) || '图片';
        const editor = this.attrs.composer.editor;
        editor?.insertAtCursor(`![${alt}](${url})\n`);
        editor?.focus();
      } catch (error) {
        app.alerts.show({
          type: 'error'
        }, errorMessage(error, '图片上传失败，请重试。'));
      } finally {
        this.campusPostUploading = false;
        m.redraw();
      }
    }
  })]);
};

export default function registerPublicEditor() {
  // Wrap the real method so the reduction runs after every extension --
  // markdown, emoji, mentions -- has added its buttons, regardless of
  // extension load order.
  override(TextEditor.prototype, 'toolbarItems', function (original) {
    const items = original();
    if (!isPublicEditor(this)) return items;
    // The public reply bar keeps exactly two tools: local image upload and
    // @ mention. Hand-written Markdown still renders normally; only the
    // shortcut buttons are removed.
    for (const item of items.toArray()) {
      if (item.itemName !== 'mention') items.remove(item.itemName);
    }
    if (!items.has('campus-post-image')) items.add('campus-post-image', imageButton.call(this), 1000);
    return items;
  });
  override(TextEditor.prototype, 'controlItems', function (original) {
    const items = original();
    if (!isPublicEditor(this)) return items;
    // The reduced reply bar keeps the editor and the publish button; the
    // preview shortcut is removed together with the formatting shortcuts.
    items.remove('preview');
    return items;
  });
  // DOM-level enforcement: runs after every redraw, so it cannot be undone
  // by another extension's override ordering or a later item registration.
  extend(TextEditor.prototype, 'onupdate', function () {
    if (!isPublicEditor(this)) return;
    const toolbar = this.element?.querySelector('.TextEditor-toolbar');
    if (toolbar) {
      for (const child of Array.from(toolbar.children)) {
        const isMention = child.classList.contains('Button') && Boolean(child.querySelector('.fa-at, .fa-solid.fa-at, [class*="fa-at"]'));
        if (!child.classList.contains('Campus-postImageButton') && !isMention) child.remove();
      }
    }
    const controls = this.element?.querySelector('.TextEditor-controls');
    if (controls) {
      for (const li of Array.from(controls.children)) {
        if (!li.classList.contains('item-submit') && !li.classList.contains('TextEditor-toolbar')) li.remove();
      }
    }
  });
}
