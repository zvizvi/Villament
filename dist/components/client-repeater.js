function M(s, o, c, m = null) {
  if (m === null)
    return { ...s, [o]: c };
  if (!Object.hasOwn(s, m))
    return s;
  const u = {};
  for (const [d, p] of Object.entries(s))
    u[d] = p, d === m && (u[o] = c);
  return u;
}
function _(s, o) {
  const c = { ...s };
  return delete c[o], c;
}
function H(s, o, c) {
  return Object.hasOwn(s, o) ? { ...s, [c]: R(s[o]) } : s;
}
function U(s, o, c) {
  const m = Object.keys(s), u = m.indexOf(o), d = c === "up" ? u - 1 : u + 1;
  return u === -1 || d < 0 || d >= m.length ? s : ([m[u], m[d]] = [m[d], m[u]], $(s, m));
}
function $(s, o) {
  if (o.some((m) => !Object.hasOwn(s, m)))
    return s;
  const c = {};
  for (const m of o)
    c[m] = s[m];
  for (const [m, u] of Object.entries(s))
    Object.hasOwn(c, m) || (c[m] = u);
  return c;
}
function E(s, o, c, m = null) {
  const u = [];
  let d = "";
  return s.forEach(({ token: p, hash: e }, t) => {
    const n = t === 0 && m !== null ? m : o();
    t === 0 && (d = n), u.push([p, n], [e, c(n)]);
  }), { key: d, replacements: u };
}
function A(s, o) {
  return o.reduce((c, [m, u]) => c.split(m).join(u), s);
}
function N(s, o) {
  return JSON.parse(A(JSON.stringify(s), o));
}
function y(s, o) {
  return o == null || s < o;
}
function R(s) {
  return s === void 0 ? void 0 : JSON.parse(JSON.stringify(s));
}
function q(s) {
  return !s || Array.isArray(s) ? Array.isArray(s) ? { ...R(s) } : {} : R(s);
}
const C = ":scope > template[data-villament-repeater-template]", S = "data-villament-repeater-action", P = ":scope > ul.fi-fo-repeater-items, :scope > ul.fi-fo-simple-repeater-items", O = ":scope > .fi-fo-repeater-add, :scope > .fi-fo-simple-repeater-add", j = ".fi-fo-repeater-item-header-end-actions, .fi-fo-simple-repeater-item-actions", F = ":scope > .fi-fo-repeater-item-content, :scope > .fi-fo-simple-repeater-item-content", T = "data-villament-repeater-pending", w = ":scope > template[data-villament-repeater-slot]", D = {
  generateKey: () => globalThis.crypto.randomUUID(),
  hash: (s) => typeof globalThis.jsMd5 == "function" ? globalThis.jsMd5(s) : s
};
function B(s, o = D) {
  return function(m) {
    const u = s(m), { statePath: d, message: p } = m;
    return {
      ...u,
      /** @type {?(event: MouseEvent) => void} */
      villamentClickListener: null,
      /** @type {?() => void} */
      villamentRemoveMessageHook: null,
      init() {
        u.init?.call(this), this.villamentClickListener = (e) => this.villamentHandleClick(e), this.$root.addEventListener("click", this.villamentClickListener, !0), this.villamentSyncRows(), this.villamentRemoveMessageHook = globalThis.Livewire?.interceptMessage?.(({ message: e, onSuccess: t }) => {
          e.component.id === this.$wire.$id && t(({ onMorphed: n }) => n(() => {
            this.$root.isConnected && this.villamentSyncRows();
          }));
        }) ?? null;
      },
      destroy() {
        u.destroy?.call(this), this.$root.removeEventListener("click", this.villamentClickListener, !0), this.villamentRemoveMessageHook?.();
      },
      /**
       * Drag reorder. Sortable has already moved the element; stock
       * would now call the server's `reorder` action.
       *
       * @param {CustomEvent & { target: any }} event
       */
      reorder(e) {
        if (!this.villamentMeta()?.actions?.reorder)
          return u.reorder?.call(this, e);
        if (e.target !== this.villamentList())
          return;
        const t = e.target.sortable?.toArray?.() ?? this.villamentItemElements().map((n) => n.getAttribute("x-sortable-item"));
        this.villamentWrite($(this.villamentRead(), t)), this.villamentRefresh(), this.finish?.();
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
        const l = this.villamentItemOf(t)?.getAttribute("x-sortable-item") ?? null;
        switch (e) {
          case "add":
            return this.villamentAdd(null);
          case "addBetween":
            return this.villamentAdd(this.villamentItemBefore(t)?.getAttribute("x-sortable-item") ?? null, !0);
          case "delete":
            return l && this.villamentDelete(l);
          case "clone":
            return l && this.villamentClone(l);
          case "moveUp":
            return l && this.villamentMove(l, "up", t);
          case "moveDown":
            return l && this.villamentMove(l, "down", t);
        }
      },
      /**
       * @param {?string} afterKey
       * @param {boolean} [isBetween]
       */
      villamentAdd(e, t = !1) {
        const n = this.villamentMeta(), l = this.villamentRead();
        if (!n || t && e === null || !y(Object.keys(l).length, n.maxItems))
          return;
        const { key: i, replacements: r } = E(n.tokens, o.generateKey, o.hash);
        this.villamentWrite(M(l, i, N(n.state, r), e));
        const a = this.villamentItemFromTemplate(r);
        return a && (this.villamentMarkRendered(a, i, n), this.villamentInsert(a, e)), this.villamentRefresh(), i;
      },
      /**
       * @param {string} key
       */
      villamentDelete(e) {
        this.villamentWrite(_(this.villamentRead(), e)), this.villamentItemElement(e)?.remove(), this.villamentRefresh();
      },
      /**
       * @param {string} key
       */
      villamentClone(e) {
        const t = this.villamentMeta(), n = this.villamentRead();
        if (!t || !Object.hasOwn(n, e) || !y(Object.keys(n).length, t.maxItems))
          return;
        const { key: l, replacements: i } = E(t.tokens, o.generateKey, o.hash);
        this.villamentWrite(H(n, e, l));
        const r = this.villamentItemFromTemplate(i);
        return r && (this.villamentMarkRendered(r, l, t), this.villamentInsert(r, null)), this.villamentRefresh(), l;
      },
      /**
       * @param {string} key
       * @param {'up' | 'down'} direction
       * @param {?HTMLElement} [control]
       */
      villamentMove(e, t, n = null) {
        const l = this.villamentRead(), i = U(l, e, t);
        if (i === l)
          return;
        const r = n !== null && document.activeElement === n;
        this.villamentWrite(i);
        const a = this.villamentItemElement(e), v = this.villamentList();
        if (a && v) {
          const h = this.villamentItemElements(), b = h.indexOf(a);
          t === "up" ? v.insertBefore(a, h[b - 1]) : v.insertBefore(a, h[b + 1].nextSibling);
        }
        this.villamentRefresh(), this.villamentAnnounceMove(e), r && a && this.villamentRestoreFocus(a, t);
      },
      // ---- state ---------------------------------------------------
      /** @returns {Record<string, any>} */
      villamentRead() {
        return q(this.$wire.get(d));
      },
      /**
       * Deferred: no request now. The next commit — a save, a live
       * field, a `callSchemaComponentMethod()` from a searchable
       * select in the new row — carries it before its own call.
       *
       * @param {Record<string, any>} items
       */
      villamentWrite(e) {
        this.$wire.set(d, e, !1);
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
        return x(this.$root);
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
        return this.$root.querySelector(P);
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
        let n = e.closest("[x-sortable-item]");
        for (; n && n.parentElement !== t; )
          n = n.parentElement?.closest("[x-sortable-item]") ?? null;
        return (
          /** @type {?HTMLElement} */
          n
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
        let n = e.closest("li");
        for (; n && n.parentElement !== t; )
          n = n.parentElement?.closest("li") ?? null;
        let l = n?.previousElementSibling ?? null;
        for (; l && !l.hasAttribute("x-sortable-item"); )
          l = l.previousElementSibling;
        return l;
      },
      /**
       * @param {Element} element
       * @param {?string} afterKey
       */
      villamentInsert(e, t) {
        const n = this.villamentEnsureList();
        if (!n)
          return;
        const l = t === null ? null : this.villamentItemElement(t);
        l ? n.insertBefore(e, l.nextSibling) : n.appendChild(e);
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
        const n = (
          /** @type {HTMLElement} */
          t.cloneNode(!1)
        );
        return this.$root.insertBefore(n, this.villamentChromeAnchor(O)), n;
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
        const n = t.length, l = !y(n, e.maxItems);
        n === 0 && this.villamentList()?.remove(), this.villamentRefreshSeparators(t, l), t = this.villamentItemElements(), t.forEach((i, r) => {
          const a = i.getAttribute("x-sortable-item") ?? "";
          this.villamentSetDisabled(this.villamentControl(i, "moveUp"), r === 0), this.villamentSetDisabled(this.villamentControl(i, "moveDown"), r === n - 1), e.hasItemNumbers && this.villamentNumber(i, r + 1), e.actions.clone && this.villamentRefreshClone(i, a, e, l);
        }), e.actions.add && this.villamentRefreshAdd(l), this.$root.querySelector(":scope > .fi-fo-repeater-actions")?.classList.toggle("fi-hidden", n < 2), this.villamentRefreshDescriptions(t, e), this.$root.dataset.reorderItems = JSON.stringify(t.map((i) => i.getAttribute("x-sortable-item"))), this.updateHandles?.();
      },
      /**
       * @param {HTMLElement[]} items
       * @param {boolean} isFull
       */
      villamentRefreshSeparators(e, t) {
        const n = this.villamentList();
        if (!n)
          return;
        for (const r of [...n.children])
          r.hasAttribute("x-sortable-item") || r.remove();
        const l = this.villamentNestedTemplateHtml(`template[data-villament-repeater-separator="${t ? "full" : "addable"}"]`), i = this.villamentMeta()?.token ?? "";
        l && e.slice(0, -1).forEach((r) => {
          const a = this.villamentParse(A(l, [[i, r.getAttribute("x-sortable-item") ?? ""]]));
          a && n.insertBefore(a, r.nextSibling);
        });
      },
      /**
       * @param {HTMLElement} item
       * @param {string} operation
       * @returns {?HTMLElement}
       */
      villamentControl(e, t) {
        return [...e.querySelectorAll(`[${S}="${t}"]`)].find((n) => this.villamentItemOf(n) === e) ?? null;
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
        const n = [...e.querySelectorAll(".fi-fo-repeater-item-header-label")].find((i) => this.villamentItemOf(i) === e);
        if (!n)
          return;
        const l = (n.textContent ?? "").trim().replace(/\s+\d+$/, "");
        n.textContent = `${l} ${t}`;
      },
      /**
       * Stock hides the clone button at `maxItems` (`isCloneable()`).
       *
       * @param {HTMLElement} item
       * @param {string} key
       * @param {TemplateMeta} meta
       * @param {boolean} isFull
       */
      villamentRefreshClone(e, t, n, l) {
        const i = this.villamentControl(e, "clone");
        if (l) {
          i?.closest("li")?.remove();
          return;
        }
        if (i)
          return;
        const a = this.villamentTemplatePart("li[x-sortable-item]")?.querySelector(`[${S}="clone"]`)?.closest("li"), v = [...e.querySelectorAll(j)].find((g) => this.villamentItemOf(g) === e);
        if (!a || !v)
          return;
        const h = this.villamentParse(A(a.outerHTML, [[n.token, t]])), f = this.villamentControl(e, "delete")?.closest("li") ?? null ?? v.querySelector(":scope > .fi-fo-repeater-item-header-collapsible-actions");
        h && v.insertBefore(h, f);
      },
      /**
       * Stock omits the add button at `maxItems` (`isAddable()`).
       *
       * @param {boolean} isFull
       */
      villamentRefreshAdd(e) {
        const t = this.$root.querySelector(O);
        if (e) {
          t?.remove();
          return;
        }
        if (t)
          return;
        const n = this.villamentNestedTemplateHtml("template[data-villament-repeater-add]"), l = n ? this.villamentParse(n) : null;
        l && this.$root.insertBefore(l, this.villamentChromeAnchor());
      },
      /**
       * Rebuilds stock's `getReorderingDescriptionHtml()`: one hidden
       * span per item that the move buttons' `aria-describedby` names.
       *
       * @param {HTMLElement[]} items
       * @param {TemplateMeta} meta
       */
      villamentRefreshDescriptions(e, t) {
        const n = [...this.$root.querySelectorAll(":scope > span[data-reorder-description]")], l = new Map(n.map((a) => [a.getAttribute("data-reorder-description"), a.getAttribute("data-reorder-label")])), r = this.villamentTemplatePart("span[data-reorder-description]")?.getAttribute("data-reorder-label") ?? "";
        n.forEach((a) => a.remove()), e.forEach((a, v) => {
          const h = a.getAttribute("x-sortable-item") ?? "", b = l.get(h) ?? r, f = document.createElement("span");
          f.className = "fi-sr-only", f.setAttribute("data-reorder-description", h), f.setAttribute("data-reorder-label", b), f.id = `${this.$root.id}-item-${o.hash(h)}`, f.textContent = t.positionMessage.replace(/:label|:position|:count/g, (g) => ({
            ":label": b,
            ":position": String(v + 1),
            ":count": String(e.length)
          })[g] ?? g), this.$root.appendChild(f);
        });
      },
      /**
       * The same live-region message stock's `reconcile()` sets.
       *
       * @param {string} key
       */
      villamentAnnounceMove(e) {
        const t = this.$root.querySelector(':scope > [x-ref="reorderStatus"]'), n = this.villamentItemElements().map((i) => i.getAttribute("x-sortable-item")), l = [...this.$root.querySelectorAll(":scope > span[data-reorder-description]")].find((i) => i.getAttribute("data-reorder-description") === e);
        !t || !p || !l || (t.textContent = p.replace(/:label|:position|:count/g, (i) => ({
          ":label": l.getAttribute("data-reorder-label") ?? "",
          ":position": String(n.indexOf(e) + 1),
          ":count": String(n.length)
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
        for (const n of this.villamentItemElements())
          this.villamentSyncRow(n, e, t);
      },
      /**
       * A drag that lands while a request is in flight keeps its new
       * order in the state (Livewire only patches what the server
       * changed), but the response's morph puts the rows back in the
       * order that request saw. Re-applies the state's order; the next
       * request carries it to the server.
       */
      villamentReconcileOrder() {
        const e = this.villamentList(), t = this.villamentItemElements(), n = t.map((r) => r.getAttribute("x-sortable-item")), l = Object.keys(this.villamentRead()), i = n.length === l.length && l.every((r) => n.includes(r));
        if (!(!e || !i || n.every((r, a) => r === l[a]))) {
          for (const r of l)
            e.appendChild(t[n.indexOf(r)]);
          this.villamentRefresh();
        }
      },
      /**
       * @param {HTMLElement} item
       * @param {TemplateMeta} meta
       * @param {Record<string, string[]>} errors
       */
      villamentSyncRow(e, t, n) {
        const l = e.getAttribute("x-sortable-item") ?? "", i = this.villamentContentOf(e);
        if (i) {
          if (i.hasAttribute(T))
            this.villamentFillContent(i, l, t), i.removeAttribute(T);
          else if (!i.__villamentAdopted) {
            this.villamentAdopt(e, i, l, t, n);
            return;
          }
          this.villamentApplySlot(e, i, l), this.villamentApplyErrors(i, l, t, n);
        }
      },
      /**
       * @param {HTMLElement} item
       * @returns {?HTMLElement}
       */
      villamentContentOf(e) {
        return e.querySelector(F);
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
      villamentFillContent(e, t, n) {
        const l = (
          /** @type {?HTMLElement} */
          this.villamentTemplatePart("li[x-sortable-item]")
        ), i = l ? this.villamentContentOf(l) : null;
        if (!i)
          return;
        const { replacements: r } = E(n.tokens, o.generateKey, o.hash, t);
        e.innerHTML = A(i.innerHTML, r), e.__villamentAdopted = !0, this.villamentRememberUnits(e, t, n);
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
      villamentMarkRendered(e, t, n) {
        const l = this.villamentContentOf(e);
        !n.rendering || !l || (l.removeAttribute(T), l.__villamentAdopted = !0, this.villamentRememberUnits(l, t, n));
      },
      /**
       * @param {HTMLElement} item
       * @param {HTMLElement} content
       * @param {string} key
       * @param {TemplateMeta} meta
       * @param {Record<string, string[]>} errors
       */
      villamentAdopt(e, t, n, l, i) {
        t.__villamentAdopted = !0;
        for (const [r, a] of Object.entries(this.villamentSlotHashes(e))) {
          const v = this.villamentUnit(t, r);
          v && (v.__villamentHash = a);
        }
        for (const r of l.rendering?.fields ?? []) {
          const a = this.villamentUnit(t, this.villamentUnitKey(l, n, r.key));
          a && (a.__villamentError = this.villamentFirstError(i, `${d}.${n}.${r.path}`, r.nested) ?? "");
        }
      },
      /**
       * @param {HTMLElement} content
       * @param {string} key
       * @param {TemplateMeta} meta
       */
      villamentRememberUnits(e, t, n) {
        for (const l of n.rendering?.server ?? []) {
          const i = this.villamentUnit(e, this.villamentUnitKey(n, t, l));
          i && (i.__villamentHash = n.rendering?.unitHashes?.[l] ?? null);
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
      villamentApplySlot(e, t, n) {
        const l = this.villamentSlotUnits(e);
        for (const [i, r] of Object.entries(this.villamentSlotHashes(e))) {
          const a = this.villamentUnit(t, i), v = l.find((b) => b.getAttribute("wire:partial") === i);
          if (!a || !v || a.__villamentHash === r)
            continue;
          const h = (
            /** @type {?HTMLElement} */
            this.villamentParse(v.outerHTML)
          );
          h && (h.__villamentHash = r, a.replaceWith(h));
        }
      },
      /**
       * @param {HTMLElement} item
       * @returns {Record<string, string>}
       */
      villamentSlotHashes(e) {
        const t = e.querySelector(w);
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
      villamentApplyErrors(e, t, n, l) {
        const i = n.rendering, r = this.villamentTemplatePart("li[x-sortable-item]");
        for (const a of i?.fields ?? []) {
          const v = this.villamentUnit(e, this.villamentUnitKey(n, t, a.key)), h = this.villamentFirstError(l, `${d}.${t}.${a.path}`, a.nested), b = h ?? "";
          if (!v || (v.__villamentError ?? "") === b)
            continue;
          let f = h === null ? r ? this.villamentUnit(r, this.villamentUnitKey(n, n.token, a.key))?.outerHTML : null : a.invalid?.split(i.errorSentinel).join(a.html ? h : J(h));
          if (!f)
            continue;
          f = A(f, [[n.token, t]]);
          const g = (
            /** @type {?HTMLElement} */
            this.villamentParse(f)
          );
          g && (g.__villamentError = b, v.replaceWith(g));
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
      villamentFirstError(e, t, n) {
        const l = e[t];
        if (l?.length)
          return String(l[0]);
        if (!n)
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
          e.querySelector(w)
        );
        return t ? [...t.content.children].filter((n) => n.hasAttribute("wire:partial")) : [];
      },
      /**
       * @param {TemplateMeta} meta
       * @param {string} key
       * @param {string} unit
       * @returns {string}
       */
      villamentUnitKey(e, t, n) {
        return `schema-component::${e.rendering?.key}.${t}.${n}`;
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
          [...(e instanceof HTMLTemplateElement ? e.content : e).querySelectorAll("[wire\\:partial]")].find((n) => n.getAttribute("wire:partial") === t) ?? null
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
        const n = this.villamentControl(e, t === "up" ? "moveUp" : "moveDown"), l = this.villamentControl(e, t === "up" ? "moveDown" : "moveUp");
        [n, l].find((r) => r && !r.hasAttribute("disabled") && !r.hasAttribute("data-reorder-hidden"))?.focus({ preventScroll: !0 });
      }
    };
  };
}
function J(s) {
  return s.replaceAll("&", "&amp;").replaceAll("<", "&lt;").replaceAll(">", "&gt;").replaceAll('"', "&quot;").replaceAll("'", "&#039;");
}
const I = /* @__PURE__ */ new WeakMap();
function x(s, o = 0) {
  const c = (
    /** @type {?HTMLTemplateElement} */
    s.querySelector(C)
  ), m = c?.getAttribute("data-villament-repeater-template-ref");
  if (!c || !m)
    return c;
  if (o > 32)
    return null;
  let u;
  try {
    u = JSON.parse(m);
  } catch {
    return null;
  }
  let d = s.parentElement?.closest("[data-reorder-owner]") ?? null;
  for (; d && d.id !== u.ancestor; )
    d = d.parentElement?.closest("[data-reorder-owner]") ?? null;
  const p = d ? x(d, o + 1) : null;
  if (!p)
    return null;
  const e = I.get(c);
  if (e?.ancestorTemplate === p)
    return e.template;
  const t = L(p);
  if (!t)
    return null;
  const n = s.id.split(u.item).join(t.token), i = (
    /** @type {?HTMLTemplateElement} */
    [...p.content.querySelectorAll("[data-reorder-owner]")].find((h) => h.id === n)?.querySelector(C) ?? null
  );
  if (!i)
    return null;
  const r = document.createElement("template");
  r.innerHTML = i.innerHTML.split(t.token).join(u.item);
  const a = [...r.content.children].find((h) => h.matches("script[data-villament-repeater-meta]")), v = L(r);
  return a && v && (a.textContent = JSON.stringify({ ...v, ...u.meta })), I.set(c, { ancestorTemplate: p, template: r }), r;
}
function L(s) {
  const o = [...s?.content.children ?? []].find((c) => c.matches("script[data-villament-repeater-meta]"));
  if (!o)
    return null;
  try {
    return JSON.parse(o.textContent ?? "");
  } catch {
    return null;
  }
}
const k = new URL(import.meta.url).searchParams.get("base");
if (!k)
  throw new Error("[villament] client-repeater was loaded without its ?base= Filament repeater URL.");
const { default: W } = await import(
  /* @vite-ignore */
  k
), K = B(W);
export {
  K as clientRepeaterFormComponent,
  K as default
};
