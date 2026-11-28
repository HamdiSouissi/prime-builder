// The visual editor is deliberately limited to the CMS basic HTML profile.
// The server remains authoritative: this only prevents active content in the editor.
const allowedTags = new Set(['strong', 'b', 'em', 'i', 'a', 'code', 'br', 'p', 'h2', 'h3', 'h4', 'ul', 'ol', 'li', 'blockquote', 'pre', 'hr', 'table', 'thead', 'tbody', 'tr', 'th', 'td']);
const dropTags = new Set(['script', 'style', 'svg', 'math', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'form', 'input', 'button', 'select', 'option', 'optgroup', 'textarea', 'noscript', 'template', 'title', 'head', 'meta', 'link', 'base', 'audio', 'video', 'source', 'track', 'canvas', 'map', 'area', 'xml', 'xmp', 'plaintext', 'marquee', 'bgsound', 'portal', 'dialog', 'annotation-xml', 'foreignobject', 'desc', 'animate', 'set']);
const voidTags = new Set(['br', 'hr']);

// Conservatively inspect links before putting them in a live contenteditable.
// The CMS performs its own, authoritative URL validation again on the server.
export function safeRichHref(value, doc = document) {
  if (typeof value !== 'string') return null;
  const raw = value.replace(/[\x00-\x1f\x7f]/g, '').trim();
  if (!raw) return null;
  const decoder = doc.createElement('textarea');
  let decoded = raw;
  for (let i = 0; i < 3; i++) {
    // RCDATA decodes entities without building elements; '<' is escaped first.
    decoder.innerHTML = decoded.replaceAll('<', '&lt;');
    const next = decoder.value;
    if (next === decoded) break;
    decoded = next;
  }
  const inspected = decoded.replace(/[\x00-\x1f\x7f]/g, '').trim();
  if (!inspected || inspected.startsWith('//') || inspected.startsWith('\\') || inspected.startsWith('/\\')) return null;
  const compact = inspected.replace(/\s+/g, '');
  const colon = compact.indexOf(':');
  const delimiters = ['/', '?', '#'].map(char => { const index = compact.indexOf(char); return index < 0 ? compact.length : index; });
  if (colon >= 0 && colon < Math.min(...delimiters)) {
    const scheme = compact.slice(0, colon).toLowerCase();
    if (!['http', 'https', 'mailto', 'tel'].includes(scheme)) return null;
    if (scheme === 'http' || scheme === 'https') {
      try {
        const url = new URL(compact);
        if (url.protocol !== `${scheme}:` || !url.hostname) return null;
      } catch { return null; }
    } else if (!compact.slice(colon + 1) || /[\r\n]/.test(value)) return null;
  } else if (/^(?:javascript|vbscript|data|file|blob)\b/i.test(compact)) return null;
  return raw;
}

// Build safe DOM nodes from an inert template or the editable's own children.
// No untrusted HTML is ever attached to the live document as-is.
function safeFragment(nodes, doc, normalizeDivs = false) {
  const fragment = doc.createDocumentFragment();
  const append = (node, parent) => {
    if (node.nodeType === 3) { parent.append(doc.createTextNode(node.nodeValue || '')); return; }
    if (node.nodeType !== 1) return;
    let tag = node.localName.toLowerCase();
    if (dropTags.has(tag)) return;
    // Browsers may generate divs for Enter; preserve the paragraph boundary.
    if (normalizeDivs && tag === 'div') tag = 'p';
    if (!allowedTags.has(tag)) { for (const child of node.childNodes) append(child, parent); return; }
    let href = null;
    if (tag === 'a') {
      href = safeRichHref(node.getAttribute('href'), doc);
      if (!href) { for (const child of node.childNodes) append(child, parent); return; }
    }
    const clean = doc.createElement(tag);
    if (href !== null) {
      clean.setAttribute('href', href);
      if (node.getAttribute('target')?.trim().toLowerCase() === '_blank') {
        clean.setAttribute('target', '_blank'); clean.setAttribute('rel', 'noopener noreferrer');
      }
    }
    if (!voidTags.has(tag)) for (const child of node.childNodes) append(child, clean);
    parent.append(clean);
  };
  for (const node of nodes) append(node, fragment);
  return fragment;
}

