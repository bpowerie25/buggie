import { useEffect, useRef } from 'react';

type Handler = (event: KeyboardEvent) => void;

/**
 * Global keyboard map.
 *
 * Supports single keys ('c'), modifier combos ('mod+k', where mod is ⌘ on Mac and
 * Ctrl elsewhere) and two-key sequences ('g i', Vim/Linear style). Sequences time out
 * after a second so a stray 'g' does not lie in wait.
 *
 * Keystrokes are ignored while the caret is in a field — keyboard-first, not
 * keyboard-only, and typing 'c' in a comment must type a 'c'.
 */
export function useHotkeys(map: Record<string, Handler>, enabled = true) {
    // Kept in a ref so handlers can close over fresh props without rebinding.
    const mapRef = useRef(map);
    mapRef.current = map;

    useEffect(() => {
        if (!enabled) return;

        let pending: string | null = null;
        let timer: ReturnType<typeof setTimeout> | undefined;

        function isTyping(target: EventTarget | null): boolean {
            const el = target as HTMLElement | null;
            if (!el) return false;

            return (
                el.tagName === 'INPUT' ||
                el.tagName === 'TEXTAREA' ||
                el.tagName === 'SELECT' ||
                el.isContentEditable
            );
        }

        function onKeyDown(event: KeyboardEvent) {
            const handlers = mapRef.current;
            const mod = event.metaKey || event.ctrlKey;
            const key = event.key.length === 1 ? event.key.toLowerCase() : event.key;

            // Modifier combos work everywhere, including inside the comment editor.
            if (mod) {
                const combo = `mod+${key}`;
                if (handlers[combo]) {
                    event.preventDefault();
                    handlers[combo](event);
                }
                return;
            }

            if (event.altKey || isTyping(event.target)) return;

            if (pending) {
                const sequence = `${pending} ${key}`;
                pending = null;
                clearTimeout(timer);

                if (handlers[sequence]) {
                    event.preventDefault();
                    handlers[sequence](event);
                    return;
                }
            }

            // Anything that begins a registered sequence waits for its second key.
            if (Object.keys(handlers).some((k) => k.startsWith(`${key} `))) {
                pending = key;
                clearTimeout(timer);
                timer = setTimeout(() => (pending = null), 1000);
                return;
            }

            if (handlers[key]) {
                event.preventDefault();
                handlers[key](event);
            }
        }

        document.addEventListener('keydown', onKeyDown);

        return () => {
            document.removeEventListener('keydown', onKeyDown);
            clearTimeout(timer);
        };
    }, [enabled]);
}
