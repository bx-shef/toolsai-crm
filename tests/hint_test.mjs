// #734: подсказка «?» на месте (lib/ui/hint.js): шторка снизу на узком экране, подсказка у значка на широком;
// заголовок, «что это», «зачем», «Подробнее» и «Понятно»; закрытие, фокус, Esc, свайп; адрес «Подробнее» — только свой портал.
// DOM — минимальная подделка ниже: проверяется порядок действий кода, а не вёрстка (вёрстку смотрят в браузере).
// HINT_JS=<путь> — прогнать другой файл (мутации: копия с одной правкой должна краснеть).
import assert from 'node:assert/strict'
import { createRequire } from 'node:module'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const require = createRequire(import.meta.url)
const here = path.dirname(fileURLToPath(import.meta.url))
const Hint = require(process.env.HINT_JS || path.join(here, '../lib/ui/hint.js'))

let passed = 0
const check = (name, fn) => { fn(); passed++; console.log('  OK   ' + name) }

/** Узел: ровно то, что трогает hint.js. */
class Node {
  constructor(doc, tag) {
    this.doc = doc; this.tag = tag; this.children = []; this.parentNode = null; this.attrs = {}; this.listeners = {}
    this.style = {}; this.className = ''; this.textContent = ''; this.id = ''; this.scrollTop = 0; this.rect = { left: 0, right: 0, top: 0, bottom: 0, width: 0, height: 0 }
  }
  setAttribute(name, value) { this.attrs[name] = String(value) }
  getAttribute(name) { return name in this.attrs ? this.attrs[name] : null }
  appendChild(child) { child.parentNode = this; this.children.push(child); return child }
  removeChild(child) { this.children = this.children.filter((c) => c !== child); child.parentNode = null }
  addEventListener(type, fn) { (this.listeners[type] ||= []).push(fn) }
  removeEventListener(type, fn) { this.listeners[type] = (this.listeners[type] || []).filter((f) => f !== fn) }
  contains(node) { for (let n = node; n; n = n.parentNode) { if (n === this) return true } return false }
  focus() { this.doc.activeElement = this }
  closest(selector) {
    const attr = selector.slice(1, -1)
    for (let n = this; n; n = n.parentNode) { if (attr in n.attrs) return n }
    return null
  }
  getBoundingClientRect() { return this.rect }
  find(cls) {
    if (this.className.split(' ').includes(cls)) return this
    for (const c of this.children) { const f = c.find(cls); if (f) return f }
    return null
  }
  fire(type, event = {}) {
    const e = { target: this, preventDefault() { e.prevented = true }, stopPropagation() { e.stopped = true }, ...event }
    for (const fn of [...(this.listeners[type] || [])]) fn(e)
    return e
  }
}

/** Окно с документом; width — ширина окна: до 640 — телефон. */
function setup(width) {
  const doc = { activeElement: null, listeners: {} }
  doc.createElement = (tag) => new Node(doc, tag)
  doc.body = new Node(doc, 'body')
  doc.addEventListener = (type, fn) => { (doc.listeners[type] ||= []).push(fn) }
  doc.removeEventListener = (type, fn) => { doc.listeners[type] = (doc.listeners[type] || []).filter((f) => f !== fn) }
  doc.fire = (type, event = {}) => {
    const e = { preventDefault() { e.prevented = true }, stopPropagation() { e.stopped = true }, ...event }
    for (const fn of [...(doc.listeners[type] || [])]) fn(e)
    return e
  }
  const win = { document: doc, innerWidth: width, innerHeight: 800, listeners: {}, setTimeout: (fn) => fn() }
  win.addEventListener = (type, fn) => { (win.listeners[type] ||= []).push(fn) }
  win.removeEventListener = (type, fn) => { win.listeners[type] = (win.listeners[type] || []).filter((f) => f !== fn) }
  const hint = Hint.bind(win)
  const trigger = (attrs = {}) => {
    const node = new Node(doc, 'button')
    node.attrs = { 'aria-expanded': 'false', 'data-hint-title': 'Месячная квота', 'data-hint-what': 'Сколько денег можно потратить на AI.', 'data-hint-why': 'Защита от неожиданного счёта.', 'data-hint-more': '/note/document/5/#kvota', ...attrs }
    node.rect = { left: 300, right: 318, top: 100, bottom: 118, width: 18, height: 18 }
    doc.body.appendChild(node)
    return node
  }
  const root = () => doc.body.children.find((c) => c.className.startsWith('shef-hint ')) || null
  const click = (node) => doc.fire('click', { target: node })

  return { win, doc, hint, trigger, root, click }
}

