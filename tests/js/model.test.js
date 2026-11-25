import test from 'node:test';
import assert from 'node:assert/strict';
import { History, add, changedElement, containsWidget, createElement, duplicate, find, inheritedStyle, insertAfter, insertRelative, moveInto, moveRelative, remove, reorder, responsiveVisibility, setResponsiveStyle, spacingStyleSource, update, withElementDefaults } from '../../assets/js/model.js';

test('custom widget classes normalize bounded safe tokens without changing copies', async () => {
  const { normalizeWidgetClasses } = await import('../../assets/js/model.js');
  assert.equal(typeof normalizeWidgetClasses, 'function');
  assert.equal(normalizeWidgetClasses('  theme-hero\twide theme-hero -accent _utility  '), 'theme-hero wide -accent _utility');
  assert.equal(normalizeWidgetClasses(' \t\n'), '');
  assert.equal(normalizeWidgetClasses(Array.from({ length: 20 }, (_, i) => `class-${i}`).join(' ')), Array.from({ length: 20 }, (_, i) => `class-${i}`).join(' '));
  assert.equal(normalizeWidgetClasses('--custom A a _X ' + 'z'.repeat(64)), '--custom A a _X ' + 'z'.repeat(64));
  for (const bad of [null, false, [], 'pb-element', 'PB_layout', 'pc-media-picker', 'pc', 'evil" onclick="alert(1)', 'foo.bar', '9start', '-9start', 'name:evil', 'accentué', 'name\u2028', 'name\u2029', 'name\v', 'safe\0unsafe', 'a'.repeat(65), 'a'.repeat(513), Array.from({ length: 21 }, (_, i) => `class-${i}`).join(' ')]) {
    assert.equal(normalizeWidgetClasses(bad), null, JSON.stringify(bad));
  }
  const first = { ...createElement('heading'), classes: 'theme-hero wide' };
  const tree = [first];
  const copy = duplicate(tree, first.id);
  assert.equal(copy.length, 2);
  assert.equal(copy[1].classes, 'theme-hero wide');
  assert.notEqual(copy[1].id, first.id);
  assert.equal(changedElement(tree, update(tree, first.id, { classes: 'other' })), first.id);
  assert.equal(tree[0].classes, 'theme-hero wide');
  const { firstInvalidWidgetClasses } = await import('../../assets/js/model.js');
  const nested = [{ id: 'parent', type: 'container', children: [{ id: 'bad', type: 'heading', classes: 'pb-element', children: [] }] }];
  assert.equal(firstInvalidWidgetClasses(nested)?.id, 'bad');
  assert.equal(firstInvalidWidgetClasses([{ id: 'original', type: 'original_group', classes: 'custom' }])?.id, 'original');
  assert.equal(firstInvalidWidgetClasses([{ id: 'original', type: 'original_group', classes: '' }]), null);
  assert.equal(firstInvalidWidgetClasses([{ id: 'a', classes: null }])?.id, 'a');
  assert.equal(firstInvalidWidgetClasses([{ id: 'a' }, first]), null);
});

test('single-image sources discard stale dimensions and copy only complete valid media sizes', async () => {
  const { replaceImageSource } = await import('../../assets/js/model.js');
  assert.equal(typeof replaceImageSource, 'function');
  const original = { url: '/wide.png', alt: 'Large', width: 320, height: 200, title: 'Conservé' };
  const snapshot = structuredClone(original);
  assert.deepEqual(replaceImageSource(original, 'url', '/tall.png'), { url: '/tall.png', alt: 'Large', title: 'Conservé' });
  assert.deepEqual(original, snapshot);
  assert.deepEqual(replaceImageSource(original, 'url', '/wide.png'), snapshot);
  assert.deepEqual(replaceImageSource(original, 'url', undefined), { alt: 'Large', title: 'Conservé' });
  const selected = { url: '/tall.png', alt: 'Portrait', width: 150, height: 300 };
  assert.deepEqual(replaceImageSource(original, 'url', selected.url, selected), { url: '/tall.png', alt: 'Portrait', width: 150, height: 300, title: 'Conservé' });
  assert.deepEqual(replaceImageSource({ image_url: '/a', alt: 'Ancien', width: 10, height: 20 }, 'image_url', '/b', { url: '/b', alt: null, width: null, height: null }), { image_url: '/b', alt: '' });
  assert.deepEqual(replaceImageSource(original, 'url', '/new.png', null), { url: '/new.png', alt: '', title: 'Conservé' });
  for (const [width, height] of [[0, 20], [10, 10001], [1.5, 20], ['10', 20], [10, null], [Infinity, 20]]) {
    const invalid = replaceImageSource(original, 'url', '/new.png', { url: '/new.png', alt: 'Nouvelle', width, height });
    assert.deepEqual(invalid, { url: '/new.png', alt: 'Nouvelle', title: 'Conservé' });
  }
  assert.deepEqual(replaceImageSource(original, 'url', '/new.png', { alt: '', width: 1, height: 10000 }), { url: '/new.png', alt: '', width: 1, height: 10000, title: 'Conservé' });
});

