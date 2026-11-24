// Cloudflare Turnstile (§10b, optional). The script is a third-party page script, so it loads only when a form or
// gate that needs it is on screen, never on page load or when the panel opens. Explicit rendering into an element
// inside our shadow root.
const SRC = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';

export interface TurnstileApi {
	render(el: HTMLElement, opts: Record<string, unknown>): string;
	reset(id?: string): void;
	remove(id?: string): void;
}

let loading: Promise<TurnstileApi> | null = null;

export function loadTurnstile(): Promise<TurnstileApi> {
	const w = window as unknown as { turnstile?: TurnstileApi };
	if (w.turnstile) return Promise.resolve(w.turnstile);
	if (!loading) {
		loading = new Promise<TurnstileApi>((ok, fail) => {
			const s = document.createElement('script');
			s.src = SRC;
			s.async = true;
			s.onload = () => (w.turnstile ? ok(w.turnstile) : fail(new Error('turnstile')));
			s.onerror = () => {
				loading = null; // a later attempt may succeed
				s.remove();
				fail(new Error('turnstile'));
			};
			document.head.appendChild(s);
		});
	}
	return loading;
}
