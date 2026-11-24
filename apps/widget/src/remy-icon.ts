// Remy's icon (plan §8.8): one setting, brand.launcher_icon, drawn the same everywhere Remy appears. Built-in
// marks are our bundled assets (assets/icons, assets/remy-avatar.svg), never server text; `image` is the
// uploaded bot_avatar copy (bot_avatar_url), set as an <img> src by property. A failed image becomes `assistant`.
import assistant from '../../../assets/icons/assistant.svg';
import chat from '../../../assets/icons/chat.svg';
import help from '../../../assets/icons/help.svg';
import sparkles from '../../../assets/icons/sparkles.svg';
import remy from '../../../assets/remy-avatar.svg';

export type IconKind = 'assistant' | 'remy' | 'chat' | 'sparkles' | 'help' | 'image';
export const MARKS: Record<Exclude<IconKind, 'image'>, string> = { assistant, remy, chat, sparkles, help };

/** http(s), protocol-relative or same-site path; '' otherwise (an empty URL must never load the page). */
export const imageUrl = (url?: string | null) => {
	const u = typeof url === 'string' ? url.trim() : '';
	return /^(https?:)?\/\//i.test(u) || (u.startsWith('/') && !u.startsWith('//')) ? u : '';
};

/** The icon to draw: the setting if known; `image` only with an uploaded copy, else `assistant`. */
export function iconOf(name?: string | null, url?: string | null): { kind: IconKind; src: string } {
	const k = (name || '') as IconKind;
	if (k === 'image') {
		const src = imageUrl(url);
		return src ? { kind: 'image', src } : { kind: 'assistant', src: '' };
	}
	return { kind: Object.prototype.hasOwnProperty.call(MARKS, k) ? k : 'assistant', src: '' }; // not prototype keys
}

/** Fill `el` with Remy's icon (class da-remy da-remy--<kind>). */
export function paintRemy(el: HTMLElement, name?: string | null, url?: string | null): void {
	const { kind, src } = iconOf(name, url);
	el.classList.add('da-remy');
	for (const c of [...el.classList]) if (c.startsWith('da-remy--')) el.classList.remove(c);
	el.classList.add('da-remy--' + kind);
	el.setAttribute('aria-hidden', 'true');
	if (kind === 'image') {
		const img = document.createElement('img');
		img.alt = '';
		img.decoding = 'async';
		img.src = src;
		img.addEventListener('error', () => {
			el.textContent = '';
			paintRemy(el, 'assistant'); // never a broken image
		});
		el.replaceChildren(img);
	} else el.innerHTML = MARKS[kind];
}