test('media-library selections append atomically to image lists without losing existing images', async () => {
  const { appendMediaList } = await import('../../assets/js/model.js');
  assert.equal(typeof appendMediaList, 'function');
  const images = [
    { kind: 'image', url: '/uploads/a.png', alt: 'Arbre\nvert | matin' },
    { kind: 'image', url: '/uploads/b.png', alt: null },
  ];
  const snapshot = structuredClone(images);
  assert.equal(appendMediaList('/uploads/old.png|Ancien|texte\n', images), '/uploads/old.png|Ancien|texte\n/uploads/a.png|Arbre vert | matin\n/uploads/b.png|');
  assert.equal(appendMediaList('', images), '/uploads/a.png|Arbre vert | matin\n/uploads/b.png|');
  assert.equal(appendMediaList('/old-a|A\r\n/old-b|B\r\n', [images[0]]), '/old-a|A\n/old-b|B\n/uploads/a.png|Arbre vert | matin');
  assert.equal(appendMediaList('/old-a|A\n\n/old-b|B', [images[0]]), null);
  assert.deepEqual(images, snapshot);
  const nineteen = Array.from({ length: 19 }, (_, i) => `/uploads/${i}.png|`).join('\n');
  assert.notEqual(appendMediaList(nineteen, [images[0]]), null);
  assert.equal(appendMediaList(nineteen, images), null);
  assert.equal(appendMediaList('', []), null);
  assert.equal(appendMediaList('', [{ kind: 'video', url: '/uploads/a.mp4', alt: '' }]), null);
  assert.equal(appendMediaList('', [{ kind: 'image', url: '/uploads/a|wrong.png', alt: '' }]), null);
  assert.equal(appendMediaList('', [{ kind: 'image', url: '/uploads/a.png\n/uploads/b.png', alt: '' }]), null);
  assert.notEqual(appendMediaList('', [{ kind: 'image', url: '/a', alt: 'é'.repeat(150) }]), null);
  assert.equal(appendMediaList('', [{ kind: 'image', url: '/a', alt: 'é'.repeat(151) }]), null);
  assert.equal(appendMediaList('/' + 'a'.repeat(9990) + '|', [images[0]]), null);
});

test('media-library selections retain bounded intrinsic dimensions without changing legacy rows', async () => {
  const { appendMediaSelection } = await import('../../assets/js/model.js');
  assert.equal(typeof appendMediaSelection, 'function');
  const existing = '/old.png|Alternative | conservée';
  const metadata = JSON.stringify([
    { url: '/old.png', width: 640, height: 480 },
    { url: '/removed.png', width: 11, height: 12 },
  ]);
  const picked = [
    { kind: 'image', url: '/new.png', alt: 'Nouveau', width: 320, height: 200 },
    { kind: 'image', url: '/unknown.png', alt: 'Sans dimensions', width: null, height: null },
  ];
  const original = structuredClone(picked);
  const result = appendMediaSelection(existing, metadata, picked);
  assert.deepEqual(picked, original);
  assert.equal(result.images, `${existing}\n/new.png|Nouveau\n/unknown.png|Sans dimensions`);
  assert.deepEqual(JSON.parse(result.dimensions), [
    { url: '/old.png', width: 640, height: 480 },
    { url: '/new.png', width: 320, height: 200 },
  ]);
  assert.deepEqual(appendMediaSelection('', undefined, [picked[1]]), { images: '/unknown.png|Sans dimensions', dimensions: '' });
  assert.equal(appendMediaSelection(existing, '{bad', picked), null);
  assert.equal(appendMediaSelection(existing, JSON.stringify([{ url: '/old.png', width: '640', height: 480 }]), picked), null);
  assert.equal(appendMediaSelection(existing, JSON.stringify([{ url: '/old.png', width: 1, height: 1, extra: 'bad' }]), picked), null);
  assert.equal(appendMediaSelection(existing, ' '.repeat(25001), picked), null);
  assert.equal(appendMediaSelection(existing, '[]', Array.from({ length: 21 }, () => picked[0])), null);
  const replaced = appendMediaSelection(existing, metadata, [{ kind: 'image', url: '/old.png', alt: 'Même URL', width: null, height: null }]);
  assert.equal(replaced.dimensions, '', 'an unknown replacement must not keep stale dimensions');
  const bounded = appendMediaSelection('', '', [
    { kind: 'image', url: '/min-max.png', width: 1, height: 10000 },
    { kind: 'image', url: '/too-wide.png', width: 10001, height: 1 },
    { kind: 'image', url: '/fraction.png', width: 5.5, height: 2 },
  ]);
  assert.deepEqual(JSON.parse(bounded.dimensions), [{ url: '/min-max.png', width: 1, height: 10000 }]);
  assert.equal(bounded.images, '/min-max.png|\n/too-wide.png|\n/fraction.png|');
});

