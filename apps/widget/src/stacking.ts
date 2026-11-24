// PLAN v1.6 §8.1 "Position and stacking": the bubble is always on top, but never hides a consent button.

const MAX_Z = '2147483647';

/** Host as the last child of <body>, fixed, max z-index: it wins ties by DOM order. If a later script appends
 *  its own max-z sibling after ours, re-append the host once per page (no loop). */
export function keepOnTop(host: HTMLElement, shadow: ShadowRoot): void {
	let moved = false;
	const isRival = (n: Element) => {
		const cs = getComputedStyle(n);
		return cs.position !== 'static' && cs.zIndex === MAX_Z && !!(host.compareDocumentPosition(n) & Node.DOCUMENT_POSITION_FOLLOWING);
	};
	const obs = new MutationObserver((records) => {
		for (const r of records) {
			r.addedNodes.forEach((n) => {
				if (moved || n === host || !(n instanceof Element) || n.parentNode !== document.body) return;
				// Styles can land a frame later (class added after insert): look now and on the next frame.
				const check = () => {
					if (moved || !n.isConnected || !isRival(n)) return;
					moved = true;
					obs.disconnect();
					const focused = shadow.activeElement as HTMLElement | null;
					document.body.appendChild(host);
					focused?.focus({ preventScroll: true });
				};
				check();
				requestAnimationFrame(check);
			});
		}
	});
	obs.observe(document.body, { childList: true });
}

/** While the panel is closed, guard consent UI under the launcher (PLAN §8.1, R1.1):
 *  - a fixed/sticky element in the lower part of the screen (a cookie card): lift the launcher 12px above it;
 *  - a large fixed/sticky overlay that starts above 35% of the viewport (a consent wall): hide the launcher
 *    (visibility hidden, so it is also out of the tab order) until the overlay is gone.
 *  Moving above a wall would push the launcher to the top of the screen; staying on top could cover its buttons.
 *  Checks on load, on resize, every 500ms for 10s after load, when the panel closes, every second while guarding,
 *  and after each user interaction for the whole visit (ARCH, R1.1): one passive capture listener for click and
 *  keyup (Enter, Space, Escape) schedules one check ~350ms later (after a menu's opening transition) plus one at the
 *  next transitionend/animationend within 1s. Debounced: a burst of clicks gives one check. Nothing runs between
 *  interactions, and no observer watches the page (menus toggle classes, so that would mean a costly subtree watch). */
export function consentGuard(host: HTMLElement, launcher: HTMLElement, probe: HTMLElement): void {
	let lift = 0;
	let covered = false;
	let watch = 0;

	const fixedAncestor = (el: Element | null): Element | null => {
		for (let e = el; e && e !== document.body && e !== document.documentElement; e = e.parentElement) {
			const p = getComputedStyle(e).position;
			if (p === 'fixed' || p === 'sticky') return e;
		}
		return null;
	};

	// After an interaction: one check once the menu/card has had time to open or close.
	let pending = 0;
	let settleUntil = 0;
	const schedule = (ms: number) => {
		clearTimeout(pending);
		pending = window.setTimeout(check, ms);
	};
	const interacted = (e: Event) => {
		if (e.type === 'keyup' && !['Enter', ' ', 'Escape'].includes((e as KeyboardEvent).key)) return;
		if (host.contains(e.target as Node)) return; // our own launcher and panel: the open/close observer handles it
		settleUntil = Date.now() + 1000;
		schedule(350);
	};
	const settled = () => {
		if (Date.now() > settleUntil) return;
		settleUntil = 0; // one transition/animation end per interaction
		schedule(30);
	};
	const opts = { capture: true, passive: true };
	document.addEventListener('click', interacted, opts);
	document.addEventListener('keyup', interacted, opts);
	document.addEventListener('transitionend', settled, opts);
	document.addEventListener('animationend', settled, opts);

	function check() {
		if (host.hasAttribute('data-open')) return;
		if (!launcher.getClientRects().length) return; // display:none (phone with the panel open)
		// Where the launcher sits without any lift: an invisible probe with the same box and no transition,
		// so a check during the lift animation does not read a half-way position.
		const r = probe.getBoundingClientRect();
		const top = r.top;
		const bottom = r.bottom;
		const vw = innerWidth;
		const vh = innerHeight;
		const points: [number, number][] = [
			[(r.left + r.right) / 2, (top + bottom) / 2],
			[r.left + 4, top + 4],
			[r.right - 4, top + 4],
			[r.left + 4, bottom - 4],
			[r.right - 4, bottom - 4],
		];
		let need = 0;
		let wall = false;
		for (const [x, y] of points) {
			if (x < 0 || y < 0 || x >= vw || y >= vh) continue;
			const under = document.elementsFromPoint(x, y).find((el) => el !== host && !host.contains(el)) || null;
			const box = fixedAncestor(under);
			if (!box) continue;
			const b = box.getBoundingClientRect();
			if (b.height < 8) continue;
			if (b.top < vh * 0.35) {
				// A consent wall covers most of the screen. A tall, narrow fixed sidebar or a header is not one.
				if (b.width >= vw * 0.5 && b.height >= vh * 0.5) wall = true;
				continue;
			}
			need = Math.max(need, bottom - (b.top - 12));
		}
		const next = wall ? 0 : Math.max(0, Math.ceil(need));
		if (next !== lift) {
			lift = next;
			host.style.setProperty('--da-lift', lift + 'px');
		}
		if (wall !== covered) {
			covered = wall;
			host.toggleAttribute('data-covered', wall);
		}
		host.toggleAttribute('data-guarded', !!lift || covered); // the teaser waits while this is set (§8.5)
		clearInterval(watch);
		if (lift || covered) watch = window.setInterval(check, 1000); // notice when it goes away
	}

	let frame = 0;
	const soon = () => {
		cancelAnimationFrame(frame);
		frame = requestAnimationFrame(check);
	};
	soon();
	addEventListener('load', soon);
	addEventListener('resize', soon);
	const started = Date.now();
	const tick = window.setInterval(() => {
		if (Date.now() - started > 10000) clearInterval(tick);
		else check();
	}, 500);
	new MutationObserver(soon).observe(host, { attributes: true, attributeFilter: ['data-open'] });
}
