function L(s, o, h, m = null) {
  if (m === null)
    return { ...s, [o]: h };
  if (!Object.hasOwn(s, m))
    return s;
  const c = {};
  for (const [u, g] of Object.entries(s))
    c[u] = g, u === m && (c[o] = h);
  return c;
}
function $(s, o) {
  const h = { ...s };
  return delete h[o], h;
}
function w(s, o, h) {
  return Object.hasOwn(s, o) ? { ...s, [h]: R(s[o]) } : s;
}
function x(s, o, h) {
  const m = Object.keys(s), c = m.indexOf(o), u = h === "up" ? c - 1 : c + 1;
  return c === -1 || u < 0 || u >= m.length ? s : ([m[c], m[u]] = [m[u], m[c]], O(s, m));
}
function O(s, o) {
  if (o.some((m) => !Object.hasOwn(s, m)))
    return s;
  const h = {};
  for (const m of o)
    h[m] = s[m];
  for (const [m, c] of Object.entries(s))
    Object.hasOwn(h, m) || (h[m] = c);
  return h;
}
function S(s, o, h, m = null) {
  const c = [];
  let u = "";
  return s.forEach(({ token: g, hash: t }, e) => {
    const l = e === 0 && m !== null ? m : o();
    e === 0 && (u = l), c.push([g, l], [t, h(l)]);
  }), { key: u, replacements: c };
}
function A(s, o) {
  return o.reduce((h, [m, c]) => h.split(m).join(c), s);
}
function M(s, o) {
  return JSON.parse(A(JSON.stringify(s), o));
}
function y(s, o) {
  return o == null || s < o;
}
function R(s) {
  return s === void 0 ? void 0 : JSON.parse(JSON.stringify(s));
}
function k(s) {
  return !s || Array.isArray(s) ? Array.isArray(s) ? { ...R(s) } : {} : R(s);
}
const _ = ":scope > template[data-villament-repeater-template]", E = "data-villament-repeater-action", U = ":scope > ul.fi-fo-repeater-items, :scope > ul.fi-fo-simple-repeater-items", C = ":scope > .fi-fo-repeater-add, :scope > .fi-fo-simple-repeater-add", H = ".fi-fo-repeater-item-header-end-actions, .fi-fo-simple-repeater-item-actions", N = ":scope > .fi-fo-repeater-item-content, :scope > .fi-fo-simple-repeater-item-content", T = "data-villament-repeater-pending", P = ":scope > template[data-villament-repeater-slot]", q = {
  generateKey: () => globalThis.crypto.randomUUID(),
  hash: (s) => typeof globalThis.jsMd5 == "function" ? globalThis.jsMd5(s) : s
};
function j(s, o = q) {
  return function(m) {
    const c = s(m), { statePath: u, message: g } = m;
    return {
      ...c,
      /** @type {?(event: MouseEvent) => void} */
      villamentClickListener: null,
      /** @type {?() => void} */
      villamentRemoveMessageHook: null,
      init() {
        c.init?.call(this), this.villamentClickListener = (t) => this.villamentHandleClick(t), this.$root.addEventListener("click", this.villamentClickListener, !0), this.villamentSyncRows(), this.villamentRemoveMessageHook = globalThis.Livewire?.interceptMessage?.(({ message: t, onSuccess: e }) => {
          t.component.id === this.$wire.$id && e(({ onMorphed: l }) => l(() => {
            this.$root.isConnected && this.villamentSyncRows();
          }));
        }) ?? null;
      },
      destroy() {
        c.destroy?.call(this), this.$root.removeEventListener("click", this.villamentClickListener, !0), this.villamentRemoveMessageHook?.();
      },
      /**
       * Drag reorder. Sortable has already moved the element; stock
       * would now call the server's `reorder` action.
       *
       * @param {CustomEvent & { target: any }} event
       */
      reorder(t) {
        if (!this.villamentMeta()?.actions?.reorder)
          return c.reorder?.call(this, t);
        if (t.target !== this.villamentList())
          return;
        const e = t.target.sortable?.toArray?.() ?? this.villamentItemElements().map((l) => l.getAttribute("x-sortable-item"));
        this.villamentWrite(O(this.villamentRead(), e)), this.villamentRefresh(), this.finish?.();
      },
      /**
       * @param {MouseEvent} event
       */
      villamentHandleClick(t) {
        const e = (
          /** @type {?HTMLElement} */
          t.target instanceof Element ? t.target.closest(`[${E}]`) : null
        );
        !e || !this.villamentOwns(e) || (t.preventDefault(), t.stopPropagation(), !(e.hasAttribute("disabled") || e.getAttribute("aria-disabled") === "true") && this.villamentRun(e.getAttribute(E), e));
      },
      /**
       * @param {?string} operation
       * @param {HTMLElement} control
       */
      villamentRun(t, e) {
        const n = this.villamentItemOf(e)?.getAttribute("x-sortable-item") ?? null;
        switch (t) {
          case "add":
            return this.villamentAdd(null);
          case "addBetween":
            return this.villamentAdd(this.villamentItemBefore(e)?.getAttribute("x-sortable-item") ?? null, !0);
          case "delete":
            return n && this.villamentDelete(n);
          case "clone":
            return n && this.villamentClone(n);
          case "moveUp":
            return n && this.villamentMove(n, "up", e);
          case "moveDown":
            return n && this.villamentMove(n, "down", e);
        }
      },
      /**
       * @param {?string} afterKey
       * @param {boolean} [isBetween]
       */
      villamentAdd(t, e = !1) {
        const l = this.villamentMeta(), n = this.villamentRead();
        if (!l || e && t === null || !y(Object.keys(n).length, l.maxItems))
          return;
        const { key: i, replacements: r } = S(l.tokens, o.generateKey, o.hash);
        this.villamentWrite(L(n, i, M(l.state, r), t));
        const a = this.villamentItemFromTemplate(r);
        return a && (this.villamentMarkRendered(a, i, l), this.villamentInsert(a, t)), this.villamentRefresh(), i;
      },
      /**
       * @param {string} key
       */
      villamentDelete(t) {
        this.villamentWrite($(this.villamentRead(), t)), this.villamentItemElement(t)?.remove(), this.villamentRefresh();
      },
      /**
       * @param {string} key
       */
      villamentClone(t) {
        const e = this.villamentMeta(), l = this.villamentRead();
        if (!e || !Object.hasOwn(l, t) || !y(Object.keys(l).length, e.maxItems))
          return;
        const { key: n, replacements: i } = S(e.tokens, o.generateKey, o.hash);
        this.villamentWrite(w(l, t, n));
        const r = this.villamentItemFromTemplate(i);
        return r && (this.villamentMarkRendered(r, n, e), this.villamentInsert(r, null)), this.villamentRefresh(), n;
      },
      /**
       * @param {string} key
       * @param {'up' | 'down'} direction
       * @param {?HTMLElement} [control]
       */
      villamentMove(t, e, l = null) {
        const n = this.villamentRead(), i = x(n, t, e);
        if (i === n)
          return;
        const r = l !== null && document.activeElement === l;
        this.villamentWrite(i);
        const a = this.villamentItemElement(t), d = this.villamentList();
        if (a && d) {
          const v = this.villamentItemElements(), p = v.indexOf(a);
          e === "up" ? d.insertBefore(a, v[p - 1]) : d.insertBefore(a, v[p + 1].nextSibling);
        }
        this.villamentRefresh(), this.villamentAnnounceMove(t), r && a && this.villamentRestoreFocus(a, e);
      },
      // ---- state ---------------------------------------------------
      /** @returns {Record<string, any>} */
      villamentRead() {
        return k(this.$wire.get(u));
      },
      /**
       * Deferred: no request now. The next commit — a save, a live
       * field, a `callSchemaComponentMethod()` from a searchable
       * select in the new row — carries it before its own call.
       *
       * @param {Record<string, any>} items
       */
      villamentWrite(t) {
        this.$wire.set(u, t, !1);
      },
      // ---- template ------------------------------------------------
      /** @returns {?HTMLTemplateElement} */
      villamentTemplate() {
        return this.$root.querySelector(_);
      },
      /** @returns {?TemplateMeta} */
      villamentMeta() {
        const t = this.villamentTemplatePart("script[data-villament-repeater-meta]");
        if (!t)
          return null;
        try {
          return JSON.parse(t.textContent ?? "");
        } catch {
          return null;
        }
      },
      /**
       * @param {string} selector
       * @returns {?Element}
       */
      villamentTemplatePart(t) {
        return [...this.villamentTemplate()?.content.children ?? []].find((e) => e.matches(t)) ?? null;
      },
      /**
       * @param {string} selector
       * @returns {?string}
       */
      villamentNestedTemplateHtml(t) {
        const e = (
          /** @type {?HTMLTemplateElement} */
          this.villamentTemplatePart(t)
        );
        return e ? e.innerHTML : null;
      },
      /**
       * @param {Array<[string, string]>} replacements
       * @returns {?Element}
       */
      villamentItemFromTemplate(t) {
        const e = this.villamentTemplatePart("li[x-sortable-item]");
        return e ? this.villamentParse(A(e.outerHTML, t)) : null;
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
      villamentParse(t) {
        const e = document.createElement("template");
        return e.innerHTML = t.trim(), e.content.firstElementChild;
      },
      // ---- DOM -----------------------------------------------------
      /**
       * @param {Element} element
       * @returns {boolean}
       */
      villamentOwns(t) {
        return t.closest("[data-reorder-owner]") === this.$root;
      },
      /** @returns {?HTMLElement} */
      villamentList() {
        return this.$root.querySelector(U);
      },
      /** @returns {HTMLElement[]} */
      villamentItemElements() {
        const t = this.villamentList();
        return t ? (
          /** @type {HTMLElement[]} */
          [...t.children].filter((e) => e.hasAttribute("x-sortable-item"))
        ) : [];
      },
      /**
       * @param {string} key
       * @returns {?HTMLElement}
       */
      villamentItemElement(t) {
        return this.villamentItemElements().find((e) => e.getAttribute("x-sortable-item") === t) ?? null;
      },
      /**
       * @param {Element} control
       * @returns {?HTMLElement}
       */
      villamentItemOf(t) {
        const e = this.villamentList();
        let l = t.closest("[x-sortable-item]");
        for (; l && l.parentElement !== e; )
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
      villamentItemBefore(t) {
        const e = this.villamentList();
        let l = t.closest("li");
        for (; l && l.parentElement !== e; )
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
      villamentInsert(t, e) {
        const l = this.villamentEnsureList();
        if (!l)
          return;
        const n = e === null ? null : this.villamentItemElement(e);
        n ? l.insertBefore(t, n.nextSibling) : l.appendChild(t);
      },
      /**
       * Stock renders no `<ul>` at all for an empty repeater.
       *
       * @returns {?HTMLElement}
       */
      villamentEnsureList() {
        const t = this.villamentList();
        if (t)
          return t;
        const e = this.villamentTemplatePart("ul");
        if (!e)
          return null;
        const l = (
          /** @type {HTMLElement} */
          e.cloneNode(!1)
        );
        return this.$root.insertBefore(l, this.villamentChromeAnchor(C)), l;
      },
      /**
       * Where stock's chrome after the list starts: the add button,
       * then the reordering descriptions.
       *
       * @param {string} [selector]
       * @returns {?Element}
       */
      villamentChromeAnchor(t = "") {
        return (t ? this.$root.querySelector(t) : null) ?? this.$root.querySelector(":scope > span[data-reorder-description]");
      },
      /**
       * Brings everything stock re-renders after a structural action
       * back in line with the item list: the empty-list case, the
       * separators, first/last move buttons, item numbers, the clone
       * and add buttons at `maxItems`, collapse/expand-all, and the
       * screen-reader position descriptions.
       */
      villamentRefresh() {
        const t = this.villamentMeta();
        if (!t)
          return;
        let e = this.villamentItemElements();
        const l = e.length, n = !y(l, t.maxItems);
        l === 0 && this.villamentList()?.remove(), this.villamentRefreshSeparators(e, n), e = this.villamentItemElements(), e.forEach((i, r) => {
          const a = i.getAttribute("x-sortable-item") ?? "";
          this.villamentSetDisabled(this.villamentControl(i, "moveUp"), r === 0), this.villamentSetDisabled(this.villamentControl(i, "moveDown"), r === l - 1), t.hasItemNumbers && this.villamentNumber(i, r + 1), t.actions.clone && this.villamentRefreshClone(i, a, t, n);
        }), t.actions.add && this.villamentRefreshAdd(n), this.$root.querySelector(":scope > .fi-fo-repeater-actions")?.classList.toggle("fi-hidden", l < 2), this.villamentRefreshDescriptions(e, t), this.$root.dataset.reorderItems = JSON.stringify(e.map((i) => i.getAttribute("x-sortable-item"))), this.updateHandles?.();
      },
      /**
       * @param {HTMLElement[]} items
       * @param {boolean} isFull
       */
      villamentRefreshSeparators(t, e) {
        const l = this.villamentList();
        if (!l)
          return;
        for (const r of [...l.children])
          r.hasAttribute("x-sortable-item") || r.remove();
        const n = this.villamentNestedTemplateHtml(`template[data-villament-repeater-separator="${e ? "full" : "addable"}"]`), i = this.villamentMeta()?.token ?? "";
        n && t.slice(0, -1).forEach((r) => {
          const a = this.villamentParse(A(n, [[i, r.getAttribute("x-sortable-item") ?? ""]]));
          a && l.insertBefore(a, r.nextSibling);
        });
      },
      /**
       * @param {HTMLElement} item
       * @param {string} operation
       * @returns {?HTMLElement}
       */
      villamentControl(t, e) {
        return [...t.querySelectorAll(`[${E}="${e}"]`)].find((l) => this.villamentItemOf(l) === t) ?? null;
      },
      /**
       * Stock's disabled icon button: `disabled`, `aria-disabled` and
       * `fi-disabled`, all three.
       *
       * @param {?HTMLElement} control
       * @param {boolean} isDisabled
       */
      villamentSetDisabled(t, e) {
        t && (e ? (t.setAttribute("disabled", "disabled"), t.setAttribute("aria-disabled", "true")) : (t.removeAttribute("disabled"), t.removeAttribute("aria-disabled")), t.classList.toggle("fi-disabled", e));
      },
      /**
       * `itemNumbers()`: the heading reads "<label> <n>".
       *
       * @param {HTMLElement} item
       * @param {number} position
       */
      villamentNumber(t, e) {
        const l = [...t.querySelectorAll(".fi-fo-repeater-item-header-label")].find((i) => this.villamentItemOf(i) === t);
        if (!l)
          return;
        const n = (l.textContent ?? "").trim().replace(/\s+\d+$/, "");
        l.textContent = `${n} ${e}`;
      },
      /**
       * Stock hides the clone button at `maxItems` (`isCloneable()`).
       *
       * @param {HTMLElement} item
       * @param {string} key
       * @param {TemplateMeta} meta
       * @param {boolean} isFull
       */
      villamentRefreshClone(t, e, l, n) {
        const i = this.villamentControl(t, "clone");
        if (n) {
          i?.closest("li")?.remove();
          return;
        }
        if (i)
          return;
        const a = this.villamentTemplatePart("li[x-sortable-item]")?.querySelector(`[${E}="clone"]`)?.closest("li"), d = [...t.querySelectorAll(H)].find((b) => this.villamentItemOf(b) === t);
        if (!a || !d)
          return;
        const v = this.villamentParse(A(a.outerHTML, [[l.token, e]])), f = this.villamentControl(t, "delete")?.closest("li") ?? null ?? d.querySelector(":scope > .fi-fo-repeater-item-header-collapsible-actions");
        v && d.insertBefore(v, f);
      },
      /**
       * Stock omits the add button at `maxItems` (`isAddable()`).
       *
       * @param {boolean} isFull
       */
      villamentRefreshAdd(t) {
        const e = this.$root.querySelector(C);
        if (t) {
          e?.remove();
          return;
        }
        if (e)
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
      villamentRefreshDescriptions(t, e) {
        const l = [...this.$root.querySelectorAll(":scope > span[data-reorder-description]")], n = new Map(l.map((a) => [a.getAttribute("data-reorder-description"), a.getAttribute("data-reorder-label")])), r = this.villamentTemplatePart("span[data-reorder-description]")?.getAttribute("data-reorder-label") ?? "";
        l.forEach((a) => a.remove()), t.forEach((a, d) => {
          const v = a.getAttribute("x-sortable-item") ?? "", p = n.get(v) ?? r, f = document.createElement("span");
          f.className = "fi-sr-only", f.setAttribute("data-reorder-description", v), f.setAttribute("data-reorder-label", p), f.id = `${this.$root.id}-item-${o.hash(v)}`, f.textContent = e.positionMessage.replace(/:label|:position|:count/g, (b) => ({
            ":label": p,
            ":position": String(d + 1),
            ":count": String(t.length)
          })[b] ?? b), this.$root.appendChild(f);
        });
      },
      /**
       * The same live-region message stock's `reconcile()` sets.
       *
       * @param {string} key
       */
      villamentAnnounceMove(t) {
        const e = this.$root.querySelector(':scope > [x-ref="reorderStatus"]'), l = this.villamentItemElements().map((i) => i.getAttribute("x-sortable-item")), n = [...this.$root.querySelectorAll(":scope > span[data-reorder-description]")].find((i) => i.getAttribute("data-reorder-description") === t);
        !e || !g || !n || (e.textContent = g.replace(/:label|:position|:count/g, (i) => ({
          ":label": n.getAttribute("data-reorder-label") ?? "",
          ":position": String(l.indexOf(t) + 1),
          ":count": String(l.length)
        })[i] ?? i));
      },
      // ---- client rendering ----------------------------------------
      /**
       * Brings every row's content in line with the server: fills the
       * rows the server sent as pending shells, places the server
       * units from each row's slot, and draws validation errors.
       */
      villamentSyncRows() {
        const t = this.villamentMeta();
        if (this.villamentReconcileOrder(), !t?.rendering)
          return;
        const e = this.villamentErrors();
        for (const l of this.villamentItemElements())
          this.villamentSyncRow(l, t, e);
      },
      /**
       * A drag that lands while a request is in flight keeps its new
       * order in the state (Livewire only patches what the server
       * changed), but the response's morph puts the rows back in the
       * order that request saw. Re-applies the state's order; the next
       * request carries it to the server.
       */
      villamentReconcileOrder() {
        const t = this.villamentList(), e = this.villamentItemElements(), l = e.map((r) => r.getAttribute("x-sortable-item")), n = Object.keys(this.villamentRead()), i = l.length === n.length && n.every((r) => l.includes(r));
        if (!(!t || !i || l.every((r, a) => r === n[a]))) {
          for (const r of n)
            t.appendChild(e[l.indexOf(r)]);
          this.villamentRefresh();
        }
      },
      /**
       * @param {HTMLElement} item
       * @param {TemplateMeta} meta
       * @param {Record<string, string[]>} errors
       */
      villamentSyncRow(t, e, l) {
        const n = t.getAttribute("x-sortable-item") ?? "", i = this.villamentContentOf(t);
        if (i) {
          if (i.hasAttribute(T))
            this.villamentFillContent(i, n, e), i.removeAttribute(T);
          else if (!i.__villamentAdopted) {
            this.villamentAdopt(t, i, n, e, l);
            return;
          }
          this.villamentApplySlot(t, i, n), this.villamentApplyErrors(i, n, e, l);
        }
      },
      /**
       * @param {HTMLElement} item
       * @returns {?HTMLElement}
       */
      villamentContentOf(t) {
        return t.querySelector(N);
      },
      /**
       * The template item's content, with this row's key. Nested
       * placeholder keys (in a server unit's provisional markup) get
       * fresh ones; the row's slot replaces those units anyway.
       *
       * @param {HTMLElement} content
       * @param {string} key
       * @param {TemplateMeta} meta
       */
      villamentFillContent(t, e, l) {
        const n = (
          /** @type {?HTMLElement} */
          this.villamentTemplatePart("li[x-sortable-item]")
        ), i = n ? this.villamentContentOf(n) : null;
        if (!i)
          return;
        const { replacements: r } = S(l.tokens, o.generateKey, o.hash, e);
        t.innerHTML = A(i.innerHTML, r), t.__villamentAdopted = !0, this.villamentRememberUnits(t, e, l);
      },
      /**
       * A row the browser built from the template: its content is
       * client-owned from the start, and its server units are the
       * template's provisional ones until the row's slot arrives.
       *
       * @param {HTMLElement} item
       * @param {string} key
       * @param {TemplateMeta} meta
       */
      villamentMarkRendered(t, e, l) {
        const n = this.villamentContentOf(t);
        !l.rendering || !n || (n.removeAttribute(T), n.__villamentAdopted = !0, this.villamentRememberUnits(n, e, l));
      },
      /**
       * @param {HTMLElement} item
       * @param {HTMLElement} content
       * @param {string} key
       * @param {TemplateMeta} meta
       * @param {Record<string, string[]>} errors
       */
      villamentAdopt(t, e, l, n, i) {
        e.__villamentAdopted = !0;
        for (const r of this.villamentSlotUnits(t)) {
          const a = this.villamentUnit(e, r.getAttribute("wire:partial"));
          a && (a.__villamentHtml = this.villamentNormalize(r.outerHTML, l));
        }
        for (const r of n.rendering?.fields ?? []) {
          const a = this.villamentUnit(e, this.villamentUnitKey(n, l, r.key));
          a && (a.__villamentError = this.villamentFirstError(i, `${u}.${l}.${r.path}`, r.nested) ?? "");
        }
      },
      /**
       * @param {HTMLElement} content
       * @param {string} key
       * @param {TemplateMeta} meta
       */
      villamentRememberUnits(t, e, l) {
        for (const n of l.rendering?.server ?? []) {
          const i = this.villamentUnit(t, this.villamentUnitKey(l, e, n));
          i && (i.__villamentHtml = this.villamentNormalize(i.outerHTML, e));
        }
      },
      /**
       * Replaces each server unit whose server markup changed. Units
       * are compared as server HTML (with the row key factored out),
       * never against the live DOM, which Alpine has since changed.
       *
       * @param {HTMLElement} item
       * @param {HTMLElement} content
       * @param {string} key
       */
      villamentApplySlot(t, e, l) {
        for (const n of this.villamentSlotUnits(t)) {
          const i = this.villamentUnit(e, n.getAttribute("wire:partial")), r = this.villamentNormalize(n.outerHTML, l);
          if (!i || i.__villamentHtml === r)
            continue;
          const a = (
            /** @type {?HTMLElement} */
            this.villamentParse(n.outerHTML)
          );
          a && (a.__villamentHtml = r, i.replaceWith(a));
        }
      },
      /**
       * Draws `$wire.$errors` on the client-rendered fields: a field
       * with an error swaps in the template's invalid variant (the
       * exact markup the server renders for an invalid field of that
       * type) with the real message; a field without one goes back to
       * the template's normal variant. Untouched while nothing changed.
       *
       * @param {HTMLElement} content
       * @param {string} key
       * @param {TemplateMeta} meta
       * @param {Record<string, string[]>} errors
       */
      villamentApplyErrors(t, e, l, n) {
        const i = l.rendering, r = this.villamentTemplatePart("li[x-sortable-item]");
        for (const a of i?.fields ?? []) {
          const d = this.villamentUnit(t, this.villamentUnitKey(l, e, a.key)), v = this.villamentFirstError(n, `${u}.${e}.${a.path}`, a.nested), p = v ?? "";
          if (!d || (d.__villamentError ?? "") === p)
            continue;
          let f = v === null ? r ? this.villamentUnit(r, this.villamentUnitKey(l, l.token, a.key))?.outerHTML : null : a.invalid?.split(i.errorSentinel).join(a.html ? v : F(v));
          if (!f)
            continue;
          f = A(f, [[l.token, e]]);
          const b = (
            /** @type {?HTMLElement} */
            this.villamentParse(f)
          );
          b && (b.__villamentError = p, d.replaceWith(b));
        }
      },
      /** @returns {Record<string, string[]>} */
      villamentErrors() {
        try {
          return this.$wire.$errors?.messages?.() ?? {};
        } catch {
          return {};
        }
      },
      /**
       * Stock's rule: the field's own path, or — for a field with
       * nested recursive rules (CheckboxList, TagsInput, KeyValue) —
       * the first message under `{path}.*`.
       *
       * @param {Record<string, string[]>} errors
       * @param {string} path
       * @param {boolean} nested
       * @returns {?string}
       */
      villamentFirstError(t, e, l) {
        const n = t[e];
        if (n?.length)
          return String(n[0]);
        if (!l)
          return null;
        const i = Object.keys(t).find((r) => r.startsWith(`${e}.`) && t[r]?.length);
        return i ? String(t[i][0]) : null;
      },
      /**
       * @param {HTMLElement} item
       * @returns {Element[]}
       */
      villamentSlotUnits(t) {
        const e = (
          /** @type {?HTMLTemplateElement} */
          t.querySelector(P)
        );
        return e ? [...e.content.children].filter((l) => l.hasAttribute("wire:partial")) : [];
      },
      /**
       * @param {TemplateMeta} meta
       * @param {string} key
       * @param {string} unit
       * @returns {string}
       */
      villamentUnitKey(t, e, l) {
        return `schema-component::${t.rendering?.key}.${e}.${l}`;
      },
      /**
       * The component wrapper Filament renders for `partial` — matched by
       * attribute value, so keys never need CSS escaping.
       *
       * @param {Element} root
       * @param {?string} partial
       * @returns {?HTMLElement}
       */
      villamentUnit(t, e) {
        return e ? (
          /** @type {?HTMLElement} */
          [...(t instanceof HTMLTemplateElement ? t.content : t).querySelectorAll("[wire\\:partial]")].find((l) => l.getAttribute("wire:partial") === e) ?? null
        ) : null;
      },
      /**
       * @param {string} html
       * @param {string} key
       * @returns {string}
       */
      villamentNormalize(t, e) {
        return t.split(e).join("{key}").replace(/\s+/g, " ");
      },
      /**
       * Moving an element out from under the focus drops it; stock's
       * `reconcile()` puts it back on the same control, or the other
       * direction when that one is now disabled.
       *
       * @param {HTMLElement} item
       * @param {'up' | 'down'} direction
       */
      villamentRestoreFocus(t, e) {
        const l = this.villamentControl(t, e === "up" ? "moveUp" : "moveDown"), n = this.villamentControl(t, e === "up" ? "moveDown" : "moveUp");
        [l, n].find((r) => r && !r.hasAttribute("disabled") && !r.hasAttribute("data-reorder-hidden"))?.focus({ preventScroll: !0 });
      }
    };
  };
}
function F(s) {
  return s.replaceAll("&", "&amp;").replaceAll("<", "&lt;").replaceAll(">", "&gt;").replaceAll('"', "&quot;").replaceAll("'", "&#039;");
}
const I = new URL(import.meta.url).searchParams.get("base");
if (!I)
  throw new Error("[villament] client-repeater was loaded without its ?base= Filament repeater URL.");
const { default: D } = await import(
  /* @vite-ignore */
  I
), B = j(D);
export {
  B as clientRepeaterFormComponent,
  B as default
};