test('media carousel appends selected images atomically and keys dimensions by image URL', async () => {
  const { appendMediaCarouselSelection } = await import('../../assets/js/model.js');
  assert.equal(typeof appendMediaCarouselSelection, 'function');
  const previous = 'video | /clip.webm | Démo | /captions.vtt | Texte\r\nimage | /old.png | Ancienne\r\n';
  const old = [{ url: '/old.png', width: 640, height: 480 }, { url: '/clip.webm', width: 1, height: 1 }];
  const selection = [
    { kind: 'image', url: '/wide.png', alt: 'Paysage\nclair', width: 320, height: 200 },
    { kind: 'image', url: '/unknown.png', alt: null, width: 800, height: null },
  ];
  const copy = structuredClone(selection);
  const result = appendMediaCarouselSelection(previous, JSON.stringify(old), selection);
  assert.equal(result.items, 'video | /clip.webm | Démo | /captions.vtt | Texte\nimage | /old.png | Ancienne\nimage|/wide.png|Paysage clair\nimage|/unknown.png|');
  assert.deepEqual(JSON.parse(result.dimensions), [old[0], { url: '/wide.png', width: 320, height: 200 }]);
  assert.deepEqual(selection, copy);
  assert.deepEqual(appendMediaCarouselSelection('', undefined, [{ kind: 'image', url: '/a', width: 1, height: 10000 }]), { items: 'image|/a|', dimensions: '[{"url":"/a","width":1,"height":10000}]' });
  assert.deepEqual(appendMediaCarouselSelection('', '', [{ kind: 'image', url: '/a', width: 1.5, height: 2 }]), { items: 'image|/a|', dimensions: '' });
  assert.equal(appendMediaCarouselSelection('/old\n\n/old2', '', [selection[0]]), null);
  assert.equal(appendMediaCarouselSelection('', '', []), null);
  assert.equal(appendMediaCarouselSelection('', '{bad', [selection[0]]), null);
  assert.equal(appendMediaCarouselSelection('', '', [{ kind: 'video', url: '/clip.webm' }]), null);
  assert.equal(appendMediaCarouselSelection('', '', [{ kind: 'image', url: '/bad|url' }]), null);
  assert.equal(appendMediaCarouselSelection('', '', [{ kind: 'image', url: '/a', alt: 'texte | ambigu' }]), null);
  assert.notEqual(appendMediaCarouselSelection('', '', [{ kind: 'image', url: '/a', alt: 'é'.repeat(150) }]), null);
  assert.equal(appendMediaCarouselSelection('', '', [{ kind: 'image', url: '/a', alt: 'é'.repeat(151) }]), null);
  assert.equal(appendMediaCarouselSelection('', '', [{ kind: 'image', url: '/' + 'a'.repeat(2048) }]), null);
  const nineteen = Array.from({ length: 19 }, (_, index) => `image|/${index}|`).join('\n');
  assert.notEqual(appendMediaCarouselSelection(nineteen, '', [selection[0]]), null);
  assert.equal(appendMediaCarouselSelection(nineteen, '', selection), null);
  assert.equal(appendMediaCarouselSelection('/' + 'a'.repeat(14990), '', [selection[0]]), null);
});

test('a structured list replaces only the chosen row image and binds known dimensions to its URL', async () => {
  const { replaceStructuredImage } = await import('../../assets/js/model.js');
  assert.equal(typeof replaceStructuredImage, 'function');
  const picked = { kind: 'image', url: '/wide.png', alt: 'Paysage\nvert', width: 320, height: 200 };
  const snapshot = structuredClone(picked);
  const cases = [
    ['slideshow', '/old.png | Ancien | Première | Description | Voir | /suite\n/other.png | Autre | Deuxième', '/old.png | Ancien | Première | Description | Voir | /suite\n/wide.png | Paysage vert | Deuxième'],
    ['portfolio', '/old.png | Ancien | Projet A | Art | Description | /suite\n/other.png | Autre | Projet B | Sites', '/old.png | Ancien | Projet A | Art | Description | /suite\n/wide.png | Paysage vert | Projet B | Sites'],
    ['testimonial_carousel', 'Excellent | Camille | Designer | /old.png\nTrès bien | Alex | Artiste', 'Excellent | Camille | Designer | /old.png\nTrès bien | Alex | Artiste | /wide.png'],
  ];
  const dimensions = JSON.stringify([{ url: '/old.png', width: 30, height: 40 }, { url: '/other.png', width: 60, height: 90 }, { url: '/removed.png', width: 1, height: 2 }]);
  for (const [type, items, expected] of cases) {
    const result = replaceStructuredImage(type, items, dimensions, 1, picked);
    assert.equal(result.items, expected, type);
    assert.deepEqual(JSON.parse(result.dimensions), [{ url: '/old.png', width: 30, height: 40 }, { url: '/wide.png', width: 320, height: 200 }], type);
  }
  assert.deepEqual(picked, snapshot);
  const duplicate = replaceStructuredImage('slideshow', '/same.png|A\n/same.png|B', '[{"url":"/same.png","width":1,"height":2}]', 1, picked);
  assert.deepEqual(JSON.parse(duplicate.dimensions), [{ url: '/same.png', width: 1, height: 2 }, { url: '/wide.png', width: 320, height: 200 }]);
  const unknown = replaceStructuredImage('slideshow', '/same.png|A', '[{"url":"/same.png","width":1,"height":2}]', 0, { kind: 'image', url: '/same.png', alt: '', width: null, height: 2 });
  assert.deepEqual(unknown, { items: '/same.png|', dimensions: '' });
  const portrait = replaceStructuredImage('testimonial_carousel', 'Un témoignage', '', 0, { ...picked, alt: 'inutilisé | décoratif' });
  assert.equal(portrait.items, 'Un témoignage |  |  | /wide.png');
  const emptySlide = replaceStructuredImage('slideshow', ' | ', '', 0, picked);
  assert.equal(emptySlide.items, ' /wide.png| Paysage vert');
  const draft = replaceStructuredImage('portfolio', ' |  | Nouveau projet | Art', '', 0, picked);
  assert.equal(draft.items, ' /wide.png|  Paysage vert| Nouveau projet | Art');
  const physical = replaceStructuredImage('slideshow', '/old|Alt\r\n\r\n/other|Alt|Deuxième', '', 2, picked);
  assert.equal(physical.items, '/old|Alt\n\n/wide.png|Paysage vert|Deuxième');
});

