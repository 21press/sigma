// The bubble: the only script on every page. Deferred, no REST call, no layout shift.
// It draws a fixed launcher inside a shadow root and loads the panel chunk on first use, or right away
// when the panel was open on the previous page of this tab (PLAN §8.1, transcript between pages).
import { applyBrand } from './color';
import { launcherCss } from './bubble-css';
import { OPEN_KEY } from './keys';
import { consentGuard, keepOnTop } from './stacking';
import { teaserAndPulse } from './teaser';
import { paintRemy } from './remy-icon';
import type { Brand, BootData, MountContext, PanelApi } from './types';
import { t } from './i18n';

declare const __PANEL_FILE__: string;

type PanelGlobal = { mount(ctx: MountContext): PanelApi };
type Win = Window & { SigmaWidget?: BootData; SigmaPanel?: PanelGlobal; __daBooted?: 1 };

const SVG = (cls: string, d: string, w = '1.5') =>
	`<svg class="${cls}" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="${d}" fill="none" stroke="currentColor" stroke-width="${w}" stroke-linecap="round" stroke-linejoin="round"/></svg>`;
/** The launcher shows Remy's icon (plan §8.8): one span.i-chat painted by remy-icon.ts, like every other place. */
const launcherIcon = (boot: BootData, name?: string | null, url?: string | null) => {
	const el = document.createElement('span');
	el.className = 'i-chat';
	paintRemy(el, name ?? boot.brand?.launcher_icon, url ?? boot.bot_avatar_url);
	return el;
};
const ICONS_REST = SVG('i-open', 'M6 9.5l6 6 6-6', '1.75') + '<span class="da-spin" aria-hidden="true"></span>';

