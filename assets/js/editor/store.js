import { createHistory } from './history.js';

export function createStore(initialDoc) {
  const doc = initialDoc && Array.isArray(initialDoc.elements)
    ? initialDoc
    : { version: 1, elements: [] };

  const history = createHistory(doc);
  let selectedId = null;
  let device = 'desktop';
  let dirty = false;
  const listeners = new Set();

  function notify() {
    for (const fn of listeners) fn(getState());
  }

  function getState() {
    return {
      document: history.current(),
      selectedId,
      device,
      dirty,
      canUndo: history.canUndo(),
      canRedo: history.canRedo(),
    };
  }

  function uid() {
    return 'el_' + Math.random().toString(36).slice(2, 10);
  }

  function addContainer(layoutType = 'flex') {
    const next = history.current();
    const el = {
      id: uid(),
      elType: 'container',
      settings: { layout_type: layoutType, content_width: 'boxed' },
      elements: [],
    };
    next.elements.push(el);
    history.push(next);
    selectedId = el.id;
    dirty = true;
    notify();
    return el.id;
  }

  function addWidget(widgetType, defaults = {}, containerId = null) {
    const next = history.current();
    const widget = {
      id: uid(),
      elType: 'widget',
      widgetType,
      settings: { ...defaults },
    };

    if (containerId) {
      const target = findById(next.elements, containerId);
      if (target && target.elType === 'container') {
        target.elements = target.elements || [];
        target.elements.push(widget);
      } else {
        next.elements.push(widget);
      }
    } else {
      next.elements.push(widget);
    }

    history.push(next);
    selectedId = widget.id;
    dirty = true;
    notify();
    return widget.id;
  }

  function updateSetting(id, key, value) {
    const next = history.current();
    const target = findById(next.elements, id);
    if (!target) return;
    target.settings = target.settings || {};
    target.settings[key] = value;
    history.push(next);
    dirty = true;
    notify();
  }

  function removeElement(id) {
    const next = history.current();
    if (removeById(next.elements, id)) {
      if (selectedId === id) selectedId = null;
      history.push(next);
      dirty = true;
      notify();
    }
  }

  function moveElement(id, direction) {
    const next = history.current();
    if (moveInList(next.elements, id, direction)) {
      history.push(next);
      dirty = true;
      notify();
    }
  }

  function undo() {
    if (history.undo()) {
      dirty = true;
      notify();
    }
  }

  function redo() {
    if (history.redo()) {
      dirty = true;
      notify();
    }
  }

  function setDevice(nextDevice) {
    device = nextDevice;
    notify();
  }

  function select(id) {
    selectedId = id;
    notify();
  }

  function markSaved() {
    dirty = false;
    notify();
  }

  function subscribe(fn) {
    listeners.add(fn);
    return () => listeners.delete(fn);
  }

  return {
    getState,
    addContainer,
    addWidget,
    updateSetting,
    removeElement,
    moveElement,
    undo,
    redo,
    setDevice,
    select,
    markSaved,
    subscribe,
  };
}

function findById(list, id) {
  for (const item of list) {
    if (item.id === id) return item;
    if (Array.isArray(item.elements)) {
      const found = findById(item.elements, id);
      if (found) return found;
    }
  }
  return null;
}

function removeById(list, id) {
  const idx = list.findIndex((i) => i.id === id);
  if (idx !== -1) {
    list.splice(idx, 1);
    return true;
  }
  for (const item of list) {
    if (Array.isArray(item.elements) && removeById(item.elements, id)) {
      return true;
    }
  }
  return false;
}

function moveInList(list, id, direction) {
  const idx = list.findIndex((i) => i.id === id);
  if (idx !== -1) {
    const targetIdx = direction === 'up' ? idx - 1 : idx + 1;
    if (targetIdx < 0 || targetIdx >= list.length) return false;
    const [el] = list.splice(idx, 1);
    list.splice(targetIdx, 0, el);
    return true;
  }
  for (const item of list) {
    if (Array.isArray(item.elements) && moveInList(item.elements, id, direction)) {
      return true;
    }
  }
  return false;
}