test('structured image replacement refuses malformed rows, excessive values and invalid metadata atomically', async () => {
  const { replaceStructuredImage } = await import('../../assets/js/model.js');
  const media = { kind: 'image', url: '/a.png', alt: 'Description', width: 1, height: 10000 };
  assert.equal(replaceStructuredImage('gallery', '/old|alt', '', 0, media), null);
  assert.equal(replaceStructuredImage('toString', '/old|alt', '', 0, media), null);
  for (const [type, items, index] of [
    ['slideshow', '', 0], ['slideshow', '/old', 0], ['slideshow', '/a|A|T|D|L|/x|extra', 0],
    ['portfolio', '/a|A|Titre', 0], ['portfolio', '/a|A||Catégorie', 0], ['portfolio', '/a|A|Titre|', 0],
    ['testimonial_carousel', ' | Auteur', 0], ['testimonial_carousel', 'Texte|Auteur|Fonction|/a|extra', 0],
    ['slideshow', '/old|alt\n\n/other|alt', 1], ['slideshow', '/old|alt', -1], ['slideshow', '/old|alt', 1], ['slideshow', '/old|alt', 0.5],
  ]) assert.equal(replaceStructuredImage(type, items, '', index, media), null, `${type} ${items} ${index}`);
  assert.equal(replaceStructuredImage('slideshow', '/old|alt', '{bad', 0, media), null);
  assert.equal(replaceStructuredImage('slideshow', '/old|alt', '[{"url":"/old","width":"1","height":2}]', 0, media), null);
  assert.equal(replaceStructuredImage('slideshow', '/old|alt', '', 0, { ...media, kind: 'video' }), null);
  assert.equal(replaceStructuredImage('slideshow', '/old|alt', '', 0, { ...media, url: '/bad|image' }), null);
  assert.equal(replaceStructuredImage('slideshow', '/old|alt', '', 0, { ...media, url: '/bad\nimage' }), null);
  assert.equal(replaceStructuredImage('slideshow', '/old|alt', '', 0, { ...media, url: '/' + 'a'.repeat(2048) }), null);
  assert.equal(replaceStructuredImage('slideshow', '/old|alt', '', 0, { ...media, alt: 'Un | autre champ' }), null);
  assert.notEqual(replaceStructuredImage('portfolio', '/old|alt|Titre|Catégorie', '', 0, { ...media, alt: 'é'.repeat(150) }), null);
  assert.equal(replaceStructuredImage('portfolio', '/old|alt|Titre|Catégorie', '', 0, { ...media, alt: 'é'.repeat(151) }), null);
  const twentySlides = Array.from({ length: 20 }, (_, index) => `/${index}|alt`).join('\n');
  assert.notEqual(replaceStructuredImage('slideshow', twentySlides, '', 19, media), null);
  assert.equal(replaceStructuredImage('slideshow', `${twentySlides}\n/extra|alt`, '', 0, media), null);
  const twentyFourProjects = Array.from({ length: 24 }, (_, index) => `/${index}|alt|Projet|Art`).join('\n');
  assert.notEqual(replaceStructuredImage('portfolio', twentyFourProjects, '', 23, media), null);
  assert.equal(replaceStructuredImage('portfolio', `${twentyFourProjects}\n/extra|alt|Projet|Art`, '', 0, media), null);
  assert.equal(replaceStructuredImage('testimonial_carousel', 'x'.repeat(10000), '', 0, media), null);
});