check('адрес «Подробнее»: только путь своего портала — без схем, //, обратной косой и пробелов', () => {
  assert.deepEqual(
    ['/note/document/5/#kvota', '/note/document/5/', '//evil.example/x', '/\\evil.example', 'https://evil.example', 'javascript:alert(1)', ' /x', '/a b', '', null].map(Hint.safeUrl),
    ['/note/document/5/#kvota', '/note/document/5/', '', '', '', '', '', '', '', ''])
})

check('read: тексты из атрибутов, пробелы по краям срезаны; нет «что это» — нет подсказки; чужой адрес отброшен', () => {
  const attrs = { 'data-hint-title': ' Квота ', 'data-hint-what': ' Что ', 'data-hint-why': '', 'data-hint-more': 'https://evil.example' }
  const node = { getAttribute: (n) => attrs[n] ?? null }
  assert.deepEqual(Hint.read(node), { title: 'Квота', what: 'Что', why: '', more: '' })
  assert.equal(Hint.read({ getAttribute: () => null }), null)
})

check('place: под значком по центру; у края окна — прижата; снизу нет места — над значком; нигде нет — в границах окна', () => {
  const view = { w: 1000, h: 800 }
  const size = { w: 340, h: 200 }
  assert.deepEqual(Hint.place({ left: 500, right: 518, top: 100, bottom: 118 }, size, view), { left: 339, top: 126, above: false })
  assert.equal(Hint.place({ left: 2, right: 20, top: 100, bottom: 118 }, size, view).left, 12)
  assert.equal(Hint.place({ left: 990, right: 1000, top: 100, bottom: 118 }, size, view).left, 648)
  assert.deepEqual(Hint.place({ left: 500, right: 518, top: 700, bottom: 718 }, size, view), { left: 339, top: 492, above: true })
  assert.deepEqual(Hint.place({ left: 500, right: 518, top: 150, bottom: 168 }, { w: 340, h: 700 }, { w: 1000, h: 800 }), { left: 339, top: 88, above: false })
})

check('широкий экран: подсказка у значка — диалог с заголовком, ответом, «зачем», «Подробнее» и «Понятно»; страница не меняется', () => {
  const t = setup(1024)
  const q = t.trigger()
  t.click(q)
  const root = t.root()
  assert.ok(root, 'подсказка открылась')
  assert.ok(root.className.includes('--pop') && !root.className.includes('--sheet'))
  const box = root.find('shef-hint__box')
  assert.deepEqual([box.getAttribute('role'), box.getAttribute('aria-modal')], ['dialog', 'true'])
  const title = root.find('shef-hint__title')
  assert.deepEqual([title.textContent, box.getAttribute('aria-labelledby') === title.id && title.id !== ''], ['Месячная квота', true])
  assert.deepEqual([root.find('shef-hint__text').textContent, root.find('--why').textContent], ['Сколько денег можно потратить на AI.', 'Защита от неожиданного счёта.'])
  const more = root.find('shef-hint__more')
  assert.deepEqual([more.textContent, more.getAttribute('href'), more.getAttribute('data-kb'), more.getAttribute('target')], ['Подробнее', '/note/document/5/#kvota', '/note/document/5/#kvota', '_blank'])
  assert.equal(root.find('shef-hint__ok').textContent, 'Понятно')
  assert.equal(q.getAttribute('aria-expanded'), 'true')
  assert.equal(t.doc.activeElement, root.find('shef-hint__ok'), 'фокус — на «Понятно»')
  assert.ok(typeof box.style.left === 'string' && typeof box.style.top === 'string', 'место у значка задано')
})

