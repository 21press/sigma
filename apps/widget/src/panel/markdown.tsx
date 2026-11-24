// Safe Markdown subset for AI answers: paragraphs, headings (as bold lines), lists,
// bold (** only: _ is common inside words and code), inline code, fenced code, links. Output is Preact vnodes whose text is always a text node.
// Nothing here uses innerHTML or dangerouslySetInnerHTML; raw HTML in the answer shows as literal text.
import type { ComponentChildren, VNode } from 'preact';

/**
 * Only http(s) links, resolved against the page. Anything else (javascript:, data:, mailto:) renders as text.
 * §10b: a backslash, whitespace or control character anywhere, or a username/password, is refused: browsers and
 * PHP disagree on such URLs (`https://evil.com\@site/x` is site to PHP, evil.com to the browser).
 */
export function safeHref(raw: string): string | null {
	const t = raw.trim();
	if (!t || /[\\\s\u0000-\u001f\u007f]/.test(t)) return null;
	try {
		const u = new URL(t, location.href);
		if (u.username || u.password) return null;
		return u.protocol === 'https:' || u.protocol === 'http:' ? u.href : null;
	} catch {
		return null;
	}
}

/** Which links in an answer may be clickable (§10b): those the server listed in `links`, or on the site's own host. */
export interface LinkScope {
	allowed: Set<string>;
	host: string;
}
/** Comparison key: host + path without a trailing slash + query; no fragment (as the server compares). */
export const linkKey = (href: string) => {
	const u = new URL(href);
	return u.host.toLowerCase() + (u.pathname.replace(/\/+$/, '') || '/') + u.search;
};
function scopedHref(raw: string, scope?: LinkScope): string | null {
	const href = safeHref(raw);
	if (!href || !scope) return href;
	const host = new URL(href).host.toLowerCase();
	// The page's own host counts too: site_host comes from home_url(), which can differ (www vs apex).
	return host === scope.host || host === location.host.toLowerCase() || scope.allowed.has(linkKey(href)) ? href : null;
}

// The bare-URL pattern is a strict subset of the server's (`(?:https?://|www\.)[^\s<>()]+`, class-link-filter.php):
// it also stops at brackets, quotes and a backslash, and never ends on punctuation.
const INLINE = /(`[^`\n]+`)|(\*\*[^*\n]+\*\*)|(\[([^\]\n]+)\]\(([^)\s]+)\))|(https?:\/\/[^\s<>()\[\]"'\\]+[^\s<>()\[\]"'\\.,;:!?])/g;

export function inline(text: string, key = 'i', scope?: LinkScope): ComponentChildren[] {
	const out: ComponentChildren[] = [];
	let last = 0;
	let n = 0;
	for (const m of text.matchAll(INLINE)) {
		const at = m.index ?? 0;
		if (at > last) out.push(text.slice(last, at));
		const k = `${key}${n++}`;
		if (m[1]) out.push(<code key={k}>{m[1].slice(1, -1)}</code>);
		else if (m[2]) out.push(<strong key={k}>{inline(m[2].slice(2, -2), k, scope)}</strong>);
		else if (m[3]) {
			const href = scopedHref(m[5], scope);
			out.push(href ? <a key={k} href={href}>{m[4]}</a> : m[4]);
		} else if (m[6]) {
			const href = scopedHref(m[6], scope);
			out.push(href ? <a key={k} href={href}>{m[6]}</a> : m[6]);
		}
		last = at + m[0].length;
	}
	if (last < text.length) out.push(text.slice(last));
	return out;
}

type Block =
	| { t: 'p'; lines: string[] }
	| { t: 'h'; text: string }
	| { t: 'code'; text: string }
	| { t: 'ul' | 'ol'; items: string[] };

export function parse(src: string): Block[] {
	const lines = src.replace(/\r\n?/g, '\n').split('\n');
	const blocks: Block[] = [];
	let i = 0;
	while (i < lines.length) {
		const line = lines[i];
		if (/^\s*```/.test(line)) {
			const body: string[] = [];
			i++;
			while (i < lines.length && !/^\s*```/.test(lines[i])) body.push(lines[i++]);
			i++; // closing fence (or end)
			blocks.push({ t: 'code', text: body.join('\n') });
			continue;
		}
		if (!line.trim()) {
			i++;
			continue;
		}
		const h = line.match(/^\s{0,3}#{1,6}\s+(.*)$/);
		if (h) {
			blocks.push({ t: 'h', text: h[1].replace(/\s+#+\s*$/, '') });
			i++;
			continue;
		}
		const listRe = /^\s*([-*+]|\d+[.)])\s+(.*)$/;
		const li = line.match(listRe);
		if (li) {
			const t = /\d/.test(li[1]) ? 'ol' : 'ul';
			const items: string[] = [];
			while (i < lines.length) {
				const m = lines[i].match(listRe);
				if (m && (/\d/.test(m[1]) ? 'ol' : 'ul') === t) {
					items.push(m[2]);
					i++;
				} else if (lines[i].trim() && /^\s{2,}\S/.test(lines[i]) && items.length) {
					items[items.length - 1] += ' ' + lines[i].trim(); // wrapped continuation line
					i++;
				} else break;
			}
			blocks.push({ t, items });
			continue;
		}
		const para: string[] = [];
		while (i < lines.length && lines[i].trim() && !/^\s*```/.test(lines[i]) && !listRe.test(lines[i]) && !/^\s{0,3}#{1,6}\s/.test(lines[i])) {
			para.push(lines[i++].trim());
		}
		blocks.push({ t: 'p', lines: para });
	}
	return blocks;
}

export function Markdown({ text, scope }: { text: string; scope?: LinkScope }): VNode {
	return (
		<div class="md">
			{parse(text).map((b, i) => {
				const k = 'b' + i;
				switch (b.t) {
					case 'code':
						return (
							<pre key={k} tabIndex={0}>
								<code>{b.text}</code>
							</pre>
						);
					case 'h':
						return (
							<p key={k} class="md-h">
								<strong>{inline(b.text, k, scope)}</strong>
							</p>
						);
					case 'ul':
					case 'ol': {
						const L = b.t;
						return <L key={k}>{b.items.map((it, j) => <li key={j}>{inline(it, `${k}-${j}-`, scope)}</li>)}</L>;
					}
					default:
						return (
							<p key={k}>
								{b.lines.flatMap((l, j) => (j ? [<br key={`${k}br${j}`} />, ...inline(l, `${k}-${j}-`, scope)] : inline(l, `${k}-${j}-`, scope)))}
							</p>
						);
				}
			})}
		</div>
	);
}