test('validated old nodes receive optional defaults without losing other values', () => {
  const input = [
    { id: 'empty', type: 'container', settings: null, styles: null, children: null, label: 'Existing' },
    { id: 'parent', type: 'container', children: [{ id: 'heading', type: 'heading', settings: { text: 'Kept' } }] },
  ];
  const before = structuredClone(input);
  const hydrated = withElementDefaults(input);
  assert.deepEqual(input, before);
  assert.deepEqual(hydrated[0], { id: 'empty', type: 'container', settings: {}, styles: {}, children: [], label: 'Existing' });
  assert.deepEqual(hydrated[1].children[0], { id: 'heading', type: 'heading', settings: { text: 'Kept' }, styles: {}, children: [] });
  assert.equal(add(hydrated, createElement('heading'), 'empty')[0].children.length, 1);
  assert.deepEqual(input, before);
});

test('preview diff selects a single changed subtree and rejects structural or multiple changes', () => {
  const parent = createElement('container'), first = createElement('heading'), second = createElement('text');
  const tree = add(add(add([], parent), first, parent.id), second, parent.id);
  assert.equal(changedElement(tree, tree), null);
  assert.equal(changedElement(tree, update(tree, first.id, { settings: { text: 'Salut' } })), first.id);
  assert.equal(changedElement(tree, update(tree, parent.id, { styles: { desktop: { gap: 12 } } })), parent.id);
  const twoChanges = update(update(tree, first.id, { hidden: true }), second.id, { label: 'Texte' });
  assert.equal(changedElement(tree, twoChanges), null);
  assert.equal(changedElement(tree, reorder(tree, first.id, 1)), null);
  assert.equal(changedElement(tree, remove(tree, first.id)), null);
  assert.equal(changedElement(tree, add(tree, createElement('button'), parent.id)), null);
  assert.equal(changedElement(tree, update(tree, first.id, { type: 'button' })), null);
  assert.equal(changedElement(tree, [{ ...parent, children: null }]), null);
});

test('preview detects a table of contents anywhere in the document', () => {
  const parent = createElement('container');
  const toc = createElement('table_of_contents');
  assert.equal(containsWidget([], 'table_of_contents'), false);
  assert.equal(containsWidget([parent], 'table_of_contents'), false);
  assert.equal(containsWidget(add([parent], toc, parent.id), 'table_of_contents'), true);
  assert.equal(containsWidget(null, 'table_of_contents'), false);
});

test('adding creates a new tree without mutating the original', () => {
  const root = createElement('container');
  const heading = createElement('heading');
  const initial = add([], root);
  const next = add(initial, heading, root.id);
  assert.equal(initial[0].children.length, 0);
  assert.equal(next[0].children[0].id, heading.id);
});
test('update, remove, and reorder operate on nested elements', () => {
  const a = createElement('heading'), b = createElement('text');
  const parent = createElement('container');
  let tree = add(add(add([], parent), a, parent.id), b, parent.id);
  tree = update(tree, a.id, { settings: { text: 'hello' } });
  assert.equal(find(tree, a.id).settings.text, 'hello');
  tree = reorder(tree, b.id, -1);
  assert.equal(tree[0].children[0].id, b.id);
  tree = remove(tree, a.id);
  assert.equal(find(tree, a.id), null);
});
test('inserting after a nested element is immutable and preserves its siblings', () => {
  const parent = createElement('container'), first = createElement('heading'), last = createElement('text');
  const initial = add(add(add([], parent), first, parent.id), last, parent.id);
  const inserted = createElement('button');
  const next = insertAfter(initial, first.id, inserted);
  assert.deepEqual(next[0].children.map(node => node.id), [first.id, inserted.id, last.id]);
  assert.deepEqual(initial[0].children.map(node => node.id), [first.id, last.id]);
  assert.deepEqual(insertAfter(initial, 'missing', inserted), initial);
});
test('relative drag operations preserve order across parents and reject cycles', () => {
  const parent = createElement('container'), child = createElement('heading');
  const first = createElement('text'), last = createElement('button');
  const initial = add(add(add(add([], parent), child, parent.id), first), last);
  const before = moveRelative(initial, last.id, first.id, 'before');
  assert.deepEqual(before.map(node => node.id), [parent.id, last.id, first.id]);
  const after = moveRelative(before, last.id, first.id, 'after');
  assert.deepEqual(after.map(node => node.id), [parent.id, first.id, last.id]);
  const nested = moveRelative(after, last.id, child.id, 'before');
  assert.deepEqual(nested[0].children.map(node => node.id), [last.id, child.id]);
  const out = moveRelative(nested, last.id, parent.id, 'after');
  assert.deepEqual(out.map(node => node.id), [parent.id, last.id, first.id]);
  assert.deepEqual(moveRelative(initial, parent.id, child.id, 'after'), initial);
  assert.deepEqual(moveRelative(initial, first.id, first.id, 'before'), initial);
  assert.deepEqual(moveRelative(initial, first.id, 'missing', 'after'), initial);
  assert.deepEqual(moveRelative(initial, first.id, last.id, 'invalid'), initial);
  const added = createElement('image');
  assert.deepEqual(insertRelative(initial, child.id, added, 'before')[0].children.map(node => node.id), [added.id, child.id]);
  assert.deepEqual(insertRelative(initial, 'missing', added, 'after'), initial);
  assert.deepEqual(initial.map(node => node.id), [parent.id, first.id, last.id]);
  assert.deepEqual(initial[0].children.map(node => node.id), [child.id]);
});

