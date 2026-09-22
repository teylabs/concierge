/**
 * Let modified clicks (new tab, new window) behave natively; route plain
 * primary clicks through the adapter.
 */
export function shouldIntercept(event: MouseEvent): boolean {
    return !(
        event.defaultPrevented ||
        event.button !== 0 ||
        event.metaKey ||
        event.ctrlKey ||
        event.shiftKey ||
        event.altKey
    );
}
