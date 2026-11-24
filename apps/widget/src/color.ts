// Brand settings → host custom properties (SPEC §2): colours per scheme with a computed text colour,
// launcher side and offsets, font. Every value is validated; anything invalid is ignored.
import type { Brand } from './types';

const DARK_TEXT = '#15171B';

function rgbOf(shadow: ShadowRoot, color: string): number[] | null {
	const probe = document.createElement('i');
	probe.style.color = color;
	shadow.appendChild(probe);
	const m = getComputedStyle(probe).color.match(/[\d.]+/g);
	probe.remove();
	return m ? m.slice(0, 3).map(Number) : null;
}

function luminance([r, g, b]: number[]): number {
	const f = (v: number) => {
		const c = v / 255;
		return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
	};
	return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b);
}

/** #15171B or #FFFFFF, whichever contrasts more with the brand colour (SPEC §2). */
export function onBrand(shadow: ShadowRoot, color: string): string | null {
	const rgb = rgbOf(shadow, color);
	if (!rgb) return null;
	const l = luminance(rgb);
	const dark = luminance([0x15, 0x17, 0x1b]);
	return (l + 0.05) / (dark + 0.05) >= 1.05 / (l + 0.05) ? DARK_TEXT : '#FFFFFF';
}

// ----- Panel palette (#39 follow-up) -----
// Owners set Background and Text per scheme. Every other panel colour is generated here, so it adapts to
// any background: HSL lightness steps for the page tint (darker) and input fields (away from the text), a
// small share of the text for hovers (6%) and borders (14%), and text mixed toward the background for muted
// text and control borders, raised until it meets its contrast target (4.5:1 and 3:1). Status colours stay fixed; only their soft backgrounds follow.

type Rgb = number[];
const hex = (c: Rgb) => '#' + c.map((v) => Math.round(Math.min(255, Math.max(0, v))).toString(16).padStart(2, '0')).join('');
const mixRgb = (a: Rgb, b: Rgb, t: number): Rgb => a.map((v, i) => v * t + b[i] * (1 - t));
const ratio = (a: Rgb, b: Rgb) => {
	const [x, y] = [luminance(a), luminance(b)].sort((p, q) => q - p);
	return (x + 0.05) / (y + 0.05);
};

function toHsl([r, g, b]: Rgb): number[] {
	const [R, G, B] = [r / 255, g / 255, b / 255];
	const max = Math.max(R, G, B);
	const min = Math.min(R, G, B);
	const l = (max + min) / 2;
	if (max === min) return [0, 0, l * 100];
	const d = max - min;
	const s = l > 0.5 ? d / (2 - max - min) : d / (max + min);
	const h = max === R ? (G - B) / d + (G < B ? 6 : 0) : max === G ? (B - R) / d + 2 : (R - G) / d + 4;
	return [h * 60, s * 100, l * 100];
}

function fromHsl([h, s, l]: number[]): Rgb {
	const S = s / 100;
	const L = l / 100;
	const k = (n: number) => (n + h / 30) % 12;
	const a = S * Math.min(L, 1 - L);
	const f = (n: number) => L - a * Math.max(-1, Math.min(k(n) - 3, Math.min(9 - k(n), 1)));
	return [f(0) * 255, f(8) * 255, f(4) * 255];
}

/** The background with its lightness moved by `dl` points (clamped to 0–100). */
const shift = (bg: Rgb, dl: number): Rgb => {
	const [h, s, l] = toHsl(bg);
	return fromHsl([h, s, Math.min(100, Math.max(0, l + dl))]);
};

/** Text mixed toward the background, starting at `t` and raised until it reaches `min` contrast. */
function readable(text: Rgb, bg: Rgb, t: number, min: number): Rgb {
	let w = t;
	let c = mixRgb(text, bg, w);
	while (ratio(c, bg) < min && w < 1) {
		w = Math.min(1, w + 0.05);
		c = mixRgb(text, bg, w);
	}
	return c;
}

const STATUS = { light: { danger: [0xa8, 0x42, 0x16], warning: [0x8a, 0x65, 0x10] }, dark: { danger: [0xe8, 0x77, 0x4a], warning: [0xe0, 0xb4, 0x4a] } };
const INK = { light: [0x1d, 0x1d, 0x1f], dark: [0xf5, 0xf5, 0xf7] };