test('duplicating a subtree changes every identifier', () => {
  const parent = createElement('container'), child = createElement('heading');
  const tree = duplicate(add(add([], parent), child, parent.id), parent.id);
  assert.equal(tree.length, 2);
  assert.notEqual(tree[0].id, tree[1].id);
  assert.notEqual(tree[0].children[0].id, tree[1].children[0].id);
});
test('duplicating a converted heading does not duplicate its public anchor', () => {
  const heading = createElement('prime_block');
  heading.settings = { block_type: 'heading', block_data: '{"text":"Bonjour"}', block_anchor: 'bonjour' };
  const tree = duplicate([heading], heading.id);
  assert.equal(tree[0].settings.block_anchor, 'bonjour');
  assert.equal(Object.hasOwn(tree[1].settings, 'block_anchor'), false);
  assert.equal(heading.settings.block_anchor, 'bonjour');
});
test('copying a subtree avoids manual anchor collisions without changing links or the source', async () => {
  const { freshIds } = await import('../../assets/js/model.js');
  assert.equal(typeof freshIds, 'function');
  const makeAnchor = name => ({ ...createElement('anchor'), settings: { anchor: name } });
  const container = createElement('container');
  const first = makeAnchor('contact'), alreadySuffixed = makeAnchor('contact-2');
  const longest = makeAnchor('a'.repeat(64));
  const link = { ...createElement('button'), settings: { text: 'Contact', url: '#contact' } };
  const legacy = { ...createElement('prime_block'), settings: { block_type: 'heading', block_anchor: 'ancien', block_data: '{"text":"Titre"}' } };
  container.children = [first, alreadySuffixed, longest, link, legacy];
  const source = structuredClone(container);
  const duplicated = duplicate([container], container.id);
  assert.equal(duplicated[0].children[0].settings.anchor, 'contact');
  assert.equal(duplicated[1].children[0].settings.anchor, 'contact-3');
  assert.equal(duplicated[1].children[1].settings.anchor, 'contact-2-2');
  assert.match(duplicated[1].children[2].settings.anchor, /^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/);
  assert.equal(duplicated[1].children[2].settings.anchor.length, 64);
  assert.notEqual(duplicated[1].children[2].settings.anchor, longest.settings.anchor);
  assert.equal(duplicated[1].children[3].settings.url, '#contact');
  assert.equal(Object.hasOwn(duplicated[1].children[4].settings, 'block_anchor'), false);
  assert.deepEqual(container, source);

  const pasted = freshIds([container], { copied: true, into: duplicated });
  assert.equal(pasted[0].children[0].settings.anchor, 'contact-4');
  const replacements = freshIds([container], { copied: true });
  assert.equal(replacements[0].children[0].settings.anchor, 'contact');
  assert.equal(replacements[0].children[1].settings.anchor, 'contact-2');
  assert.notEqual(replacements[0].id, container.id);
  const imported = freshIds([container]);
  assert.equal(imported[0].children[4].settings.block_anchor, 'ancien');
  assert.deepEqual(container, source);

  const historic = { ...createElement('prime_block'), id: 'core', settings: { block_type: 'heading', block_anchor: 'ancien' } };
  const collisions = freshIds([makeAnchor('ancien'), makeAnchor('pb-core'), makeAnchor('ancien')], { copied: true, into: [historic] });
  assert.deepEqual(collisions.map(node => node.settings.anchor), ['ancien-2', 'pb-core-2', 'ancien-3']);
  assert.equal(historic.settings.block_anchor, 'ancien');
});

