function q(a, o, u, m = null) {
  if (m === null)
    return { ...a, [o]: u };
  if (!Object.hasOwn(a, m))
    return a;
  const d = {};
  for (const [p, g] of Object.entries(a))
    d[p] = g, p === m && (d[o] = u);
  return d;
}
function U(a, o) {
  const u = { ...a };
  return delete u[o], u;
}
function P(a, o, u) {
  return Object.hasOwn(a, o) ? { ...a, [u]: T(a[o]) } : a;
}
function j(a, o, u) {
  const m = Object.keys(a), d = m.indexOf(o), p = u === "up" ? d - 1 : d + 1;
  return d === -1 || p < 0 || p >= m.length ? a : ([m[d], m[p]] = [m[p], m[d]], x(a, m));
}
function x(a, o) {
  if (o.some((m) => !Object.hasOwn(a, m)))
    return a;
  const u = {};
  for (const m of o)
    u[m] = a[m];
  for (const [m, d] of Object.entries(a))
    Object.hasOwn(u, m) || (u[m] = d);
  return u;
}
function R(a, o, u, m = null) {
  const d = [];
  let p = "";
  return a.forEach(({ token: g, hash: e }, t) => {
    const l = t === 0 && m !== null ? m : o();
    t === 0 && (p = l), d.push([g, l], [e, u(l)]);
  }), { key: p, replacements: d };
}
function A(a, o) {
  return o.reduce((u, [m, d]) => u.split(m).join(d), a);
}
function D(a, o) {
  return JSON.parse(A(JSON.stringify(a), o));
}
function y(a, o) {
  return o == null || a < o;
}
function T(a) {
  return a === void 0 ? void 0 : JSON.parse(JSON.stringify(a));
}
function F(a) {
  return !a || Array.isArray(a) ? Array.isArray(a) ? { ...T(a) } : {} : T(a);
}
const O = ":scope > template[data-villament-repeater-template]", S = "data-villament-repeater-action", B = ':scope > ul.fi-fo-repeater-items, :scope > ul.fi-fo-simple-repeater-items, :scope > table > tbody[x-ref="reorderItems"]', I = ":scope > .fi-fo-repeater-add, :scope > .fi-fo-simple-repeater-add, :scope > .fi-fo-table-repeater-add", W = ".fi-fo-repeater-item-header-end-actions, .fi-fo-simple-repeater-item-actions, .fi-fo-table-repeater-actions", w = "[data-reorder-direction], [x-sortable-handle]", J = ":scope > .fi-fo-repeater-item-content, :scope > .fi-fo-simple-repeater-item-content", E = "data-villament-repeater-pending", L = ":scope > template[data-villament-repeater-slot]", K = {
  generateKey: () => globalThis.crypto.randomUUID(),
  hash: (a) => typeof globalThis.jsMd5 == "function" ? globalThis.jsMd5(a) : a
};
function z(a, o = K) {
  return function(m) {
    const d = a(m), { statePath: p, message: g } = m;
    return {
      ...d,
      /** @type {?(event: MouseEvent) => void} */
      villamentClickListener: null,
      /** @type {?() => void} */
      villamentRemoveMessageHook: null,
      init() {
        d.init?.call(this), this.villamentClickListener = (e) => this.villamentHandleClick(e), this.$root.addEventListener("click", this.villamentClickListener, !0), this.villamentSyncRows(), this.villamentRemoveMessageHook = globalThis.Livewire?.interceptMessage?.(({ message: e, onSuccess: t }) => {
          e.component.id === this.$wire.$id && t(({ onMorphed: l }) => l(() => {
            this.$root.isConnected && this.villamentSyncRows();
          }));
        }) ?? null;
      },
      destroy() {
        d.destroy?.call(this), this.$root.removeEventListener("click", this.villamentClickListener, !0), this.villamentRemoveMessageHook?.();
      },
      /**
       * Drag reorder. Sortable has already moved the element; stock
       * would now call the server's `reorder` action.
       *
       * @param {CustomEvent & { target: any }} event
       */
      reorder(e) {
        if (!this.villamentMeta()?.actions?.reorder)
          return d.reorder?.call(this, e);
        if (e.target !== this.villamentList())
          return;
        const t = e.target.sortable?.toArray?.() ?? this.villamentItemElements().map((l) => l.getAttribute("x-sortable-item"));
        this.villamentWrite(x(this.villamentRead(), t)), this.villamentRefresh(), this.finish?.();
      },
      /**
       * @param {MouseEvent} event
       */
      villamentHandleClick(e) {
        const t = (
          /** @type {?HTMLElement} */
          e.target instanceof Element ? e.target.closest(`[${S}]`) : null
        );
        !t || !this.villamentOwns(t) || (e.preventDefault(), e.stopPropagation(), !(t.hasAttribute("disabled") || t.getAttribute("aria-disabled") === "true") && this.villamentRun(t.getAttribute(S), t));
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
        if (!l || t && e === null || !y(Object.keys(n).length, l.maxItems))
          return;
        const { key: i, replacements: r } = R(l.tokens, o.generateKey, o.hash);
        this.villamentWrite(q(n, i, D(l.state, r), e));
        const s = this.villamentItemFromTemplate(r);
        return s && (this.villamentMarkRendered(s, i, l), this.villamentInsert(s, e)), this.villamentRefresh(), i;
      },
      /**
       * @param {string} key
       */
      villamentDelete(e) {
        this.villamentWrite(U(this.villamentRead(), e)), this.villamentItemElement(e)?.remove(), this.villamentRefresh();
      },
      /**
       * @param {string} key
       */
      villamentClone(e) {
        const t = this.villamentMeta(), l = this.villamentRead();
        if (!t || !Object.hasOwn(l, e) || !y(Object.keys(l).length, t.maxItems))
          return;
        const { key: n, replacements: i } = R(t.tokens, o.generateKey, o.hash);
        this.villamentWrite(P(l, e, n));
        const r = this.villamentItemFromTemplate(i);
        return r && (this.villamentMarkRendered(r, n, t), this.villamentInsert(r, null)), this.villamentRefresh(), n;
      },
      /**
       * @param {string} key
       * @param {'up' | 'down'} direction
       * @param {?HTMLElement} [control]
       */
      villamentMove(e, t, l = null) {
        const n = this.villamentRead(), i = j(n, e, t);
        if (i === n)
          return;
        const r = l !== null && document.activeElement === l;
        this.villamentWrite(i);
        const s = this.villamentItemElement(e), c = this.villamentList();
        if (s && c) {
          const h = this.villamentItemElements(), v = h.indexOf(s);
          t === "up" ? c.insertBefore(s, h[v - 1]) : c.insertBefore(s, h[v + 1].nextSibling);
        }
        this.villamentRefresh(), this.villamentAnnounceMove(e), r && s && this.villamentRestoreFocus(s, t);
      },
      // ---- state ---------------------------------------------------
      /** @returns {Record<string, any>} */
      villamentRead() {
        return F(this.$wire.get(p));
      },
      /**
       * Deferred: no request now. The next commit — a save, a live
       * field, a `callSchemaComponentMethod()` from a searchable
       * select in the new row — carries it before its own call.
       *
       * @param {Record<string, any>} items
       */
      villamentWrite(e) {
        this.$wire.set(p, e, !1);
      },
      // ---- template ------------------------------------------------
      /**
       * This repeater's template: its own, or — for a repeater nested in
       * a row of another `ClientRepeater` — the copy the ancestor's
       * template carries, re-keyed for this row.
       *
       * @returns {?HTMLTemplateElement}
       */
      villamentTemplate() {
        return M(this.$root);
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
       * The template's item: an `<li>`, or — `table()` — the `<tr>` in
       * its own nested template (where the HTML parser keeps it).
       *
       * @returns {?HTMLElement}
       */
      villamentTemplateItem() {
        return _(this.villamentTemplate());
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
        const t = this.villamentTemplateItem();
        return t ? this.villamentParse(A(t.outerHTML, e)) : null;
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
        return this.$root.querySelector(B);
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
        const t = this.villamentTemplatePart("table"), l = t ?? this.villamentTemplatePart("ul");
        if (!l)
          return null;
        const n = (
          /** @type {HTMLElement} */
          l.cloneNode(t !== null)
        );
        return this.$root.insertBefore(n, this.villamentChromeAnchor(I)), t ? n.querySelector("tbody") : n;
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
        const l = t.length, n = !y(l, e.maxItems);
        if (l === 0) {
          const i = this.villamentList();
          (i?.closest("table") ?? i)?.remove();
        }
        this.villamentRefreshSeparators(t, n), t = this.villamentItemElements(), e.layout === "table" && e.reorderColumn && this.villamentRefreshReorderColumn(t, e), t.forEach((i, r) => {
          const s = i.getAttribute("x-sortable-item") ?? "";
          this.villamentSetDisabled(this.villamentControl(i, "moveUp"), r === 0), this.villamentSetDisabled(this.villamentControl(i, "moveDown"), r === l - 1), e.hasItemNumbers && this.villamentNumber(i, r + 1), e.actions.clone && this.villamentRefreshClone(i, s, e, n);
        }), e.actions.add && this.villamentRefreshAdd(n), this.$root.querySelector(":scope > .fi-fo-repeater-actions")?.classList.toggle("fi-hidden", l < 2), this.villamentRefreshDescriptions(t, e), this.$root.dataset.reorderItems = JSON.stringify(t.map((i) => i.getAttribute("x-sortable-item"))), this.updateHandles?.();
      },
      /**
       * @param {HTMLElement[]} items
       * @param {boolean} isFull
       */
      villamentRefreshSeparators(e, t) {
        const l = this.villamentList();
        if (!l)
          return;
        for (const r of [...l.children])
          r.hasAttribute("x-sortable-item") || r.remove();
        const n = this.villamentNestedTemplateHtml(`template[data-villament-repeater-separator="${t ? "full" : "addable"}"]`), i = this.villamentMeta()?.token ?? "";
        n && e.slice(0, -1).forEach((r) => {
          const s = this.villamentParse(A(n, [[i, r.getAttribute("x-sortable-item") ?? ""]]));
          s && l.insertBefore(s, r.nextSibling);
        });
      },
      /**
       * @param {HTMLElement} item
       * @param {string} operation
       * @returns {?HTMLElement}
       */
      villamentControl(e, t) {
        return [...e.querySelectorAll(`[${S}="${t}"]`)].find((l) => this.villamentItemOf(l) === e) ?? null;
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
        const l = [...e.querySelectorAll(".fi-fo-repeater-item-header-label")].find((i) => this.villamentItemOf(i) === e);
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
        const i = this.villamentControl(e, "clone");
        if (n) {
          i?.parentElement?.remove();
          return;
        }
        if (i)
          return;
        const s = this.villamentTemplateItem()?.querySelector(`[${S}="clone"]`)?.parentElement, c = [...e.querySelectorAll(W)].filter((b) => this.villamentItemOf(b) === e && !b.querySelector(w)).at(-1);
        if (!s || !c)
          return;
        const h = this.villamentParse(A(s.outerHTML, this.villamentRowReplacements(l, t))), f = this.villamentControl(e, "delete")?.parentElement ?? null ?? c.querySelector(":scope > .fi-fo-repeater-item-header-collapsible-actions");
        h && c.insertBefore(h, f);
      },
      /**
       * The placeholder key, and the md5 Filament derives a11y ids from,
       * both swapped for `key`: what a piece of the template needs to
       * belong to one row.
       *
       * @param {TemplateMeta} meta
       * @param {string} key
       * @returns {Array<[string, string]>}
       */
      villamentRowReplacements(e, t) {
        const l = e.tokens?.[0]?.hash;
        return l ? [[e.token, t], [l, o.hash(t)]] : [[e.token, t]];
      },
      /**
       * `table()` renders a leading reorder column — a header cell and
       * one cell per row — only while there are two rows or more
       * (`$hasReorderColumn`). Adds or drops it as stock's re-render
       * would, from the template (rendered with two rows).
       *
       * @param {HTMLElement[]} items
       * @param {TemplateMeta} meta
       */
      villamentRefreshReorderColumn(e, t) {
        const l = this.villamentList()?.closest("table"), n = this.villamentTemplatePart("table"), i = this.villamentTemplateItem(), r = l?.querySelector(":scope > thead > tr"), s = n?.querySelector(":scope > thead > tr");
        if (!r || !s || !i)
          return;
        const c = e.length > 1, h = r.children.length === s.children.length;
        c && !h ? r.insertBefore(s.firstElementChild.cloneNode(!0), r.firstElementChild) : !c && h && r.firstElementChild?.remove();
        const v = (f) => f?.tagName === "TD" && f.querySelector(`:scope > .fi-fo-table-repeater-actions :is(${w})`) !== null;
        for (const f of e) {
          const b = v(f.firstElementChild);
          if (c && !b) {
            const N = f.getAttribute("x-sortable-item") ?? "", C = this.villamentParse(A(i.firstElementChild.outerHTML, this.villamentRowReplacements(t, N)));
            C && f.insertBefore(C, f.firstElementChild);
          } else !c && b && f.firstElementChild.remove();
        }
      },
      /**
       * Stock omits the add button at `maxItems` (`isAddable()`).
       *
       * @param {boolean} isFull
       */
      villamentRefreshAdd(e) {
        const t = this.$root.querySelector(I);
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
        const l = [...this.$root.querySelectorAll(":scope > span[data-reorder-description]")], n = new Map(l.map((s) => [s.getAttribute("data-reorder-description"), s.getAttribute("data-reorder-label")])), r = this.villamentTemplatePart("span[data-reorder-description]")?.getAttribute("data-reorder-label") ?? "";
        l.forEach((s) => s.remove()), e.forEach((s, c) => {
          const h = s.getAttribute("x-sortable-item") ?? "", v = n.get(h) ?? r, f = document.createElement("span");
          f.className = "fi-sr-only", f.setAttribute("data-reorder-description", h), f.setAttribute("data-reorder-label", v), f.id = `${this.$root.id}-item-${o.hash(h)}`, f.textContent = t.positionMessage.replace(/:label|:position|:count/g, (b) => ({
            ":label": v,
            ":position": String(c + 1),
            ":count": String(e.length)
          })[b] ?? b), this.$root.appendChild(f);
        });
      },
      /**
       * The same live-region message stock's `reconcile()` sets.
       *
       * @param {string} key
       */
      villamentAnnounceMove(e) {
        const t = this.$root.querySelector(':scope > [x-ref="reorderStatus"]'), l = this.villamentItemElements().map((i) => i.getAttribute("x-sortable-item")), n = [...this.$root.querySelectorAll(":scope > span[data-reorder-description]")].find((i) => i.getAttribute("data-reorder-description") === e);
        !t || !g || !n || (t.textContent = g.replace(/:label|:position|:count/g, (i) => ({
          ":label": n.getAttribute("data-reorder-label") ?? "",
          ":position": String(l.indexOf(e) + 1),
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
        const e = this.villamentMeta();
        if (this.villamentReconcileOrder(), !e?.rendering)
          return;
        const t = this.villamentErrors();
        for (const l of this.villamentItemElements())
          this.villamentSyncRow(l, e, t);
      },
      /**
       * A drag that lands while a request is in flight keeps its new
       * order in the state (Livewire only patches what the server
       * changed), but the response's morph puts the rows back in the
       * order that request saw. Re-applies the state's order; the next
       * request carries it to the server.
       */
      villamentReconcileOrder() {
        const e = this.villamentList(), t = this.villamentItemElements(), l = t.map((r) => r.getAttribute("x-sortable-item")), n = Object.keys(this.villamentRead()), i = l.length === n.length && n.every((r) => l.includes(r));
        if (!(!e || !i || l.every((r, s) => r === n[s]))) {
          for (const r of n)
            e.appendChild(t[l.indexOf(r)]);
          this.villamentRefresh();
        }
      },
      /**
       * @param {HTMLElement} item
       * @param {TemplateMeta} meta
       * @param {Record<string, string[]>} errors
       */
      villamentSyncRow(e, t, l) {
        const n = e.getAttribute("x-sortable-item") ?? "", i = this.villamentContentOf(e);
        if (i) {
          if (this.villamentIsPending(i))
            this.villamentFillContent(i, n, t), i.removeAttribute(E);
          else if (!i.__villamentAdopted) {
            this.villamentAdopt(e, i, n, t, l);
            return;
          }
          this.villamentApplySlot(e, i, n), this.villamentApplyErrors(i, n, t, l);
        }
      },
      /**
       * @param {HTMLElement} item
       * @returns {?HTMLElement}
       */
      villamentContentOf(e) {
        return e.tagName === "TR" ? e : e.querySelector(J);
      },
      /**
       * @param {HTMLElement} content
       * @returns {boolean}
       */
      villamentIsPending(e) {
        return e.tagName === "TR" ? e.querySelector(`:scope > td[${E}]`) !== null : e.hasAttribute(E);
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
      villamentFillContent(e, t, l) {
        const n = (
          /** @type {?HTMLElement} */
          this.villamentTemplateItem()
        ), i = n ? this.villamentContentOf(n) : null;
        if (!i)
          return;
        const { replacements: r } = R(l.tokens, o.generateKey, o.hash, t);
        if (e.tagName === "TR") {
          const { start: s, count: c } = l.rendering?.cells ?? { start: 0, count: 0 }, h = [...i.children].filter((v) => v.tagName === "TD").slice(s, s + c);
          [...e.querySelectorAll(`:scope > td[${E}]`)].forEach((v, f) => {
            const b = h[f] ? this.villamentParse(A(h[f].outerHTML, r)) : null;
            b && v.replaceWith(b);
          });
        } else
          e.innerHTML = A(i.innerHTML, r);
        e.__villamentAdopted = !0, this.villamentRememberUnits(e, t, l);
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
      villamentMarkRendered(e, t, l) {
        const n = this.villamentContentOf(e);
        !l.rendering || !n || (n.removeAttribute(E), n.__villamentAdopted = !0, this.villamentRememberUnits(n, t, l));
      },
      /**
       * @param {HTMLElement} item
       * @param {HTMLElement} content
       * @param {string} key
       * @param {TemplateMeta} meta
       * @param {Record<string, string[]>} errors
       */
      villamentAdopt(e, t, l, n, i) {
        t.__villamentAdopted = !0;
        for (const [r, s] of Object.entries(this.villamentSlotHashes(e))) {
          const c = this.villamentUnit(t, r);
          c && (c.__villamentHash = s);
        }
        for (const r of n.rendering?.fields ?? []) {
          const s = this.villamentUnit(t, this.villamentUnitKey(n, l, r.key));
          s && (s.__villamentError = this.villamentFirstError(i, `${p}.${l}.${r.path}`, r.nested) ?? "");
        }
      },
      /**
       * @param {HTMLElement} content
       * @param {string} key
       * @param {TemplateMeta} meta
       */
      villamentRememberUnits(e, t, l) {
        for (const n of l.rendering?.server ?? []) {
          const i = this.villamentUnit(e, this.villamentUnitKey(l, t, n));
          i && (i.__villamentHash = l.rendering?.unitHashes?.[n] ?? null);
        }
      },
      /**
       * Replaces each server unit whose server markup changed. Units
       * are compared by the hash the server computed from its own
       * markup (row key factored out), never against the live DOM,
       * which Alpine has since changed.
       *
       * @param {HTMLElement} item
       * @param {HTMLElement} content
       * @param {string} key
       */
      villamentApplySlot(e, t, l) {
        const n = this.villamentSlotUnits(e);
        for (const [i, r] of Object.entries(this.villamentSlotHashes(e))) {
          const s = this.villamentUnit(t, i), c = n.find((v) => v.getAttribute("wire:partial") === i);
          if (!s || !c || s.__villamentHash === r)
            continue;
          const h = (
            /** @type {?HTMLElement} */
            this.villamentParse(c.outerHTML)
          );
          h && (h.__villamentHash = r, s.replaceWith(h));
        }
      },
      /**
       * @param {HTMLElement} item
       * @returns {Record<string, string>}
       */
      villamentSlotHashes(e) {
        const t = e.querySelector(L);
        try {
          return JSON.parse(t?.getAttribute("data-villament-repeater-hashes") ?? "{}");
        } catch {
          return {};
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
      villamentApplyErrors(e, t, l, n) {
        const i = l.rendering, r = this.villamentTemplateItem();
        for (const s of i?.fields ?? []) {
          const c = this.villamentUnit(e, this.villamentUnitKey(l, t, s.key)), h = this.villamentFirstError(n, `${p}.${t}.${s.path}`, s.nested), v = h ?? "";
          if (!c || (c.__villamentError ?? "") === v)
            continue;
          let f = h === null ? r ? this.villamentUnit(r, this.villamentUnitKey(l, l.token, s.key))?.outerHTML : null : s.invalid?.split(i.errorSentinel).join(s.html ? h : G(h));
          if (!f)
            continue;
          f = A(f, [[l.token, t]]);
          const b = (
            /** @type {?HTMLElement} */
            this.villamentParse(f)
          );
          b && (b.__villamentError = v, c.replaceWith(b));
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
      villamentFirstError(e, t, l) {
        const n = e[t];
        if (n?.length)
          return String(n[0]);
        if (!l)
          return null;
        const i = Object.keys(e).find((r) => r.startsWith(`${t}.`) && e[r]?.length);
        return i ? String(e[i][0]) : null;
      },
      /**
       * @param {HTMLElement} item
       * @returns {Element[]}
       */
      villamentSlotUnits(e) {
        const t = (
          /** @type {?HTMLTemplateElement} */
          e.querySelector(L)
        );
        return t ? [...t.content.children].filter((l) => l.hasAttribute("wire:partial")) : [];
      },
      /**
       * @param {TemplateMeta} meta
       * @param {string} key
       * @param {string} unit
       * @returns {string}
       */
      villamentUnitKey(e, t, l) {
        return `schema-component::${e.rendering?.key}.${t}.${l}`;
      },
      /**
       * The component wrapper Filament renders for `partial` — matched by
       * attribute value, so keys never need CSS escaping.
       *
       * @param {Element} root
       * @param {?string} partial
       * @returns {?HTMLElement}
       */
      villamentUnit(e, t) {
        return t ? (
          /** @type {?HTMLElement} */
          [...(e instanceof HTMLTemplateElement ? e.content : e).querySelectorAll("[wire\\:partial]")].find((l) => l.getAttribute("wire:partial") === t) ?? null
        ) : null;
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
        [l, n].find((r) => r && !r.hasAttribute("disabled") && !r.hasAttribute("data-reorder-hidden"))?.focus({ preventScroll: !0 });
      }
    };
  };
}
function G(a) {
  return a.replaceAll("&", "&amp;").replaceAll("<", "&lt;").replaceAll(">", "&gt;").replaceAll('"', "&quot;").replaceAll("'", "&#039;");
}
const $ = /* @__PURE__ */ new WeakMap();
function M(a, o = 0) {
  const u = (
    /** @type {?HTMLTemplateElement} */
    a.querySelector(O)
  ), m = u?.getAttribute("data-villament-repeater-template-ref");
  if (!u || !m)
    return u;
  if (o > 32)
    return null;
  let d;
  try {
    d = JSON.parse(m);
  } catch {
    return null;
  }
  let p = a.parentElement?.closest("[data-reorder-owner]") ?? null;
  for (; p && p.id !== d.ancestor; )
    p = p.parentElement?.closest("[data-reorder-owner]") ?? null;
  const g = p ? M(p, o + 1) : null;
  if (!g)
    return null;
  const e = $.get(u);
  if (e?.ancestorTemplate === g)
    return e.template;
  const t = k(g);
  if (!t)
    return null;
  const l = a.id.split(d.item).join(t.token), i = (
    /** @type {?HTMLTemplateElement} */
    [..._(g)?.querySelectorAll("[data-reorder-owner]") ?? []].find((h) => h.id === l)?.querySelector(O) ?? null
  );
  if (!i)
    return null;
  const r = document.createElement("template");
  r.innerHTML = i.innerHTML.split(t.token).join(d.item);
  const s = [...r.content.children].find((h) => h.matches("script[data-villament-repeater-meta]")), c = k(r);
  return s && c && (s.textContent = JSON.stringify({ ...c, ...d.meta })), $.set(u, { ancestorTemplate: g, template: r }), r;
}
function k(a) {
  const o = [...a?.content.children ?? []].find((u) => u.matches("script[data-villament-repeater-meta]"));
  if (!o)
    return null;
  try {
    return JSON.parse(o.textContent ?? "");
  } catch {
    return null;
  }
}
function _(a) {
  for (const o of a?.content.children ?? []) {
    if (o.matches("[x-sortable-item]"))
      return (
        /** @type {HTMLElement} */
        o
      );
    if (o.matches("template[data-villament-repeater-row]"))
      return (
        /** @type {?HTMLElement} */
        /** @type {HTMLTemplateElement} */
        o.content.firstElementChild
      );
  }
  return null;
}
const H = new URL(import.meta.url).searchParams.get("base");
if (!H)
  throw new Error("[villament] client-repeater was loaded without its ?base= Filament repeater URL.");
const { default: Q } = await import(
  /* @vite-ignore */
  H
), V = z(Q);
export {
  V as clientRepeaterFormComponent,
  V as default
};