/** Panel colours for one scheme from its Background (and Text, else the Delta ink that reads best on it). */
export function panelPalette(bgRgb: Rgb, textRgb: Rgb | null, scheme: 'light' | 'dark'): Record<string, string> {
	const text = textRgb || (ratio(INK.light, bgRgb) >= ratio(INK.dark, bgRgb) ? INK.light : INK.dark);
	const lightBg = luminance(bgRgb) > luminance(text);
	return {
		surface: hex(bgRgb),
		'surface-body': hex(shift(bgRgb, -4)),
		'surface-sunken': hex(shift(bgRgb, lightBg ? 5 : -6)),
		'surface-hover': hex(mixRgb(text, bgRgb, 0.06)),
		border: hex(mixRgb(text, bgRgb, 0.14)),
		'border-control': hex(readable(text, bgRgb, 0.45, 3)),
		ink: hex(text),
		'ink-muted': hex(readable(text, bgRgb, 0.7, 4.5)),
		focus: hex(text),
		'danger-bg': hex(mixRgb(STATUS[scheme].danger, bgRgb, 0.1)),
		'warning-bg': hex(mixRgb(STATUS[scheme].warning, bgRgb, 0.1)),
	};
}

const okColor = (v: unknown): v is string => typeof v === 'string' && v.length < 64 && CSS.supports('color', v);

const PALETTE_KEYS = ['surface', 'surface-body', 'surface-sunken', 'surface-hover', 'border', 'border-control', 'ink', 'ink-muted', 'focus', 'danger-bg', 'warning-bg'];

export function applyBrand(host: HTMLElement, shadow: ShadowRoot, brand: Brand | null | undefined): void {
	if (!brand || typeof brand !== 'object') return;
	const set = (k: string, v: string) => host.style.setProperty(k, v);
	// `accent` is the plan's original single colour: treat it as the light-scheme colour.
	const light = okColor(brand.color_light) ? brand.color_light : okColor(brand.accent) ? brand.accent : null;
	const dark = okColor(brand.color_dark) ? brand.color_dark : null;
	for (const [scheme, c] of [['light', light], ['dark', dark]] as const) {
		if (!c) continue;
		const on = onBrand(shadow, c);
		if (!on) continue;
		set(`--da-brand-${scheme}`, c);
		set(`--da-on-brand-${scheme}`, on);
	}
	const off = (v: unknown) => (typeof v === 'number' && v >= 0 && v <= 200 ? Math.round(v) + 'px' : null);
	const x = off(brand.offset_x);
	const y = off(brand.offset_y);
	if (x) set('--da-launcher-offset-x', x);
	if (y) set('--da-launcher-offset-y', y);
	if (brand.position === 'left' || brand.position === 'right') host.setAttribute('data-side', brand.position);
	// font: "inherit" uses the page's body font (already loaded, nothing downloaded); "system" the OS UI font.
	if (brand.font === 'system') set('--da-font', 'ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif');
	else if (brand.font === 'inherit') {
		const f = getComputedStyle(document.body).fontFamily;
		if (f && f.length < 300 && !/[;{}<>]/.test(f)) set('--da-font', f);
	}
}

/** Panel palette (#39 follow-up): only for a scheme the owner set, so sites without it keep the exact Delta
 *  values. Called by the panel chunk only (the launcher doesn't use these colours), to keep widget.js small. */
export function applyPalette(host: HTMLElement, shadow: ShadowRoot, brand: Brand | null | undefined): void {
	if (!brand || typeof brand !== 'object') return;
	const set = (k: string, v: string) => host.style.setProperty(k, v);
	for (const scheme of ['light', 'dark'] as const) {
		const bg = brand[`bg_${scheme}`];
		const tx = brand[`text_${scheme}`];
		const bgRgb = okColor(bg) ? rgbOf(shadow, bg) : null;
		if (!bgRgb) {
			for (const k of PALETTE_KEYS) host.style.removeProperty(`--da-${k}-${scheme}`);
			continue;
		}
		const txRgb = okColor(tx) ? rgbOf(shadow, tx) : null;
		const pal = panelPalette(bgRgb, txRgb, scheme);
		for (const k of PALETTE_KEYS) set(`--da-${k}-${scheme}`, pal[k]);
	}
}