test('moving a container into its descendant is rejected', () => {
  const a = createElement('container'), b = createElement('container');
  const tree = add(add([], a), b, a.id);
  assert.deepEqual(moveInto(tree, a.id, b.id), tree);
});
test('moving elements to a container or back to root is immutable', () => {
  const a = createElement('container'), b = createElement('heading');
  const initial = add(add([], a), b);
  const nested = moveInto(initial, b.id, a.id);
  assert.equal(initial.length, 2);
  assert.equal(nested.length, 1);
  assert.equal(nested[0].children[0].id, b.id);
  assert.equal(moveInto(nested, b.id, null).length, 2);
});
test('history restores snapshots and clears redo on divergent edits', () => {
  const history = new History([]);
  const node = createElement('text');
  assert.equal(history.commit(add([], node)), true);
  assert.equal(history.undo().length, 0);
  assert.equal(history.redo().length, 1);
  history.undo();
  history.commit(add([], createElement('heading')));
  assert.equal(history.redo()[0].type, 'heading');
});
test('history labels and jumps preserve redo and discard a divergent future', () => {
  const history = new History([], 2, 'initial');
  const a = createElement('heading'), b = createElement('text'), c = createElement('button');
  history.commit(add(history.present, a), 'heading');
  history.commit(add(history.present, b), 'text');
  assert.deepEqual(history.entries().map(entry => [entry.label, entry.current]), [['initial', false], ['heading', false], ['text', true]]);
  assert.equal(history.jump(0).length, 0);
  assert.equal(history.jump(2).length, 2);
  assert.equal(history.jump(1).length, 1);
  history.commit(add(history.present, c), 'button');
  assert.deepEqual(history.entries().map(entry => entry.label), ['initial', 'heading', 'button']);
  assert.equal(history.redo().length, 2);
  history.commit(remove(history.present, a.id), 'remove');
  assert.deepEqual(history.entries().map(entry => entry.label), ['heading', 'button', 'remove']);
  assert.deepEqual(history.jump(10), history.present);
});
test('style updates preserve separate responsive values', () => {
  const node = createElement('heading');
  const tree = update([node], node.id, { styles: { desktop: { font_size: 32 }, mobile: { font_size: 20 } } });
  assert.equal(tree[0].styles.desktop.font_size, 32);
  assert.equal(tree[0].styles.mobile.font_size, 20);
});