function parseSafeHtml(value, doc) {
  const template = doc.createElement('template');
  template.innerHTML = value;
  return safeFragment(template.content.childNodes, doc);
}

function serializeSafeHtml(editable) {
  const container = editable.ownerDocument.createElement('div');
  container.append(safeFragment(editable.childNodes, editable.ownerDocument, true));
  return container.innerHTML;
}

export function makeRichField({ name, label, schema, current, onChange, ui, id }) {
  const doc = document;
  const wrapper = doc.createElement('div'); wrapper.className = 'pb-field pb-rich-field';
  const caption = doc.createElement('label'); caption.className = 'pb-rich-label'; caption.textContent = label; caption.id = id();
  const source = doc.createElement('textarea'); source.id = id(); source.value = current ?? '';
  if (schema.max != null) source.maxLength = schema.max;
  caption.htmlFor = source.id;

  const toggle = doc.createElement('button'); toggle.type = 'button'; toggle.className = 'pb-rich-toggle';
  toggle.textContent = ui.rich_visual; toggle.setAttribute('aria-expanded', 'false');
  const visual = doc.createElement('div'); visual.className = 'pb-rich-editable'; visual.id = id(); visual.hidden = true;
  visual.contentEditable = 'true'; visual.setAttribute('role', 'textbox'); visual.setAttribute('aria-multiline', 'true');
  visual.dataset.pbRichEditable = name; toggle.setAttribute('aria-controls', visual.id);
  const tools = doc.createElement('div'); tools.className = 'pb-rich-tools'; tools.hidden = true;
  tools.setAttribute('role', 'group'); tools.setAttribute('aria-label', ui.rich_toolbar);
  const help = doc.createElement('p'); help.className = 'pb-rich-help'; help.textContent = ui.rich_help; help.id = id();
  const error = doc.createElement('p'); error.className = 'pb-rich-error'; error.hidden = true; error.setAttribute('role', 'alert'); error.id = id();
  const notice = doc.createElement('p'); notice.className = 'pb-rich-help'; notice.hidden = true; notice.setAttribute('role', 'status');
  source.setAttribute('aria-describedby', `${help.id} ${error.id}`);
  visual.setAttribute('aria-describedby', `${help.id} ${error.id}`);
  let savedRange = null;
  let commandInProgress = false;
  const stateButtons = [];

  const checkLength = value => {
    const tooLong = Number.isInteger(schema.max) && new TextEncoder().encode(value).length > schema.max;
    error.textContent = tooLong ? ui.rich_limit.replace('{max}', String(schema.max)) : '';
    error.hidden = !tooLong;
    source.setAttribute('aria-invalid', String(tooLong));
    visual.setAttribute('aria-invalid', String(tooLong));
  };
  source.addEventListener('input', () => {
    checkLength(source.value);
    onChange(source.value === '' ? undefined : source.value);
  });

  const remember = () => {
    const selection = doc.getSelection();
    if (selection?.rangeCount && visual.contains(selection.anchorNode) && visual.contains(selection.focusNode)) {
      savedRange = selection.getRangeAt(0).cloneRange();
      for (const [button, command] of stateButtons) button.setAttribute('aria-pressed', String(doc.queryCommandState(command)));
    }
  };
  const restore = () => {
    const selection = doc.getSelection();
    if (!selection || !visual.isConnected) return false;
    let range = savedRange;
    visual.focus({ preventScroll: true });
    if (!range || !visual.contains(range.startContainer) || !visual.contains(range.endContainer)) {
      range = doc.createRange(); range.selectNodeContents(visual); range.collapse(false);
    }
    selection.removeAllRanges(); selection.addRange(range);
    return true;
  };
  const sync = () => {
    notice.hidden = true;
    const html = serializeSafeHtml(visual);
    source.value = html;
    checkLength(html);
    onChange(html === '' ? undefined : html);
    remember();
  };
  const execute = (command, value = null, after = null) => {
    commandInProgress = true;
    try {
      doc.execCommand(command, false, value);
      if (after) after();
    } finally { commandInProgress = false; }
    sync();
  };
  const action = (labelText, command, value = null) => {
    const button = doc.createElement('button'); button.type = 'button'; button.textContent = labelText;
    button.setAttribute('aria-pressed', 'false'); stateButtons.push([button, command]);
    button.addEventListener('mousedown', event => event.preventDefault());
    button.addEventListener('click', () => {
      if (!restore()) return;
      execute(command, value);
    });
    return button;
  };
  tools.append(
    action(ui.rich_bold, 'bold'), action(ui.rich_italic, 'italic'),
    action(ui.rich_unordered, 'insertUnorderedList'), action(ui.rich_ordered, 'insertOrderedList'),
  );
  const block = doc.createElement('select'); block.setAttribute('aria-label', ui.rich_block);
  for (const [value, title] of [['', ui.rich_block], ['p', ui.rich_paragraph], ['h2', ui.rich_h2], ['h3', ui.rich_h3], ['h4', ui.rich_h4], ['blockquote', ui.rich_quote], ['pre', ui.rich_pre]]) {
    const option = doc.createElement('option'); option.value = value; option.textContent = title; block.append(option);
  }
  block.addEventListener('change', () => {
    const value = block.value; block.value = '';
    if (!value || !restore()) return;
    execute('formatBlock', `<${value}>`);
  });
  tools.append(block);

  const link = doc.createElement('button'); link.type = 'button'; link.textContent = ui.rich_link; link.setAttribute('aria-expanded', 'false');
  link.addEventListener('mousedown', event => event.preventDefault());
  const linkForm = doc.createElement('div'); linkForm.className = 'pb-rich-link'; linkForm.id = id(); linkForm.hidden = true;
  link.setAttribute('aria-controls', linkForm.id);
  const linkInput = doc.createElement('input'); linkInput.type = 'text'; linkInput.inputMode = 'url'; linkInput.setAttribute('aria-label', ui.rich_link_url);
  const linkError = doc.createElement('p'); linkError.className = 'pb-rich-error'; linkError.id = id(); linkError.setAttribute('role', 'alert'); linkError.hidden = true;
  linkInput.setAttribute('aria-describedby', linkError.id);
  const apply = doc.createElement('button'); apply.type = 'button'; apply.textContent = ui.rich_link_apply;
  const unlink = doc.createElement('button'); unlink.type = 'button'; unlink.textContent = ui.rich_link_remove;
  const cancel = doc.createElement('button'); cancel.type = 'button'; cancel.textContent = ui.cancel;
  const closeLink = () => { linkForm.hidden = true; link.setAttribute('aria-expanded', 'false'); linkError.hidden = true; linkInput.removeAttribute('aria-invalid'); };
  const ancestorLink = () => {
    const node = savedRange?.commonAncestorContainer;
    const element = node?.nodeType === 1 ? node : node?.parentElement;
    const anchor = element?.closest('a');
    return anchor && visual.contains(anchor) ? anchor : null;
  };
  link.addEventListener('click', () => {
    remember();
    const existing = ancestorLink(); linkInput.value = existing?.getAttribute('href') || '';
    linkError.hidden = true; linkForm.hidden = false; link.setAttribute('aria-expanded', 'true'); linkInput.focus();
  });
  const submitLink = () => {
    const href = safeRichHref(linkInput.value, doc);
    if (!href) { linkError.textContent = ui.rich_link_invalid; linkError.hidden = false; linkInput.setAttribute('aria-invalid', 'true'); linkInput.focus(); return; }
    const existing = ancestorLink();
    if (!existing && (!savedRange || savedRange.collapsed)) {
      linkError.textContent = ui.rich_link_select; linkError.hidden = false; linkInput.focus(); return;
    }
    const openInNewTab = existing?.getAttribute('target')?.trim().toLowerCase() === '_blank';
    if (!restore()) return;
    if (existing) {
      const range = doc.createRange(); range.selectNodeContents(existing);
      const selection = doc.getSelection(); selection.removeAllRanges(); selection.addRange(range);
    }
    execute('createLink', href, () => {
      // execCommand replaces an existing link; retain the only supported target.
      remember();
      const updated = ancestorLink();
      if (openInNewTab && updated) { updated.setAttribute('target', '_blank'); updated.setAttribute('rel', 'noopener noreferrer'); }
    });
    closeLink();
  };
  apply.addEventListener('click', submitLink);
  unlink.addEventListener('click', () => {
    const existing = ancestorLink();
    if (!restore()) return;
    if (existing) {
      const range = doc.createRange(); range.selectNodeContents(existing);
      const selection = doc.getSelection(); selection.removeAllRanges(); selection.addRange(range);
    }
    execute('unlink'); closeLink();
  });
  cancel.addEventListener('click', () => { closeLink(); if (!restore()) link.focus(); });
  linkInput.addEventListener('keydown', event => {
    if (event.key === 'Enter') { event.preventDefault(); submitLink(); }
    if (event.key === 'Escape') { event.preventDefault(); closeLink(); if (!restore()) link.focus(); }
  });
  linkForm.append(linkInput, apply, unlink, cancel, linkError); tools.append(link, linkForm);

  for (const event of ['keyup', 'pointerup', 'focus', 'blur']) visual.addEventListener(event, remember);
  visual.addEventListener('focus', () => {
    // The CMS basic profile discards inline styles; ask the browser for tags.
    doc.execCommand('styleWithCSS', false, false);
    doc.execCommand('defaultParagraphSeparator', false, 'p');
  });
  visual.addEventListener('input', () => { if (!commandInProgress) sync(); });
  // Clicking text while editing must never navigate away or open an existing link.
  visual.addEventListener('click', event => { if (event.target.closest('a')) event.preventDefault(); });
  visual.addEventListener('paste', event => {
    event.preventDefault();
    const plain = event.clipboardData?.getData('text/plain');
    if (typeof plain !== 'string' || plain === '') return;
    remember();
    if (restore()) execute('insertText', plain);
  });
  visual.addEventListener('drop', event => event.preventDefault());
  visual.addEventListener('dragover', event => event.preventDefault());
  visual.addEventListener('beforeinput', event => {
    if (event.inputType?.startsWith('insertFromPaste') || event.inputType?.startsWith('insertFromDrop')) event.preventDefault();
    // Underlining, strike-through and other unsupported browser shortcuts would
    // otherwise appear in the editable but disappear through the CMS filter.
    if (event.inputType?.startsWith('format') && !['formatBold', 'formatItalic'].includes(event.inputType)) {
      event.preventDefault(); notice.textContent = ui.rich_unsupported; notice.hidden = false;
    }
  });
  toggle.addEventListener('click', () => {
    notice.hidden = true;
    if (visual.hidden) {
      visual.replaceChildren(parseSafeHtml(source.value, doc)); savedRange = null;
      caption.removeAttribute('for'); source.hidden = true; visual.setAttribute('aria-labelledby', caption.id);
      tools.hidden = false; visual.hidden = false; toggle.textContent = ui.rich_source; toggle.setAttribute('aria-expanded', 'true');
    } else {
      closeLink(); visual.removeAttribute('aria-labelledby'); visual.hidden = true; tools.hidden = true;
      caption.htmlFor = source.id; source.hidden = false; toggle.textContent = ui.rich_visual; toggle.setAttribute('aria-expanded', 'false');
    }
  });
  // The adjacent dynamic-tag picker can insert at the retained caret in either
  // mode without switching modes or interpreting the tag as HTML.
  wrapper.insertDynamicTag = tag => {
    if (!visual.hidden) {
      if (restore()) execute('insertText', tag);
    } else {
      source.setRangeText(tag, source.selectionStart, source.selectionEnd, 'end');
      source.dispatchEvent(new Event('input', { bubbles: true }));
      source.focus();
    }
  };
  checkLength(source.value);
  wrapper.append(caption, source, toggle, tools, visual, help, notice, error);
  return wrapper;
}