(() => {
	const w = window as Win;
	if (w.__daBooted) return;
	const boot = w.SigmaWidget;
	if (!boot || typeof boot.root !== 'string' || !boot.root) return;
	w.__daBooted = 1;
	// Admin live preview (BootData.preview): never set on the site, see types.ts.
	const preview = boot.preview && typeof boot.preview === 'object' ? boot.preview : null;

	const self = document.currentScript as HTMLScriptElement | null;
	const scriptUrl = (self && self.src) || '';

	const start = () => {
		const host = document.createElement('sigma-chat');
		// Hard reset against theme rules that target any element (* {…}, body > * {…}).
		// Fixed, max z-index, last child of <body> (PLAN v1.6 §8.1): above everything, ties won by DOM order.
		host.setAttribute('style', 'all:initial!important;display:block!important;position:fixed!important;z-index:2147483647!important;top:0!important;left:0!important;width:0!important;height:0!important');
		const shadow = host.attachShadow({ mode: 'open' });

		// Light/dark (SPEC §3): brand.scheme forces it; "auto" follows <html data-theme> (Delta's toggle), else the OS.
		let pref: Brand['scheme'] = boot.brand?.scheme;
		const mq = matchMedia('(prefers-color-scheme: light)');
		const setScheme = (p?: Brand['scheme']) => {
			if (p) pref = p;
			let s: 'light' | 'dark';
			if (pref === 'light' || pref === 'dark') s = pref;
			else {
				const root = document.documentElement;
				const t = (root.getAttribute('data-theme') || '').toLowerCase();
				s = t === 'light' || t === 'dark' ? t : root.classList.contains('dark') ? 'dark' : pageTone() || (mq.matches ? 'light' : 'dark');
			}
			host.setAttribute('data-scheme', s);
		};
		setScheme();
		addEventListener('load', () => setScheme());
		new MutationObserver(() => setScheme()).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme', 'class'] });
		mq.addEventListener?.('change', () => setScheme());

		const style = document.createElement('style');
		style.textContent = launcherCss;
		shadow.appendChild(style);

		// Static, trusted markup only (icons). No server data ever goes through innerHTML.
		const launcher = document.createElement('button');
		launcher.type = 'button';
		launcher.className = 'da-launcher';
		const label = boot.brand?.name ? /* translators: %s: the chat box's name. */ t('Open %s', boot.brand.name) : t('Open help');
		launcher.setAttribute('aria-label', label);
		launcher.setAttribute('aria-haspopup', 'dialog');
		launcher.setAttribute('aria-expanded', 'false');
		launcher.innerHTML = ICONS_REST;
		launcher.prepend(launcherIcon(boot));
		shadow.appendChild(launcher);
		const probe = document.createElement('i');
		probe.className = 'da-probe';
		probe.setAttribute('aria-hidden', 'true');
		shadow.appendChild(probe);
		// Admin preview: on the page like on the site (fixed, bottom corner), or inside #da-preview-stage when the
		// screen provides one. On the page it keeps the site's stacking and lifts above fixed bars (the save bar).
		const stage = preview && document.getElementById('da-preview-stage');
		(stage || document.body).appendChild(host);
		if (!stage) {
			keepOnTop(host, shadow);
			consentGuard(host, launcher, probe);
		}

		applyBrand(host, shadow, boot.brand || (boot.accent ? { color_light: boot.accent } : null));

		let panel: PanelApi | null = null;
		let loading: Promise<PanelApi> | null = null;
		const ctx: MountContext = { shadow, host, launcher, boot, paintRemy, setScheme };

		const load = (): Promise<PanelApi> => {
			if (loading) return loading;
			loading = new Promise<PanelApi>((resolve, reject) => {
				if (w.SigmaPanel) return resolve(w.SigmaPanel.mount(ctx));
				const s = document.createElement('script');
				s.src = new URL(__PANEL_FILE__, scriptUrl || location.href).href;
				s.async = true;
				s.onload = () => (w.SigmaPanel ? resolve(w.SigmaPanel.mount(ctx)) : reject(new Error('panel')));
				s.onerror = () => reject(new Error('panel'));
				document.head.appendChild(s);
			}).catch((e) => {
				loading = null; // allow a retry on the next click
				throw e;
			});
			return loading;
		};

		const openPanel = (quiet = false) => {
			launcher.setAttribute('aria-busy', 'true');
			return load()
				.then((p) => {
					panel = p;
					launcher.removeAttribute('aria-busy');
					launcher.removeAttribute('data-error');
					p.open(quiet);
				})
				.catch(() => {
					launcher.removeAttribute('aria-busy');
					launcher.setAttribute('data-error', '');
					launcher.setAttribute('aria-label', t('Help could not load. Select to try again.'));
				});
		};

		// Warm the chunk when the pointer or keyboard reaches the launcher. Still no REST call.
		const warm = () => void load().catch(() => {});
		launcher.addEventListener('pointerenter', warm, { once: true });
		launcher.addEventListener('focus', warm, { once: true });
		launcher.addEventListener('click', () => (panel ? panel.toggle() : openPanel()));

		if (preview) {
			// Brand and scheme follow the settings screen; the panel follows the same event for the rest.
			addEventListener('sigma:preview', (e) => {
				const c = (e as CustomEvent<typeof preview>).detail;
				if (!c || typeof c !== 'object') return;
				applyBrand(host, shadow, c.brand);
				setScheme(c.brand?.scheme || 'auto');
				// Swap the launcher icon (Remy's icon, §8.8): bundled marks, or the uploaded image by src.
				launcher.querySelector('.i-chat')?.replaceWith(launcherIcon(boot, c.brand?.launcher_icon || 'assistant', c.bot_avatar_url || ''));
			});
			// On a phone the open panel is full-screen and would cover the settings: there it opens from the launcher.
			if (!matchMedia('(max-width: 639.98px)').matches) void openPanel(true);
			return;
		}

		// The visitor had the panel open on the previous page of this tab: reopen it as it was.
		let wasOpen = false;
		try {
			wasOpen = sessionStorage.getItem(OPEN_KEY) === '1';
		} catch {
			/* storage off */
		}
		teaserAndPulse(boot, host, shadow, launcher, () => (panel ? panel.open() : void openPanel()));
		if (wasOpen) void openPanel(true);
	};

	if (document.body) start();
	else document.addEventListener('DOMContentLoaded', start);
})();

/** The page's own tone from its background, for sites that are dark (or light) without a data-theme switch,
 *  such as deltatheme.com: following only the OS would put a dark launcher on a dark page. Null if transparent. */
function pageTone(): 'light' | 'dark' | null {
	for (const el of [document.body, document.documentElement]) {
		if (!el) continue;
		const m = getComputedStyle(el).backgroundColor.match(/[\d.]+/g);
		if (!m) continue;
		const [r, g, b, a = 1] = m.map(Number);
		if (a < 0.5) continue;
		return 0.2126 * r + 0.7152 * g + 0.0722 * b < 128 ? 'dark' : 'light';
	}
	return null;
}