test('responsive inheritance finds the nearest previous explicit value, including zero', () => {
  const styles = { desktop: { padding: 12, color: 'kit:primary' }, tablet: { padding: 0 } };
  assert.equal(inheritedStyle(styles, 'desktop', 'padding'), null);
  assert.deepEqual(inheritedStyle(styles, 'tablet', 'padding'), { mode: 'desktop', value: 12 });
  assert.deepEqual(inheritedStyle(styles, 'mobile', 'padding'), { mode: 'tablet', value: 0 });
  assert.deepEqual(inheritedStyle(styles, 'mobile', 'color'), { mode: 'desktop', value: 'kit:primary' });
  assert.equal(inheritedStyle(styles, 'mobile', 'width'), null);
  assert.equal(inheritedStyle(null, 'mobile', 'width'), null);
});
test('border overrides inherit independently and clearing them preserves explicit zero', () => {
  const desktop = { desktop: { border_style: 'solid', border_width: 4, border_color: 'kit:primary' } };
  const tablet = setResponsiveStyle(desktop, 'tablet', 'border_width', 0);
  const mobile = setResponsiveStyle(tablet, 'mobile', 'border_style', 'none');
  assert.deepEqual(inheritedStyle(mobile, 'tablet', 'border_style'), { mode: 'desktop', value: 'solid' });
  assert.deepEqual(inheritedStyle(mobile, 'mobile', 'border_width'), { mode: 'tablet', value: 0 });
  assert.deepEqual(inheritedStyle(mobile, 'mobile', 'border_color'), { mode: 'desktop', value: 'kit:primary' });
  const cleared = setResponsiveStyle(mobile, 'tablet', 'border_width', undefined);
  assert.deepEqual(inheritedStyle(cleared, 'mobile', 'border_width'), { mode: 'desktop', value: 4 });
  assert.equal(mobile.tablet.border_width, 0);
  assert.equal(desktop.tablet, undefined);
  assert.equal(cleared.mobile.border_style, 'none');
});
test('shadow presets inherit and explicit none differs from clearing an override', () => {
  const desktop = setResponsiveStyle({}, 'desktop', 'box_shadow', 'soft');
  const tablet = setResponsiveStyle(desktop, 'tablet', 'box_shadow', 'none');
  assert.deepEqual(inheritedStyle(tablet, 'tablet', 'box_shadow'), { mode: 'desktop', value: 'soft' });
  assert.deepEqual(inheritedStyle(tablet, 'mobile', 'box_shadow'), { mode: 'tablet', value: 'none' });
  const cleared = setResponsiveStyle(tablet, 'tablet', 'box_shadow', undefined);
  assert.deepEqual(inheritedStyle(cleared, 'mobile', 'box_shadow'), { mode: 'desktop', value: 'soft' });
  assert.equal(tablet.tablet.box_shadow, 'none');
  assert.deepEqual(cleared, desktop);
});
test('per-side spacing follows the cascade of side and all-sides values at each breakpoint', () => {
  const styles = {
    desktop: { padding_top: 12, padding: 20, margin: '8px', margin_left: 'auto' },
    tablet: { padding: 0, margin_right: -4 },
    mobile: { padding_left: '2rem' },
  };
  assert.deepEqual(spacingStyleSource(styles, 'desktop', 'padding_top'), { mode: 'desktop', key: 'padding_top', value: 12 });
  assert.deepEqual(spacingStyleSource(styles, 'desktop', 'padding_bottom'), { mode: 'desktop', key: 'padding', value: 20 });
  assert.deepEqual(spacingStyleSource(styles, 'tablet', 'padding_top'), { mode: 'tablet', key: 'padding', value: 0 });
  assert.deepEqual(spacingStyleSource(styles, 'mobile', 'padding_top'), { mode: 'tablet', key: 'padding', value: 0 });
  assert.deepEqual(spacingStyleSource(styles, 'mobile', 'padding_left'), { mode: 'mobile', key: 'padding_left', value: '2rem' });
  assert.deepEqual(spacingStyleSource(styles, 'mobile', 'margin_left'), { mode: 'desktop', key: 'margin_left', value: 'auto' });
  assert.deepEqual(spacingStyleSource(styles, 'mobile', 'margin_right'), { mode: 'tablet', key: 'margin_right', value: -4 });
  const cleared = setResponsiveStyle(styles, 'tablet', 'padding', undefined);
  assert.deepEqual(spacingStyleSource(cleared, 'mobile', 'padding_top'), { mode: 'desktop', key: 'padding_top', value: 12 });
  assert.equal(styles.tablet.padding, 0);
  assert.equal(spacingStyleSource(null, 'mobile', 'padding_left'), null);
  assert.equal(spacingStyleSource(styles, 'mobile', 'padding'), null);
  assert.equal(spacingStyleSource(styles, 'mobile', 'padding_unsafe'), null);
});
test('position inheritance distinguishes zero, automatic resets and cleared overrides', () => {
  const desktop = setResponsiveStyle([], 'desktop', 'offset_top', -12);
  const tablet = setResponsiveStyle(desktop, 'tablet', 'offset_top', 0);
  const mobile = setResponsiveStyle(tablet, 'mobile', 'offset_top', 'auto');
  assert.deepEqual(inheritedStyle(mobile, 'tablet', 'offset_top'), { mode: 'desktop', value: -12 });
  assert.deepEqual(inheritedStyle(mobile, 'mobile', 'offset_top'), { mode: 'tablet', value: 0 });
  assert.equal(mobile.mobile.offset_top, 'auto');
  const cleared = setResponsiveStyle(mobile, 'mobile', 'offset_top', undefined);
  assert.deepEqual(cleared, tablet);
  assert.equal(JSON.stringify(mobile), '{"desktop":{"offset_top":-12},"tablet":{"offset_top":0},"mobile":{"offset_top":"auto"}}');
  assert.deepEqual(desktop, { desktop: { offset_top: -12 } });
});
test('style editing handles empty maps returned by PHP without losing changes', () => {
  const empty = [];
  const desktop = setResponsiveStyle(empty, 'desktop', 'visibility', 'hide');
  assert.deepEqual(desktop, { desktop: { visibility: 'hide' } });
  assert.deepEqual(empty, []);
  assert.equal(JSON.stringify(desktop), '{"desktop":{"visibility":"hide"}}');
  const tablet = setResponsiveStyle(desktop, 'tablet', 'padding', 0);
  assert.deepEqual(tablet, { desktop: { visibility: 'hide' }, tablet: { padding: 0 } });
  assert.deepEqual(desktop, { desktop: { visibility: 'hide' } });
  const cleared = setResponsiveStyle(tablet, 'tablet', 'padding', undefined);
  assert.deepEqual(cleared, desktop);
  assert.deepEqual(setResponsiveStyle({ mobile: [] }, 'mobile', 'visibility', 'show'), { mobile: { visibility: 'show' } });
  const node = { ...createElement('heading'), styles: [] };
  const history = new History([node]);
  assert.equal(history.commit(update(history.present, node.id, { styles: desktop })), true);
  assert.equal(history.present[0].styles.desktop.visibility, 'hide');
});
test('responsive visibility inherits and allows explicit show after hide', () => {
  const styles = { desktop: { visibility: 'hide' }, mobile: { visibility: 'show' } };
  assert.equal(responsiveVisibility(styles, 'desktop'), 'hide');
  assert.equal(responsiveVisibility(styles, 'tablet'), 'hide');
  assert.equal(responsiveVisibility(styles, 'mobile'), 'show');
  assert.equal(responsiveVisibility({ tablet: { visibility: 'hide' } }, 'desktop'), 'show');
  assert.equal(responsiveVisibility({ tablet: { visibility: 'hide' } }, 'mobile'), 'hide');
  assert.equal(responsiveVisibility({ desktop: { visibility: 'hide' }, tablet: { visibility: 'invalid' } }, 'mobile'), 'hide');
  assert.equal(responsiveVisibility(null, 'mobile'), 'show');
});
test('extension-defined containers support nesting and safe reparenting', () => {
  const group = { id: 'custom', type: 'third-party-group', settings: {}, styles: {}, children: [] };
  const child = { id: 'child', type: 'heading', settings: {}, styles: {}, children: [] };
  const tree = add([group], child, 'custom', ['container', 'third-party-group']);
  assert.equal(tree[0].children[0].id, 'child');
  const root = moveInto(tree, 'child', null, ['container', 'third-party-group']);
  assert.equal(root[1].id, 'child');
  assert.equal(tree[0].children.length, 1);
});
