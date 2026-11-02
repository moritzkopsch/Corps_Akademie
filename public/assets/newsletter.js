document.querySelectorAll('form[data-newsletter-editor]').forEach((form) => {
  const text = form.querySelector('[data-newsletter-text]');
  const toolbar = form.querySelector('[data-newsletter-toolbar]');
  if (!text || !toolbar) return;
  const surround = (before, after) => {
    const start = text.selectionStart;
    const end = text.selectionEnd;
    const value = text.value;
    text.value = value.slice(0, start) + before + value.slice(start, end) + after + value.slice(end);
    text.focus();
    text.setSelectionRange(start + before.length, end + before.length);
  };
  const prefixLines = (prefix) => {
    const start = text.selectionStart;
    const end = text.selectionEnd;
    const value = text.value;
    const from = value.lastIndexOf('\n', start - 1) + 1;
    const lineBreak = value.indexOf('\n', end);
    const to = lineBreak === -1 ? value.length : lineBreak;
    const block = value.slice(from, to).split('\n').map((line) => (line.startsWith(prefix) ? line : prefix + line)).join('\n');
    text.value = value.slice(0, from) + block + value.slice(to);
    text.focus();
    text.setSelectionRange(from, from + block.length);
  };
  toolbar.addEventListener('click', (event) => {
    const button = event.target.closest('button[data-mark]');
    if (!button) return;
    event.preventDefault();
    const mark = button.dataset.mark;
    if (mark === 'bold') surround('**', '**');
    else if (mark === 'italic') surround('*', '*');
    else if (mark === 'list') prefixLines('- ');
    else if (mark === 'link') surround('[', '](https://)');
  });
});
