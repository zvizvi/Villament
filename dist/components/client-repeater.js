function R(i, s, u, a = null) {
  if (a === null)
    return { ...i, [s]: u };
  if (!Object.hasOwn(i, a))
    return i;
  const c = {};
  for (const [h, v] of Object.entries(i))
    c[h] = v, h === a && (c[s] = u);
  return c;
}
function y(i, s) {
  const u = { ...i };
  return delete u[s], u;
}
function L(i, s, u) {
  return Object.hasOwn(i, s) ? { ...i, [u]: C(i[s]) } : i;
}
function w(i, s, u) {
  const a = Object.keys(i), c = a.indexOf(s), h = u === "up" ? c - 1 : c + 1;
  return c === -1 || h < 0 || h >= a.length ? i : ([a[c], a[h]] = [a[h], a[c]], O(i, a));
}
function O(i, s) {
  if (s.some((a) => !Object.hasOwn(i, a)))
    return i;
  const u = {};
  for (const a of s)
    u[a] = i[a];
  for (const [a, c] of Object.entries(i))
    Object.hasOwn(u, a) || (u[a] = c);
  return u;
}
function I(i, s, u, a = null) {
  const c = [];
  let h = "";
  return i.forEach(({ token: v, hash: e }, t) => {
    const l = t === 0 && a !== null ? a : s();
    t === 0 && (h = l), c.push([v, l], [e, u(l)]);
  }), { key: h, replacements: c };
}
function E(i, s) {
  return s.reduce((u, [a, c]) => u.split(a).join(c), i);
}
function $(i, s) {
  return JSON.parse(E(JSON.stringify(i), s));
}
function S(i, s) {
  return s == null || i < s;
}
function C(i) {
  return i === void 0 ? void 0 : JSON.parse(JSON.stringify(i));
}
function k(i) {
  return !i || Array.isArray(i) ? Array.isArray(i) ? { ...C(i) } : {} : C(i);
}
const M = ":scope > template[data-villament-repeater-template]", A = "data-villament-repeater-action", N = ":scope > ul.fi-fo-repeater-items, :scope > ul.fi-fo-simple-repeater-items", T = ":scope > .fi-fo-repeater-add, :scope > .fi-fo-simple-repeater-add", P = ".fi-fo-repeater-item-header-end-actions, .fi-fo-simple-repeater-item-actions", q = {
  generateKey: () => globalThis.crypto.randomUUID(),
  hash: (i) => typeof globalThis.jsMd5 == "function" ? globalThis.jsMd5(i) : i
};
function D(i, s = q) {
  return function(a) {
    const c = i(a), { statePath: h, message: v } = a;
    return {
      ...c,
      /** @type {?(event: MouseEvent) => void} */
      villamentClickListener: null,
      init() {
        c.init?.call(this), this.villamentClickListener = (e) => this.villamentHandleClick(e), this.$root.addEventListener("click", this.villamentClickListener, !0);
      },
      destroy() {
        c.destroy?.call(this), this.$root.removeEventListener("click", this.villamentClickListener, !0);
      },
      /**
       * Drag reorder. Sortable has already moved the element; stock
       * would now call the server's `reorder` action.
       *
       * @param {CustomEvent & { target: any }} event
       */
      reorder(e) {
        if (!this.villamentMeta()?.actions?.reorder)
          return c.reorder?.call(this, e);
        if (e.target !== this.villamentList())
          return;
        const t = e.target.sortable?.toArray?.() ?? this.villamentItemElements().map((l) => l.getAttribute("x-sortable-item"));
        this.villamentWrite(O(this.villamentRead(), t)), this.villamentRefresh(), this.finish?.();
      },
      /**
       * @param {MouseEvent} event
       */
      villamentHandleClick(e) {
        const t = (
          /** @type {?HTMLElement} */
          e.target instanceof Element ? e.target.closest(`[${A}]`) : null
        );
        !t || !this.villamentOwns(t) || (e.preventDefault(), e.stopPropagation(), !(t.hasAttribute("disabled") || t.getAttribute("aria-disabled") === "true") && this.villamentRun(t.getAttribute(A), t));
      },
      /**
       * @param {?string} operation
       * @param {HTMLElement} control
       */
      villamentRun(e, t) {
        const n = this.villamentItemOf(t)?.getAttribute("x-sortable-item") ?? null;
        switch (e) {
          case "add":
            return this.villamentAdd(null);
          case "addBetween":
            return this.villamentAdd(this.villamentItemBefore(t)?.getAttribute("x-sortable-item") ?? null, !0);
          case "delete":
            return n && this.villamentDelete(n);
          case "clone":
            return n && this.villamentClone(n);
          case "moveUp":
            return n && this.villamentMove(n, "up", t);
          case "moveDown":
            return n && this.villamentMove(n, "down", t);
        }
      },
      /**
       * @param {?string} afterKey
       * @param {boolean} [isBetween]
       */
      villamentAdd(e, t = !1) {
        const l = this.villamentMeta(), n = this.villamentRead();
        if (!l || t && e === null || !S(Object.keys(n).length, l.maxItems))
          return;
        const { key: r, replacements: o } = I(l.tokens, s.generateKey, s.hash);
        this.villamentWrite(R(n, r, $(l.state, o), e));
        const m = this.villamentItemFromTemplate(o);
        return m && this.villamentInsert(m, e), this.villamentRefresh(), r;
      },
      /**
       * @param {string} key
       */
      villamentDelete(e) {
        this.villamentWrite(y(this.villamentRead(), e)), this.villamentItemElement(e)?.remove(), this.villamentRefresh();
      },
      /**
       * @param {string} key
       */
      villamentClone(e) {
        const t = this.villamentMeta(), l = this.villamentRead();
        if (!t || !Object.hasOwn(l, e) || !S(Object.keys(l).length, t.maxItems))
          return;
        const { key: n, replacements: r } = I(t.tokens, s.generateKey, s.hash);
        this.villamentWrite(L(l, e, n));
        const o = this.villamentItemFromTemplate(r);
        return o && this.villamentInsert(o, null), this.villamentRefresh(), n;
      },
      /**
       * @param {string} key
       * @param {'up' | 'down'} direction
       * @param {?HTMLElement} [control]
       */
      villamentMove(e, t, l = null) {
        const n = this.villamentRead(), r = w(n, e, t);
        if (r === n)
          return;
        const o = l !== null && document.activeElement === l;
        this.villamentWrite(r);
        const m = this.villamentItemElement(e), f = this.villamentList();
        if (m && f) {
          const d = this.villamentItemElements(), b = d.indexOf(m);
          t === "up" ? f.insertBefore(m, d[b - 1]) : f.insertBefore(m, d[b + 1].nextSibling);
        }
        this.villamentRefresh(), this.villamentAnnounceMove(e), o && m && this.villamentRestoreFocus(m, t);
      },
      // ---- state ---------------------------------------------------
      /** @returns {Record<string, any>} */
      villamentRead() {
        return k(this.$wire.get(h));
      },
      /**
       * Deferred: no request now. The next commit — a save, a live
       * field, a `callSchemaComponentMethod()` from a searchable
       * select in the new row — carries it before its own call.
       *
       * @param {Record<string, any>} items
       */
      villamentWrite(e) {
        this.$wire.set(h, e, !1);
      },
      // ---- template ------------------------------------------------
      /** @returns {?HTMLTemplateElement} */
      villamentTemplate() {
        return this.$root.querySelector(M);
      },
      /** @returns {?TemplateMeta} */
      villamentMeta() {
        const e = this.villamentTemplatePart("script[data-villament-repeater-meta]");
        if (!e)
          return null;
        try {
          return JSON.parse(e.textContent ?? "");
        } catch {
          return null;
        }
      },
      /**
       * @param {string} selector
       * @returns {?Element}
       */
      villamentTemplatePart(e) {
        return [...this.villamentTemplate()?.content.children ?? []].find((t) => t.matches(e)) ?? null;
      },
      /**
       * @param {string} selector
       * @returns {?string}
       */
      villamentNestedTemplateHtml(e) {
        const t = (
          /** @type {?HTMLTemplateElement} */
          this.villamentTemplatePart(e)
        );
        return t ? t.innerHTML : null;
      },
      /**
       * @param {Array<[string, string]>} replacements
       * @returns {?Element}
       */
      villamentItemFromTemplate(e) {
        const t = this.villamentTemplatePart("li[x-sortable-item]");
        return t ? this.villamentParse(E(t.outerHTML, e)) : null;
      },
      /**
       * Parsed through a fresh `<template>`, so nothing initializes
       * before the element is in the document; Alpine (and with it
       * every `wire:*` directive) picks it up from its mutation
       * observer once it is inserted.
       *
       * @param {string} html
       * @returns {?Element}
       */
      villamentParse(e) {
        const t = document.createElement("template");
        return t.innerHTML = e.trim(), t.content.firstElementChild;
      },
      // ---- DOM -----------------------------------------------------
      /**
       * @param {Element} element
       * @returns {boolean}
       */
      villamentOwns(e) {
        return e.closest("[data-reorder-owner]") === this.$root;
      },
      /** @returns {?HTMLElement} */
      villamentList() {
        return this.$root.querySelector(N);
      },
      /** @returns {HTMLElement[]} */
      villamentItemElements() {
        const e = this.villamentList();
        return e ? (
          /** @type {HTMLElement[]} */
          [...e.children].filter((t) => t.hasAttribute("x-sortable-item"))
        ) : [];
      },
      /**
       * @param {string} key
       * @returns {?HTMLElement}
       */
      villamentItemElement(e) {
        return this.villamentItemElements().find((t) => t.getAttribute("x-sortable-item") === e) ?? null;
      },
      /**
       * @param {Element} control
       * @returns {?HTMLElement}
       */
      villamentItemOf(e) {
        const t = this.villamentList();
        let l = e.closest("[x-sortable-item]");
        for (; l && l.parentElement !== t; )
          l = l.parentElement?.closest("[x-sortable-item]") ?? null;
        return (
          /** @type {?HTMLElement} */
          l
        );
      },
      /**
       * The item an "add between" separator follows.
       *
       * @param {Element} control
       * @returns {?Element}
       */
      villamentItemBefore(e) {
        const t = this.villamentList();
        let l = e.closest("li");
        for (; l && l.parentElement !== t; )
          l = l.parentElement?.closest("li") ?? null;
        let n = l?.previousElementSibling ?? null;
        for (; n && !n.hasAttribute("x-sortable-item"); )
          n = n.previousElementSibling;
        return n;
      },
      /**
       * @param {Element} element
       * @param {?string} afterKey
       */
      villamentInsert(e, t) {
        const l = this.villamentEnsureList();
        if (!l)
          return;
        const n = t === null ? null : this.villamentItemElement(t);
        n ? l.insertBefore(e, n.nextSibling) : l.appendChild(e);
      },
      /**
       * Stock renders no `<ul>` at all for an empty repeater.
       *
       * @returns {?HTMLElement}
       */
      villamentEnsureList() {
        const e = this.villamentList();
        if (e)
          return e;
        const t = this.villamentTemplatePart("ul");
        if (!t)
          return null;
        const l = (
          /** @type {HTMLElement} */
          t.cloneNode(!1)
        );
        return this.$root.insertBefore(l, this.villamentChromeAnchor(T)), l;
      },
      /**
       * Where stock's chrome after the list starts: the add button,
       * then the reordering descriptions.
       *
       * @param {string} [selector]
       * @returns {?Element}
       */
      villamentChromeAnchor(e = "") {
        return (e ? this.$root.querySelector(e) : null) ?? this.$root.querySelector(":scope > span[data-reorder-description]");
      },
      /**
       * Brings everything stock re-renders after a structural action
       * back in line with the item list: the empty-list case, the
       * separators, first/last move buttons, item numbers, the clone
       * and add buttons at `maxItems`, collapse/expand-all, and the
       * screen-reader position descriptions.
       */
      villamentRefresh() {
        const e = this.villamentMeta();
        if (!e)
          return;
        let t = this.villamentItemElements();
        const l = t.length, n = !S(l, e.maxItems);
        l === 0 && this.villamentList()?.remove(), this.villamentRefreshSeparators(t, n), t = this.villamentItemElements(), t.forEach((r, o) => {
          const m = r.getAttribute("x-sortable-item") ?? "";
          this.villamentSetDisabled(this.villamentControl(r, "moveUp"), o === 0), this.villamentSetDisabled(this.villamentControl(r, "moveDown"), o === l - 1), e.hasItemNumbers && this.villamentNumber(r, o + 1), e.actions.clone && this.villamentRefreshClone(r, m, e, n);
        }), e.actions.add && this.villamentRefreshAdd(n), this.$root.querySelector(":scope > .fi-fo-repeater-actions")?.classList.toggle("fi-hidden", l < 2), this.villamentRefreshDescriptions(t, e), this.$root.dataset.reorderItems = JSON.stringify(t.map((r) => r.getAttribute("x-sortable-item"))), this.updateHandles?.();
      },
      /**
       * @param {HTMLElement[]} items
       * @param {boolean} isFull
       */
      villamentRefreshSeparators(e, t) {
        const l = this.villamentList();
        if (!l)
          return;
        for (const o of [...l.children])
          o.hasAttribute("x-sortable-item") || o.remove();
        const n = this.villamentNestedTemplateHtml(`template[data-villament-repeater-separator="${t ? "full" : "addable"}"]`), r = this.villamentMeta()?.token ?? "";
        n && e.slice(0, -1).forEach((o) => {
          const m = this.villamentParse(E(n, [[r, o.getAttribute("x-sortable-item") ?? ""]]));
          m && l.insertBefore(m, o.nextSibling);
        });
      },
      /**
       * @param {HTMLElement} item
       * @param {string} operation
       * @returns {?HTMLElement}
       */
      villamentControl(e, t) {
        return [...e.querySelectorAll(`[${A}="${t}"]`)].find((l) => this.villamentItemOf(l) === e) ?? null;
      },
      /**
       * Stock's disabled icon button: `disabled`, `aria-disabled` and
       * `fi-disabled`, all three.
       *
       * @param {?HTMLElement} control
       * @param {boolean} isDisabled
       */
      villamentSetDisabled(e, t) {
        e && (t ? (e.setAttribute("disabled", "disabled"), e.setAttribute("aria-disabled", "true")) : (e.removeAttribute("disabled"), e.removeAttribute("aria-disabled")), e.classList.toggle("fi-disabled", t));
      },
      /**
       * `itemNumbers()`: the heading reads "<label> <n>".
       *
       * @param {HTMLElement} item
       * @param {number} position
       */
      villamentNumber(e, t) {
        const l = [...e.querySelectorAll(".fi-fo-repeater-item-header-label")].find((r) => this.villamentItemOf(r) === e);
        if (!l)
          return;
        const n = (l.textContent ?? "").trim().replace(/\s+\d+$/, "");
        l.textContent = `${n} ${t}`;
      },
      /**
       * Stock hides the clone button at `maxItems` (`isCloneable()`).
       *
       * @param {HTMLElement} item
       * @param {string} key
       * @param {TemplateMeta} meta
       * @param {boolean} isFull
       */
      villamentRefreshClone(e, t, l, n) {
        const r = this.villamentControl(e, "clone");
        if (n) {
          r?.closest("li")?.remove();
          return;
        }
        if (r)
          return;
        const m = this.villamentTemplatePart("li[x-sortable-item]")?.querySelector(`[${A}="clone"]`)?.closest("li"), f = [...e.querySelectorAll(P)].find((g) => this.villamentItemOf(g) === e);
        if (!m || !f)
          return;
        const d = this.villamentParse(E(m.outerHTML, [[l.token, t]])), p = this.villamentControl(e, "delete")?.closest("li") ?? null ?? f.querySelector(":scope > .fi-fo-repeater-item-header-collapsible-actions");
        d && f.insertBefore(d, p);
      },
      /**
       * Stock omits the add button at `maxItems` (`isAddable()`).
       *
       * @param {boolean} isFull
       */
      villamentRefreshAdd(e) {
        const t = this.$root.querySelector(T);
        if (e) {
          t?.remove();
          return;
        }
        if (t)
          return;
        const l = this.villamentNestedTemplateHtml("template[data-villament-repeater-add]"), n = l ? this.villamentParse(l) : null;
        n && this.$root.insertBefore(n, this.villamentChromeAnchor());
      },
      /**
       * Rebuilds stock's `getReorderingDescriptionHtml()`: one hidden
       * span per item that the move buttons' `aria-describedby` names.
       *
       * @param {HTMLElement[]} items
       * @param {TemplateMeta} meta
       */
      villamentRefreshDescriptions(e, t) {
        const l = [...this.$root.querySelectorAll(":scope > span[data-reorder-description]")], n = new Map(l.map((m) => [m.getAttribute("data-reorder-description"), m.getAttribute("data-reorder-label")])), o = this.villamentTemplatePart("span[data-reorder-description]")?.getAttribute("data-reorder-label") ?? "";
        l.forEach((m) => m.remove()), e.forEach((m, f) => {
          const d = m.getAttribute("x-sortable-item") ?? "", b = n.get(d) ?? o, p = document.createElement("span");
          p.className = "fi-sr-only", p.setAttribute("data-reorder-description", d), p.setAttribute("data-reorder-label", b), p.id = `${this.$root.id}-item-${s.hash(d)}`, p.textContent = t.positionMessage.replace(/:label|:position|:count/g, (g) => ({
            ":label": b,
            ":position": String(f + 1),
            ":count": String(e.length)
          })[g] ?? g), this.$root.appendChild(p);
        });
      },
      /**
       * The same live-region message stock's `reconcile()` sets.
       *
       * @param {string} key
       */
      villamentAnnounceMove(e) {
        const t = this.$root.querySelector(':scope > [x-ref="reorderStatus"]'), l = this.villamentItemElements().map((r) => r.getAttribute("x-sortable-item")), n = [...this.$root.querySelectorAll(":scope > span[data-reorder-description]")].find((r) => r.getAttribute("data-reorder-description") === e);
        !t || !v || !n || (t.textContent = v.replace(/:label|:position|:count/g, (r) => ({
          ":label": n.getAttribute("data-reorder-label") ?? "",
          ":position": String(l.indexOf(e) + 1),
          ":count": String(l.length)
        })[r] ?? r));
      },
      /**
       * Moving an element out from under the focus drops it; stock's
       * `reconcile()` puts it back on the same control, or the other
       * direction when that one is now disabled.
       *
       * @param {HTMLElement} item
       * @param {'up' | 'down'} direction
       */
      villamentRestoreFocus(e, t) {
        const l = this.villamentControl(e, t === "up" ? "moveUp" : "moveDown"), n = this.villamentControl(e, t === "up" ? "moveDown" : "moveUp");
        [l, n].find((o) => o && !o.hasAttribute("disabled") && !o.hasAttribute("data-reorder-hidden"))?.focus({ preventScroll: !0 });
      }
    };
  };
}
const x = new URL(import.meta.url).searchParams.get("base");
if (!x)
  throw new Error("[villament] client-repeater was loaded without its ?base= Filament repeater URL.");
const { default: j } = await import(
  /* @vite-ignore */
  x
), F = D(j);
export {
  F as clientRepeaterFormComponent,
  F as default
};
