// Launcher teaser and beacon pulse (PLAN §8.5), in the bubble script: they run before the chat opens.
// - Teaser (v1.11, per visit = a tab session, sessionStorage): 3 s after the visit's first page, unless the chat was
//   opened in this visit. Once per visit; still open on navigation → it stays. Untouched for 15 s → it auto-hides.
//   Opening the chat or Escape on the focused teaser hides it for the rest of the visit. Waits while the consent
//   guard hides the launcher; never with the panel open. Text via textContent only.
// - Pulse (v1.15, per page view): on every page load an arrival burst at 1 s (the launcher "breathes" 1 → 1.06 → 1
//   in 600 ms, plus 2 rings in 2.4 s), then a ring burst every 12 s while the panel is closed. Independent of the
//   teaser. Opening the panel or Escape on the teaser stops it for this page view only; the next page pulses again.
//   Paused while the tab is hidden or the launcher has hover or focus; none with reduced motion. CSS
//   transform/opacity only; between bursts only one timer is pending.
import { VISIT_KEY } from './keys';
import { paintRemy } from './remy-icon';
import type { BootData } from './types';
import { t } from './i18n';

type Visit = { opened?: 1; quiet?: 1; teaser?: 'open' | 'done' };
const visit = (): Visit => {
	try {
		return JSON.parse(sessionStorage.getItem(VISIT_KEY) || '{}') || {};
	} catch {
		return {};
	}
};
const save = (v: Visit) => {
	try {
		sessionStorage.setItem(VISIT_KEY, JSON.stringify({ ...visit(), ...v }));
	} catch {
		/* storage off: this page view still behaves */
	}
};

