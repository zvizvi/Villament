/**
 * Wraps a plain `$wire`-shaped mock in a Proxy that mimics Livewire v4's
 * REAL `Livewire.find()` return value: the $wire proxy itself, where any
 * unknown property (including `$wire`) resolves to a phantom server-method
 * caller FUNCTION — never `undefined`, never a component object.
 *
 * Regression guard for the `find(id).$wire.call(...)` bug: with the real
 * shape, that expression invoked `Function.prototype.call` on a phantom
 * method named `$wire`, firing a server call to a nonexistent method.
 *
 * @param {object} wireImplementation Known methods/props ($on, get, set, mountAction, …).
 * @param {(name: string, args: unknown[]) => void} [onPhantomCall] Invoked when a
 *     phantom (unknown-property) method is actually called — tests can fail on it.
 * @returns {object} The proxied fake `$wire`.
 */
export function makeWireProxy(wireImplementation, onPhantomCall = () => {}) {
    return new Proxy(wireImplementation, {
        get(target, property) {
            if (property in target) {
                return target[property]
            }

            if (property === 'then' || typeof property === 'symbol') {
                return undefined
            }

            // Livewire's fallback: unknown property → phantom method caller.
            return (...args) => onPhantomCall(String(property), args)
        },
    })
}