check('щелчок по значку не нажимает объемлющую подпись или ссылку: отмена действия по умолчанию и всплытия', () => {
  const t = setup(1024)
  const e = t.click(t.trigger())
  assert.deepEqual([e.prevented, e.stopped], [true, true])
})

check('нет «Подробнее» — нет ссылки; нет заголовка — заголовком служит ответ, без повтора', () => {
  const t = setup(1024)
  t.click(t.trigger({ 'data-hint-more': '', 'data-hint-title': '', 'data-hint-why': '' }))
  const root = t.root()
  assert.equal(root.find('shef-hint__more'), null)
  assert.equal(root.find('shef-hint__title').textContent, 'Сколько денег можно потратить на AI.')
  assert.equal(root.find('shef-hint__text'), null)
})

check('чужой адрес в data-hint-more — «Подробнее» не рисуется', () => {
  const t = setup(1024)
  t.click(t.trigger({ 'data-hint-more': 'https://evil.example/x' }))
  assert.equal(t.root().find('shef-hint__more'), null)
})

check('нет «что это» — подсказка не открывается', () => {
  const t = setup(1024)
  t.hint.open(t.trigger({ 'data-hint-what': '' }))
  assert.equal(t.root(), null)
})

check('телефон: шторка снизу — без места у значка; «Понятно» закрывает, фокус возвращается на «?», aria-expanded снят', () => {
  const t = setup(375)
  const q = t.trigger()
  t.click(q)
  const root = t.root()
  assert.ok(root.className.includes('--sheet'))
  assert.deepEqual([root.find('shef-hint__box').style.left, root.find('shef-hint__box').style.top], [undefined, undefined])
  root.find('shef-hint__ok').fire('click')
  assert.deepEqual([t.root(), q.getAttribute('aria-expanded'), t.doc.activeElement === q, t.hint.isOpen()], [null, 'false', true, false])
})

check('щелчок по затемнению закрывает', () => {
  const t = setup(375)
  const q = t.trigger()
  t.click(q)
  t.root().find('shef-hint__dim').fire('click')
  assert.deepEqual([t.root(), t.doc.activeElement === q], [null, true])
})

check('Esc закрывает и не доходит до слайдера портала; чужие клавиши — нет', () => {
  const t = setup(1024)
  const q = t.trigger()
  t.click(q)
  const other = t.doc.fire('keydown', { key: 'a' })
  assert.deepEqual([t.hint.isOpen(), other.stopped], [true, undefined])
  const esc = t.doc.fire('keydown', { key: 'Escape' })
  assert.deepEqual([t.hint.isOpen(), esc.stopped, esc.prevented, t.doc.activeElement === q], [false, true, true, true])
  assert.equal((t.doc.listeners.keydown || []).length, 0, 'обработчик клавиш снят')
})

check('Tab не уходит за подсказку: «Понятно» → «Подробнее» по кругу, Shift+Tab — обратно; без «Подробнее» фокус стоит на «Понятно»', () => {
  const t = setup(1024)
  t.click(t.trigger())
  const root = t.root()
  const ok = root.find('shef-hint__ok')
  const more = root.find('shef-hint__more')
  assert.equal(t.doc.activeElement, ok)
  assert.equal(t.doc.fire('keydown', { key: 'Tab' }).prevented, true)
  assert.equal(t.doc.activeElement, more)
  assert.equal(t.doc.fire('keydown', { key: 'Tab', shiftKey: true }).prevented, true)
  assert.equal(t.doc.activeElement, ok)
  t.doc.fire('keydown', { key: 'Escape' })
  const lone = setup(1024)
  lone.click(lone.trigger({ 'data-hint-more': '' }))
  const only = lone.root().find('shef-hint__ok')
  assert.equal(lone.doc.fire('keydown', { key: 'Tab' }).prevented, true)
  assert.equal(lone.doc.activeElement, only)
})

check('«Подробнее» закрывает подсказку, статью открывает общий обработчик a[data-kb] страницы', () => {
  const t = setup(1024)
  t.click(t.trigger())
  t.root().find('shef-hint__more').fire('click')
  assert.equal(t.root(), null)
})