export function teaserAndPulse(boot: BootData, host: HTMLElement, shadow: ShadowRoot, launcher: HTMLElement, open: () => void): void {
	const tz = boot.teaser || {};
	const bot = (boot.bot_name || 'Sigma').trim();
	const text = (typeof tz.text === 'string' && tz.text.trim() ? tz.text : /* translators: %s: the bot's name. */ t('👋 Hi, this is %s. How can I help you?', bot)).slice(0, 120);
	const finished = () => {
		const v = visit();
		return !!(v.opened || v.quiet);
	};

	// ----- teaser -----
	let teaser: HTMLElement | null = null;
	let showing = false;
	const v0 = visit();
	let eligible = tz.enabled !== false && !v0.opened && !v0.quiet && v0.teaser !== 'done';
	// Auto-hide (v1.11.1): untouched for 15 s, the teaser fades out. That counts as shown for the visit (no
	// re-greet) but not as a dismissal, so the pulse starts about 12 s later. Hover or focus pauses the countdown.
	let left = 15000;
	let since = 0;
	let hideT = 0;
	let held = false;
	const countdown = (run: boolean) => {
		clearTimeout(hideT);
		if (since) left -= Date.now() - since;
		since = 0;
		if (run && showing && !held) {
			since = Date.now();
			hideT = window.setTimeout(autoHide, Math.max(0, left));
		}
	};
	const autoHide = () => {
		since = 0;
		save({ teaser: 'done' });
		eligible = false;
		setShowing(false); // v1.15: the pulse is independent of the teaser, so nothing to re-arm
	};
	const setShowing = (on: boolean) => {
		showing = on;
		if (teaser) teaser.hidden = !on;
		host.toggleAttribute('data-teaser', on);
		if (on) save({ teaser: 'open' });
		countdown(on);
	};
	if (eligible) {
		teaser = document.createElement('div');
		teaser.className = 'da-teaser';
		teaser.hidden = true;
		// §8.6: a chat line, the Remy avatar then the message bubble; no ×. One button, "Open chat: {text}".
		const go = document.createElement('button');
		go.type = 'button';
		go.className = 'da-teaser__open';
		go.setAttribute('aria-label', /* translators: %s: the teaser text. */ t('Open chat: %s', text));
		// §8.8: Remy's icon, the same as the launcher's (bundled marks, or the uploaded image; a failed image → assistant).
		const av = document.createElement('span');
		av.className = 'da-teaser__av';
		paintRemy(av, boot.brand?.launcher_icon, boot.bot_avatar_url);
		const msg = document.createElement('span');
		msg.className = 'da-teaser__msg';
		msg.textContent = text;
		go.append(av, msg);
		teaser.append(go);
		const hold = (on: boolean) => () => {
			held = on || teaser!.matches(':hover') || teaser!.contains(shadow.activeElement);
			countdown(!held);
		};
		teaser.addEventListener('pointerenter', hold(true));
		teaser.addEventListener('pointerleave', hold(false));
		teaser.addEventListener('focusin', hold(true));
		teaser.addEventListener('focusout', () => setTimeout(hold(false))); // after focus has moved
		shadow.insertBefore(teaser, launcher); // before the launcher in tab order
		go.addEventListener('click', () => open()); // the panel's data-open then ends the visit's teaser and pulse
		// Escape on the focused teaser (§8.6, it replaces the ×): hide it for the visit; stop the pulse on this page (v1.15).
		go.addEventListener('keydown', (e) => {
			if (e.key !== 'Escape') return;
			e.preventDefault();
			save({ teaser: 'done' });
			eligible = false;
			setShowing(false);
			stopped = true;
			halt();
			launcher.focus();
		});
	}
	let ready = v0.teaser === 'open'; // still open from the previous page: no new 3 s wait
	const sync = () => {
		if (!teaser || !eligible) return;
		// v1.11.2: wait only for a full-screen wall (data-covered); above a small card the launcher is lifted and the
		// teaser rides along above it.
		const want = ready && !finished() && !host.hasAttribute('data-open') && !host.hasAttribute('data-covered');
		if (want !== showing) setShowing(want);
		if (showing) place();
	};
	new MutationObserver(sync).observe(host, { attributes: true, attributeFilter: ['data-open', 'data-covered', 'style'] });
	addEventListener('resize', () => showing && place());

	// Above the launcher, unless that would leave the viewport (short screens, a launcher lifted over a card):
	// then beside it. Measured from the launcher's target position (probe minus lift), not mid-transition.
	const probe = shadow.querySelector<HTMLElement>('.da-probe');
	function place() {
		if (!teaser || teaser.hidden || !probe) return;
		const lift = parseFloat(host.style.getPropertyValue('--da-lift')) || 0;
		const top = probe.getBoundingClientRect().top - lift;
		host.removeAttribute('data-teaser-at');
		teaser.style.maxWidth = '';
		if (top - teaser.offsetHeight - 12 < 8) {
			host.setAttribute('data-teaser-at', 'side');
			const r = probe.getBoundingClientRect();
			const room = host.getAttribute('data-side') === 'left' ? innerWidth - r.right - 12 - 8 : r.left - 12 - 8;
			teaser.style.maxWidth = Math.max(160, Math.min(innerWidth < 640 ? 220 : 260, room)) + 'px';
		}
	}

	// ----- pulse -----
	const reduce = matchMedia('(prefers-reduced-motion: reduce)');
	const ring = document.createElement('span');
	ring.className = 'da-ring';
	ring.setAttribute('aria-hidden', 'true');
	launcher.appendChild(ring);
	let next = 0;
	let off = 0;
	let arrived = false; // this page view's arrival burst has run
	let stopped = false; // v1.15: opened or Escape on this page view (not stored: the next page pulses again)
	const stop = () => {
		launcher.removeAttribute('data-pulse');
		launcher.removeAttribute('data-arrive');
	};
	const canPulse = () => boot.pulse !== false && !reduce.matches && !stopped && !host.hasAttribute('data-open') && document.visibilityState === 'visible';
	const burst = () => {
		next = 0;
		if (!canPulse()) return; // schedule() restarts it when the conditions return
		if (!launcher.matches(':hover') && shadow.activeElement !== launcher) {
			launcher.setAttribute('data-pulse', ''); // removed 2.4 s later, so each burst starts the animation afresh
			if (!arrived) launcher.setAttribute('data-arrive', ''); // the breathe, once per page view
			clearTimeout(off);
			off = window.setTimeout(stop, 2400);
		}
		arrived = true;
		next = window.setTimeout(burst, 12000);
	};
	function schedule(delay = arrived ? 4000 : 1000) {
		if (next || !canPulse()) return;
		next = window.setTimeout(burst, delay);
	}
	const halt = () => {
		clearTimeout(next);
		next = 0;
		stop();
	};
	launcher.addEventListener('pointerenter', stop);
	launcher.addEventListener('focus', stop);
	document.addEventListener('visibilitychange', () => (document.visibilityState === 'visible' ? schedule() : halt()));
	reduce.addEventListener?.('change', () => (reduce.matches ? halt() : schedule()));

	// Opening the chat (launcher, teaser, or a panel restored from the last page) ends the teaser for this visit and
	// the pulse for this page view (v1.15).
	const onOpen = () => {
		if (!host.hasAttribute('data-open')) return; // closed again: the pulse stays stopped on this page
		save({ opened: 1, teaser: 'done' });
		eligible = false;
		setShowing(false);
		stopped = true;
		halt();
	};
	new MutationObserver(onOpen).observe(host, { attributes: true, attributeFilter: ['data-open'] });

	const begin = () => {
		if (ready) sync();
		else window.setTimeout(() => ((ready = true), sync()), 3000);
		schedule(1000); // v1.15: the arrival burst, on every page load
	};
	if (document.readyState === 'complete') begin();
	else addEventListener('load', begin, { once: true });
}