check('повторный щелчок по тому же значку закрывает, по другому — открывает его подсказку', () => {
  const t = setup(1024)
  const a = t.trigger()
  const b = t.trigger({ 'data-hint-title': 'Другое', 'data-hint-what': 'Другой ответ.' })
  t.click(a)
  t.click(a)
  assert.equal(t.root(), null)
  t.click(a)
  t.click(b)
  assert.deepEqual([t.root().find('shef-hint__title').textContent, a.getAttribute('aria-expanded'), b.getAttribute('aria-expanded'), t.doc.body.children.filter((c) => c.className.startsWith('shef-hint ')).length], ['Другое', 'false', 'true', 1])
})

check('телефон: свайп шторки вниз дальше порога закрывает, короче — возвращает на место; с прокрученного содержимого свайп не начинается', () => {
  const t = setup(375)
  const q = t.trigger()
  t.click(q)
  const box = t.root().find('shef-hint__box')
  const touch = (type, y) => box.fire(type, { touches: [{ clientY: y }] })
  touch('touchstart', 300)
  touch('touchmove', 330)
  assert.equal(box.style.transform, 'translateY(30px)')
  box.fire('touchend')
  assert.deepEqual([t.hint.isOpen(), box.style.transform], [true, ''])
  touch('touchstart', 300)
  touch('touchmove', 300 + Hint.SWIPE + 40)
  box.fire('touchend')
  assert.deepEqual([t.hint.isOpen(), t.doc.activeElement === q], [false, true])
  t.click(q)
  const scrolled = t.root().find('shef-hint__box')
  // Листается текст (.shef-hint__body), а не сама шторка: тот же жест вниз листает длинный ответ вверх
  t.root().find('shef-hint__body').scrollTop = 50
  scrolled.fire('touchstart', { touches: [{ clientY: 300 }] })
  scrolled.fire('touchmove', { touches: [{ clientY: 500 }] })
  assert.equal(scrolled.style.transform, undefined, 'шторка за пальцем не едет')
  scrolled.fire('touchend')
  assert.equal(t.hint.isOpen(), true)
  t.root().find('shef-hint__body').scrollTop = 0
  scrolled.fire('touchstart', { touches: [{ clientY: 300 }] })
  scrolled.fire('touchmove', { touches: [{ clientY: 100 }] })
  assert.equal(scrolled.style.transform, '', 'вверх шторку не тянем')
})

check('подсказка у значка закрывается при прокрутке страницы и смене размера; шторка — стоит; прокрутка внутри подсказки не закрывает', () => {
  const wide = setup(1024)
  wide.click(wide.trigger())
  wide.doc.fire('scroll', { target: wide.root().find('shef-hint__body') })
  assert.equal(wide.hint.isOpen(), true)
  wide.doc.fire('scroll', { target: wide.doc.body })
  assert.equal(wide.hint.isOpen(), false)
  wide.click(wide.trigger())
  wide.win.listeners.resize.forEach((fn) => fn())
  assert.equal(wide.hint.isOpen(), false)
  const phone = setup(375)
  phone.click(phone.trigger())
  phone.doc.fire('scroll', { target: phone.doc.body })
  phone.win.listeners.resize.forEach((fn) => fn())
  assert.equal(phone.hint.isOpen(), true)
  phone.win.innerWidth = 1024
  phone.win.listeners.resize.forEach((fn) => fn())
  assert.equal(phone.hint.isOpen(), false, 'телефон повернули — шторка уступает подсказке')
})

check('закрытая подсказка не оставляет обработчиков на документе и окне', () => {
  const t = setup(1024)
  t.click(t.trigger())
  t.hint.close()
  assert.deepEqual([(t.doc.listeners.keydown || []).length, (t.doc.listeners.scroll || []).length, (t.win.listeners.resize || []).length, t.doc.listeners.click.length], [0, 0, 0, 1])
})

check('в node подключение само не запускается (нет document); файл не ставит ничего в глобальное окружение', () => {
  assert.equal(Hint.controller, undefined)
  assert.deepEqual([Hint.NARROW, Hint.SWIPE], [640, 60])
})

console.log('\nПроверок пройдено: ' + passed)
