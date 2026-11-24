// The panel: home, chat, handoff and states.
// Views: loading → home (greeting + composer) → chat ⇄ handoff → sent. R2 adds a "live" view for handoff
// {status:"waiting"}; the header, message list (already renders `agent`), composer and footer are shared for it.
// Desktop: non-modal dialog, no focus trap. Phones (< 640px): full-screen modal with a focus trap.
import type { JSX } from 'preact';
import { useEffect, useLayoutEffect, useRef, useState } from 'preact/hooks';
import { applyBrand, applyPalette } from '../color';
import type { ChatMessage, MountContext, SiteConfig } from '../types';
import { ApiError, createApi, createPreviewApi, minutes, uuid4, type Session } from './api';
import { Icon } from './icons';
import { Markdown, linkKey, safeHref } from './markdown';
import { loadTurnstile } from './turnstile';
import * as store from './storage';
import { t } from '../i18n';
import { canDictate, useDictation } from './voice';

type View = 'loading' | 'error' | 'off' | 'home' | 'chat' | 'handoff' | 'sent';

export interface Bridge {
	setOpen?: (fn: (open: boolean) => boolean, quiet?: boolean) => void;
}

const MAX_TEXT = 2000;
const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const FOCUSABLE = 'a[href],button:not([disabled]),textarea:not([disabled]),input:not([disabled]):not([tabindex="-1"]),[tabindex]:not([tabindex="-1"])';
const MOBILE = '(max-width: 639.98px)';
const SLOW_MS = 8000;

let seq = 0;
const localId = () => 'l' + Date.now().toString(36) + (seq++).toString(36);
const pageUrl = () => location.origin + location.pathname; // no query or hash: they can carry personal data
/** Shorten to about n characters on a word boundary, with an ellipsis. */
/** The AI's name (§8.5): /config.bot_name, else the inline boot value, else "Sigma" (0.2.5). Set by App each render. */
let botName = 'Sigma'; // The default name (0.2.5), a name: not translated.
/** §10b: links on this host are clickable in answers (from /config site_host; the page's host before it loads). */
let siteHost = location.host.toLowerCase();
/** §10b: Turnstile site key ('' = off). */
let turnstileKey = '';
let turnstileTheme: 'auto' | 'light' | 'dark' = 'auto';
/** AI message label: the bot's name and an "AI" badge, so visitors always know it's an AI (§8.5). */
/** Remy's icon (plan §8.8): brand.launcher_icon and bot_avatar_url from /config, else the inline boot values.
 *  Set by App each render; drawn by the same painter as the launcher and the teaser. */
let remyName: string | null = null;
let remyUrl: string | null = null;
let paint: MountContext['paintRemy'] = () => {};
function BotAvatar({ size }: { size: number }) {
	const el = useRef<HTMLSpanElement>(null);
	const name = remyName;
	const url = remyUrl;
	useLayoutEffect(() => {
		if (el.current) paint(el.current, name, url);
	}, [name, url]);
	return <span ref={el} class="da-bot-av" aria-hidden="true" style={{ width: size + 'px', height: size + 'px' }} />;
}
/** AI message head: the Remy avatar (24 px) and "Remy · AI" (the badge stays the AI disclosure). */
const AiLabel = () => (
	<span class="da-msg__head">
		<BotAvatar size={24} />
		<span class="da-msg__label">
			{botName}
			<span class="da-ai">{t('AI')}</span>
		</span>
	</span>
);
const pageLocale = () => document.documentElement.lang || navigator.language || 'en';

function limitText(m: ChatMessage): { body: string; placeholder: string } {
	if (m.scope === 'day') return { body: t('You can ask again tomorrow. A person can help now.'), placeholder: t('You can ask again tomorrow') };
	const n = minutes(m.retryAfter || 3600);
	if (n >= 60) return { body: t('You can ask again in about an hour. A person can help now.'), placeholder: t('You can ask again in about an hour') };
	return {
		body: n === 1 ? t('You can ask again in about 1 minute. A person can help now.') : /* translators: %s: minutes (2 or more). */ t('You can ask again in about %s minutes. A person can help now.', n), placeholder: /* translators: %s: minutes. */ t('You can ask again in %s min', n) };
}

export function App({ ctx, bridge }: { ctx: MountContext; bridge: Bridge }) {
	// Admin live preview (BootData.preview): config from the settings screen, no network, see createPreviewApi.
	const previewConfig = useRef<SiteConfig | null>(ctx.boot.preview || null);
	const api = useRef(
		ctx.boot.preview ? createPreviewApi(() => previewConfig.current as SiteConfig) : createApi(ctx.boot.root, typeof ctx.boot.nonce === 'string' ? ctx.boot.nonce : undefined),
	).current;
	const saved = useRef(store.loadChat()).current;
	paint = ctx.paintRemy;

	const [open, setOpenState] = useState(false);
	const [config, setConfig] = useState<SiteConfig | null>(null);
	const [view, setView] = useState<View>('loading');
	const [messages, setMessages] = useState<ChatMessage[]>(saved?.messages ?? []);
	const [busy, setBusy] = useState(false);
	/** §10b: a security check the server asked for before a new conversation (403 turnstile_required). */
	const [gate, setGate] = useState<{ ok: (token: string) => void; no: (e: unknown) => void } | null>(null);
	// Streaming answer (§8.3): plain text shown outside the live log while it arrives; the finished message
	// then goes into the log once, so a screen reader announces it once.
	const [stream, setStream] = useState<string | null>(null);
	const [slow, setSlow] = useState(false);
	// Page view only: a provider hiccup (503) must not switch the AI off for the whole tab; /config decides on the next page.
	const [aiDown, setAiDown] = useState(false);
	const [humanOk, setHumanOk] = useState(true); // can_handoff from the last answer
	const [lockedUntil, setLockedUntil] = useState(saved?.lockedUntil && saved.lockedUntil > Date.now() ? saved.lockedUntil : 0);
	const [sent, setSent] = useState(saved?.sent ?? null);
	const [mobile, setMobile] = useState(() => matchMedia(MOBILE).matches);
	const session = useRef<Session | null>(store.loadToken());
	const panelRef = useRef<HTMLDivElement>(null);
	const quietOpen = useRef(false);
	const closedBy = useRef<'key' | 'button' | 'launcher'>('launcher');
	const fresh = useRef(new Set<string>()); // messages created on this page view (announce their alerts)
	const returnFocus = useRef<string | null>(null); // data-fk of the control that opened a sub-view
	// Back history (owner bug, 0.1.1): Back returns to the view the visitor came from, focusing the control that
	// opened the view. Kept in sessionStorage with the transcript, so a reload keeps Back right.
	/** A new conversation started from home that has no answer yet, and the old one it would replace. */
	const pending = useRef<{ messages: ChatMessage[]; session: Session | null; sent: { email: string; text?: string } | null; history: { view: View; fk: string | null }[] } | null>(null);
	const [committed, setCommitted] = useState(0); // bumps when a pending conversation becomes the stored one
	/** The new conversation was created and its first ask accepted (`start` event or a JSON 200), or it handed off:
	 *  it replaces the old one now, in storage and as the Continue card (0.1.2). */
	const commitPending = () => {
		if (!pending.current) return;
		pending.current = null;
		if (session.current) store.saveToken(session.current);
		setCommitted((n) => n + 1);
	};
	const history = useRef<{ view: View; fk: string | null }[]>(
		Array.isArray(saved?.history) ? (saved!.history as { view: View; fk: string | null }[]).slice(-10) : [],
	);
	/** Open a view from the current one; fk = data-fk of the control that opened it (focus target on Back). */
	const go = (to: View, fk: string | null = null) => {
		if (to === view) return;
		if (view === 'home' || view === 'chat' || view === 'handoff' || view === 'sent') {
			history.current = [...history.current, { view, fk }].slice(-10);
		}
		returnFocus.current = null;
		setView(to);
	};

	bridge.setOpen = (fn, quiet = false) => {
		quietOpen.current = quiet;
		closedBy.current = 'launcher';
		setOpenState(fn);
	};

	useEffect(() => {
		const mq = matchMedia(MOBILE);
		const on = () => setMobile(mq.matches);
		mq.addEventListener?.('change', on);
		return () => mq.removeEventListener?.('change', on);
	}, []);

	// ----- open / close side effects -----
	useEffect(() => {
		const { launcher, host } = ctx;
		const name = config?.brand?.name;
		launcher.setAttribute('aria-expanded', String(open));
		launcher.setAttribute('aria-label', open ? t('Close help') : name ? /* translators: %s: the chat box's name. */ t('Open %s', name) : t('Open help'));
		launcher.setAttribute('aria-controls', 'da-panel');
		host.toggleAttribute('data-open', open);
		store.saveOpen(open);
		const html = document.documentElement;
		const lock = open && mobile;
		const prev = html.style.overflow;
		if (lock) html.style.overflow = 'hidden'; // full-screen panel on phones: stop the page scrolling behind it
		if (!open && closedBy.current !== 'launcher') launcher.focus();
		return () => {
			if (lock) html.style.overflow = prev;
		};
	}, [open, mobile, config]);

	// ----- config: the first REST call, only once the panel is open -----
	function loadConfig() {
		setView('loading');
		api
			.config()
			.then((c) => {
				setConfig(c);
				applyBrand(ctx.host, ctx.shadow, c.brand);
			applyPalette(ctx.host, ctx.shadow, c.brand);
				ctx.setScheme(c.brand?.scheme || 'auto');
				const saveView = saved?.view as View | undefined;
				if (!c.enabled || (!c.ai_enabled && !c.human_enabled)) setView('off');
				else if (saveView === 'sent' && sent && c.human_enabled) setView('sent');
				else if (saveView === 'handoff' && c.human_enabled) setView('handoff');
				else if (saveView === 'chat' && messages.length) setView('chat');
				else setView('home');
			})
			.catch(() => setView('error'));
	}

	useEffect(() => {
		if (open && !config && view === 'loading') loadConfig();
	}, [open]);

	// Admin live preview: the settings screen sends the unsaved settings on every change.
	useEffect(() => {
		if (!ctx.boot.preview) return;
		const on = (e: Event) => {
			const c = (e as CustomEvent<SiteConfig>).detail;
			if (!c || typeof c !== 'object') return;
			previewConfig.current = c;
			setConfig(c);
			applyBrand(ctx.host, ctx.shadow, c.brand);
			applyPalette(ctx.host, ctx.shadow, c.brand);
			ctx.setScheme(c.brand?.scheme || 'auto');
			setView((v) => (!c.enabled || (!c.ai_enabled && !c.human_enabled) ? 'off' : v === 'off' || v === 'loading' ? 'home' : v));
		};
		addEventListener('sigma:preview', on);
		return () => removeEventListener('sigma:preview', on);
	}, []);

	// ----- persistence (sessionStorage; the token lives in localStorage) -----
	useEffect(() => {
		if (view === 'loading' || view === 'error' || view === 'off') return;
		if (pending.current) return; // the old conversation stays stored until the new one gets its first answer
		store.saveChat({ messages: messages.filter((m) => m.card !== 'down'), view, sent, lockedUntil, history: history.current });
	}, [messages, view, sent, lockedUntil, committed]);

	// ----- timers: rate-limit lock, "Still reading the docs…" -----
	useEffect(() => {
		if (!lockedUntil) return;
		const t = setTimeout(() => setLockedUntil(0), Math.max(0, lockedUntil - Date.now()));
		return () => clearTimeout(t);
	}, [lockedUntil]);

	useEffect(() => {
		setSlow(false);
		if (!busy) return;
		const t = setTimeout(() => setSlow(true), SLOW_MS);
		return () => clearTimeout(t);
	}, [busy]);

	// ----- focus: into the panel on open (not on a quiet restore) and on every view change -----
	useLayoutEffect(() => {
		const panel = panelRef.current;
		if (!open || !panel || view === 'loading') return;
		if (quietOpen.current) {
			quietOpen.current = false;
			return;
		}
		const key = returnFocus.current;
		returnFocus.current = null;
		const el =
			(key && panel.querySelector<HTMLElement>(`[data-fk="${key}"]`)) ||
			panel.querySelector<HTMLElement>('[data-autofocus]') ||
			panel.querySelector<HTMLElement>(FOCUSABLE);
		el?.focus({ preventScroll: true });
	}, [open, view]);

	const push = (m: Omit<ChatMessage, 'id'> & { id?: string }) => {
		const id = m.id || localId();
		fresh.current.add(id);
		setMessages((list) => [...list, { ...m, id }]);
		return id;
	};

	async function ensureSession(renew = false): Promise<Session> {
		if (session.current && !renew) return session.current;
		let r;
		try {
			r = await api.create(pageUrl(), pageLocale());
		} catch (e) {
			if (e instanceof ApiError && (e.code === 'turnstile_required' || e.code === 'turnstile_failed') && turnstileKey) {
				// §10b: past the site's question breaker a new conversation needs a Turnstile token. Ask once.
				const token = await new Promise<string>((ok, no) => setGate({ ok, no }));
				r = await api.create(pageUrl(), pageLocale(), token || undefined); // '' = the challenge couldn't load
			} else {
				// 1 new conversation per 10 s per IP (shared office IPs, two tabs): wait it out once, quietly.
				if (!(e instanceof ApiError) || e.status !== 429 || e.retryAfter > 15) throw e;
				await new Promise((ok) => setTimeout(ok, (e.retryAfter || 10) * 1000 + 250));
				r = await api.create(pageUrl(), pageLocale());
			}
		}
		session.current = { uuid: r.uuid, token: r.token };
		if (!pending.current) store.saveToken(session.current); // a pending new conversation stores it once answered
		return session.current;
	}

	/** Runs a call with the session; if the server no longer knows it (purged, bad token), starts a new one once. */
	async function withSession<T>(fn: (s: Session) => Promise<T>): Promise<T> {
		const s = await ensureSession();
		try {
			return await fn(s);
		} catch (e) {
			// The server no longer knows this conversation (purged, token mismatch, closed): start a new one once.
			if (e instanceof ApiError && ['invalid_token', 'not_found', 'closed', 'rest_forbidden'].includes(e.code)) return fn(await ensureSession(true));
			throw e;
		}
	}

	/** fresh: asked from the home composer while a conversation exists → start a new one. The old transcript and
	 *  token are replaced only now, when the first message of the new one is sent (owner feedback, 0.1.1). */
	async function ask(text: string, retryId?: string, fresh = false) {
		text = text.trim().slice(0, MAX_TEXT);
		if (!text || busy || Date.now() < lockedUntil) return;
		if (fresh && messages.some((m) => m.sender === 'visitor')) {
			// Keep the old conversation until the new one's first answer arrives (review note on #12):
			// a failed first send followed by Back brings the old one and its Continue card back.
			pending.current = { messages, session: session.current, sent, history: history.current };
			session.current = null; // ensureSession() creates the new conversation
			setMessages([]);
			setSent(null);
			setHumanOk(true);
			setAiDown(false);
			history.current = [];
		}
		let visitorId = retryId;
		if (retryId) setMessages((l) => l.map((m) => (m.id === retryId ? { ...m, failed: false, busy: false } : m)));
		else visitorId = push({ sender: 'visitor', body: text });
		if (view !== 'chat') go('chat');
		setBusy(true);
		let partial = '';
		setStream(null);
		try {
			const run = () =>
				withSession((s) =>
					api.askStream(
						s,
						text,
						(t) => {
							partial += t;
							setStream(partial);
						},
						commitPending,
						{ page_url: pageUrl(), locale: pageLocale() }, // §10b: the first ask creates the conversation
					),
				);
			let r;
			try {
				r = await run();
			} catch (e) {
				// §10b: no free slot (one ask in flight per conversation, 2 per IP, 4 per site). Retry once, quietly.
				if (!(e instanceof ApiError) || e.code !== 'busy' || e.midStream) throw e;
				await new Promise((ok) => setTimeout(ok, Math.min(Math.max(e.retryAfter || 5, 1), 15) * 1000 + 250));
				r = await run();
			}
			setStream(null);
			commitPending();
			setHumanOk(r.can_handoff !== false);
			push({
				id: String(r.message.id),
				sender: 'ai',
				body: r.message.body || '',
				links: Array.isArray(r.message.links) ? r.message.links : [],
				notInDocs: r.answered === false,
				handoffRequested: r.handoff_requested === true,
			});
		} catch (e) {
			const err = e instanceof ApiError ? e : new ApiError(0, 'unknown', '');
			setStream(null);
			if (err.midStream && partial) {
				// Cut off after some text: keep it, greyed, with "Not finished. Try again" (resends the same question).
				push({ sender: 'ai', body: partial, partial: true, question: text, retryOf: visitorId });
			} else if (err.midStream && err.retryable) {
				setMessages((l) => l.map((m) => (m.id === visitorId ? { ...m, failed: true } : m)));
			} else if (err.code === 'busy') {
				setMessages((l) => l.map((m) => (m.id === visitorId ? { ...m, failed: true, busy: true } : m)));
			} else if (err.code === 'turnstile_cancelled') {
				setMessages((l) => l.map((m) => (m.id === visitorId ? { ...m, failed: true } : m)));
			} else if (err.status === 429) {
				const secs = err.retryAfter || 3600;
				setLockedUntil(Date.now() + secs * 1000);
				push({ sender: 'system', body: '', card: 'limit', retryAfter: secs, scope: err.scope || 'hour' });
			} else if (err.code === 'disabled' || err.code === 'not_available') {
				setView('off'); // kill switch or admins-only mode
			} else if (err.code === 'ai_unavailable' && /^(status_|http_error|corpus_building)/.test(err.reason)) {
				// Provider hiccup (timeout, overload) or the docs index still building (v1.10.1): the question can be
				// retried in a moment; the AI stays on.
				setMessages((l) => l.map((m) => (m.id === visitorId ? { ...m, failed: true } : m)));
			} else if (err.status === 503 || err.code === 'ai_unavailable') {
				setAiDown(true);
				push({ sender: 'system', body: '', card: 'down' });
			} else {
				setMessages((l) => l.map((m) => (m.id === visitorId ? { ...m, failed: true } : m)));
			}
		} finally {
			setBusy(false);
		}
	}

	/** "Talk to Remy" (§8.6) and the chat header's New chat (v1.13): a new AI chat that starts with Remy's intro. An
	 *  existing conversation is kept (pending) until the new one's first ask is accepted, exactly as for any new
	 *  conversation; Back without sending puts it back. */
	function startBotChat(fk = 'home-remy') {
		const introMsg: ChatMessage = { id: 'intro', sender: 'ai', body: intro, intro: true };
		if (!pending.current && (messages.some((m) => m.sender === 'visitor') || sent)) {
			pending.current = { messages, session: session.current, sent, history: history.current };
			session.current = null;
			setSent(null);
			setHumanOk(true);
			setAiDown(false);
			history.current = [];
		}
		setMessages([introMsg]);
		go('chat', fk);
	}

	function toHandoff(fromKey: string) {
		go('handoff', fromKey);
	}

	function goBack() {
		cancelGate();
		const old = pending.current;
		if (old && view === 'chat') {
			// The new conversation never got an answer: put the old one back.
			pending.current = null;
			session.current = old.session;
			setMessages(old.messages);
			setSent(old.sent);
			history.current = [];
			returnFocus.current = 'home-remy'; // its card shows "Continue with Remy" again
			setView('home');
			return;
		}
		const prev = history.current[history.current.length - 1];
		history.current = history.current.slice(0, -1);
		returnFocus.current = prev ? prev.fk : null;
		setView(prev ? prev.view : 'home'); // nothing stored: Back goes home
	}

	function cancelGate() {
		if (!gate) return;
		gate.no(new ApiError(0, 'turnstile_cancelled', ''));
		setGate(null);
	}

	function close(by: 'key' | 'button') {
		closedBy.current = by;
		setOpenState(false);
	}

	function onKeyDown(e: KeyboardEvent) {
		if (e.key === 'Escape' && !e.isComposing) {
			e.preventDefault();
			e.stopPropagation();
			close('key');
			return;
		}
		// Focus trap only for the full-screen (modal) panel on phones.
		if (e.key !== 'Tab' || !mobile || !panelRef.current) return;
		const items = Array.from(panelRef.current.querySelectorAll<HTMLElement>(FOCUSABLE)).filter((el) => el.getClientRects().length > 0);
		if (!items.length) return;
		const first = items[0];
		const last = items[items.length - 1];
		const active = ctx.shadow.activeElement;
		if (e.shiftKey && (active === first || !panelRef.current.contains(active))) {
			e.preventDefault();
			last.focus();
		} else if (!e.shiftKey && (active === last || !panelRef.current.contains(active))) {
			e.preventDefault();
			first.focus();
		}
	}

	// On phones a doc link would reopen the full-screen panel over the doc: let the next page start closed.
	function onClickCapture(e: MouseEvent) {
		if (mobile && (e.target as Element).closest?.('a[href]')) store.saveOpen(false);
	}

	const c = config;
	const name = c?.brand?.name || t('Help');
	const aiOn = !!c?.ai_enabled && !aiDown;
	const human = !!c?.human_enabled && humanOk;
	const humanText = c?.human_reply_text || t('We reply by email');
	const privacyAi = c ? c.privacy_text_ai || c.privacy_text || '' : '';
	const privacyHuman = c ? c.privacy_text_human || c.privacy_text || '' : '';
	const logo = c?.brand?.logo_url ? safeHref(c.brand.logo_url) : null;

	const status =
		view === 'handoff' || view === 'sent'
			? humanText
			: view === 'chat'
				? aiDown
					? t('Assistant unavailable')
					: t('Answers from our docs')
				: aiOn
					? t('Usually answers in seconds')
					: humanText;
	botName = (c?.bot_name || ctx.boot.bot_name || 'Sigma').trim().slice(0, 30) || 'Sigma';
	siteHost = (typeof c?.site_host === 'string' && c.site_host.trim() ? c.site_host : location.host).trim().toLowerCase();
	turnstileKey = typeof c?.turnstile_site_key === 'string' ? c.turnstile_site_key.trim() : '';
	turnstileTheme = c?.turnstile_theme === 'light' || c?.turnstile_theme === 'dark' ? c.turnstile_theme : 'auto';
	remyName = c?.brand?.launcher_icon || ctx.boot.brand?.launcher_icon || 'assistant';
	remyUrl = (c ? c.bot_avatar_url : ctx.boot.bot_avatar_url) || '';
	const intro = (c?.bot_intro || /* translators: %s: the bot's name. */ t('Hi, I\'m %s. Ask me anything and I\'ll answer from our docs.', botName)).slice(0, 300);
	const title = view === 'handoff' || view === 'sent' ? t('Talk to a human') : view === 'chat' ? botName : name;
	const hasBack = view === 'chat' || view === 'handoff' || view === 'sent';
	// Home, v1.13: one card per channel, each switching to "Continue" for the tab's conversation. The visitor's full
	// last question shows on one line (CSS ellipsis, v1.12.2). Handed off → the human card; else → the Remy card.
	const lastVisitor = [...messages].reverse().find((m) => m.sender === 'visitor');
	const lastQuestion = lastVisitor ? lastVisitor.body.replace(/\s+/g, ' ').trim() : '';
	// A handoff sent straight from home has no visitor message: the human card shows what was sent (review on #35).
	const handedQuestion = lastQuestion || (sent?.text ?? '');
	const handedOff = !!sent && !!handedQuestion;
	const aiConvo = !sent && !!lastQuestion;
	const humanCard = !!c?.human_enabled && (handedOff || humanOk);
	const remyCard = !!c?.ai_enabled && (aiConvo || !aiDown);
	const humanTitle = handedOff ? c?.continue_human_title || t('Continue with the team') : c?.human_card_title || t('Talk to a human');
	const remyTitle = aiConvo ? c?.continue_card_title || /* translators: %s: the bot's name. */ t('Continue with %s', botName) : c?.bot_card_title || /* translators: %s: the bot's name. */ t('Talk to %s', botName);

	return (
		<div
			ref={panelRef}
			id="da-panel"
			class="da-panel"
			role="dialog"
			aria-modal={mobile ? 'true' : 'false'}
			aria-labelledby="da-title"
			hidden={!open}
			onKeyDown={onKeyDown}
			onClickCapture={onClickCapture}
		>
			<div class="da-header">
				{hasBack ? (
					<button type="button" class="da-header__btn" aria-label={t('Back')} onClick={goBack}>
						<Icon n="back" />
					</button>
				) : null}
				<div class="da-header__id">
					{/* No logo set: no icon, just the name (2026-11-21; the Delta mark was the old default). */}
					{view === 'chat' ? <BotAvatar size={24} /> : logo ? <img class="da-header__logo" src={logo} alt="" width={24} height={24} /> : null}
					<div class="da-header__text">
						<h2 class="da-header__name" id="da-title">
							{title}
						</h2>
						{c ? <span class="da-header__status">{status}</span> : null}
					</div>
				</div>
				{view === 'chat' && aiOn ? (
					<button
						type="button"
						class="da-header__btn da-header__new"
						data-fk="chat-new"
						aria-label={/* translators: %s: the bot's name. */ t('Start a new chat with %s', botName)}
						title={t('New chat')}
						disabled={busy}
						onClick={() => startBotChat('chat-new')}
					>
						<Icon n="newchat" />
					</button>
				) : null}
				<button type="button" class="da-header__btn" aria-label={t('Close help')} onClick={() => close('button')}>
					<Icon n="close" />
				</button>
			</div>

			{view === 'loading' ? (
				<div class="da-body da-body--home da-center" role="status">
					<span class="da-spinner" aria-hidden="true" />
					<span class="da-sr">{t('Loading help')}</span>
				</div>
			) : view === 'error' ? (
				<div class="da-body da-body--home da-center">
					<p role="alert">{t('Help couldn\'t load. Check your connection and try again.')}</p>
					<button type="button" class="da-btn da-btn--secondary da-btn--sm" data-autofocus onClick={loadConfig}>
						{t('Try again')}
					</button>
				</div>
			) : view === 'off' ? (
				<div class="da-body da-body--home da-center">
					<p data-autofocus tabIndex={-1}>
						{t('Help isn\'t available right now. Please try again later.')}
					</p>
				</div>
			) : !c ? null : view === 'home' ? (
				<>
					<div class="da-body da-body--home">
						<div class="da-greeting">
							<span class="da-eyebrow">{name}</span>
							<p class="da-greeting__title">{c.greeting || t('Hi. How can we help?')}</p>
							<p class="da-greeting__intro">{aiOn ? c.intro : t('Leave a message and the team will reply by email.')}</p>
						</div>
						{humanCard ? (
							<button
								type="button"
								class={`da-option da-option--human${handedOff ? ' da-option--continue' : ''}`}
								data-fk="home-human"
								data-autofocus
								aria-label={handedOff ? `${humanTitle}: ${handedQuestion}` : teamLabel(c)}
								onClick={() => (handedOff ? go('sent', 'home-human') : toHandoff('home-human'))}
							>
								<span class="da-option__text">
									<span class="da-option__title">{humanTitle}</span>
									<span class={`da-option__desc${handedOff ? ' da-option__desc--clip' : ''}`}>
										{handedOff ? handedQuestion : humanText}
									</span>
								</span>
								<Team config={c} />
								<Icon n="right" />
							</button>
						) : null}
						{remyCard ? (
							<button
								type="button"
								class={`da-option da-option--bot${aiConvo ? ' da-option--continue' : ''}`}
								data-fk="home-remy"
								data-autofocus={humanCard ? undefined : true}
								aria-label={aiConvo ? `${remyTitle}: ${lastQuestion}` : undefined}
								onClick={() => (aiConvo ? go('chat', 'home-remy') : startBotChat())}
							>
								<span class="da-option__text">
									<span class="da-option__title">{remyTitle}</span>
									<span class={`da-option__desc${aiConvo ? ' da-option__desc--clip' : ''}`}>{aiConvo ? lastQuestion : c.bot_card_text || t('Handles instant support')}</span>
								</span>
								<BotAvatar size={28} />
								<Icon n="right" />
							</button>
						) : null}
					</div>
					{/* §8.6: no composer on home; the input appears only inside a chat. */}
					<Footer privacy={aiOn ? privacyAi : privacyHuman} url={c.privacy_url} />
				</>
			) : view === 'chat' ? (
				<Chat
					messages={messages}
					busy={busy}
					slow={slow}
					fresh={fresh.current}
					human={human}
					aiOn={aiOn}
					lockedUntil={lockedUntil}
					privacy={privacyAi}
					privacyUrl={c.privacy_url}
					voice={( c.voice_input ?? ctx.boot.voice_input ) === true}
					onSend={(t) => ask(t)}
					stream={stream}
					onRetry={(m) => {
						if (m.partial && m.question) {
							setMessages((l) => l.filter((x) => x.id !== m.id));
							ask(m.question, m.retryOf);
						} else ask(m.body, m.id);
					}}
					onHuman={(key) => toHandoff(key)}
				/>
			) : view === 'handoff' ? (
				<Handoff
					config={c}
					privacy={privacyHuman}
					prefill={[...messages].reverse().find((m) => m.sender === 'visitor')?.body || ''}
					hasChat={messages.some((m) => m.sender === 'visitor')}
					scheme={ctx.host.getAttribute('data-scheme') === 'light' ? 'light' : 'dark'}
					send={async (n, email, text, website, key, token) => {
						let r;
						try {
							r = await withSession((s) => api.handoff(s, n, email, text, website, key, token, { page_url: pageUrl(), locale: pageLocale() }));
						} catch (e) {
							// Already handed off with another key (an earlier submit got through): show the stored result.
							if (e instanceof ApiError && e.code === 'already_handed_off') r = { status: 'emailed' as const };
							else throw e;
						}
						// R1 always answers "emailed". R2's "waiting" will route to the live view here.
						if (r.status === 'emailed' || r.status === 'waiting') {
							commitPending(); // handed off from a new conversation: it replaces the old one now
							setSent({ email, text: text.replace(/\s+/g, ' ').trim() });
							// The sent state replaces the form: Back from it goes where the form was opened from.
							setView('sent');
						}
					}}
				/>
			) : view === 'sent' && sent ? (
				<>
					<div class="da-body da-body--home">
						<div class="da-end" role="status">
							<span class="da-end__icon" aria-hidden="true">
								<Icon n="check" />
							</span>
							<p class="da-end__title" data-autofocus tabIndex={-1}>
								{t('Message sent')}
							</p>
							<p class="da-end__text">
								{/* No visitor email in R1 (PLAN v1.6): the chat is the confirmation; support replies by email. */}
								{/* translators: %s: the visitor's email address. */ t('We\'ll reply to %s by email.', '\u0000').split('\u0000').flatMap((part, i) => (i ? [<strong key="e">{sent.email}</strong>, part] : [part]))}
							</p>
							<button
								type="button"
								class="da-btn da-btn--secondary da-btn--sm"
								onClick={() => {
									history.current = [];
									returnFocus.current = 'home-human';
									setView('home');
								}}
							>
								{t('Back to start')}
							</button>
						</div>
					</div>
					<Footer privacy={privacyHuman} url={c.privacy_url} />
				</>
			) : null}
			{gate ? (
				<div class="da-gate" role="group" aria-labelledby="da-gate-t">
					<p class="da-notice__title" id="da-gate-t">
						{t('Quick check')}
					</p>
					<p class="da-notice__text">{t('Confirm you\'re a person to start the chat.')}</p>
					<Challenge
						scheme={ctx.host.getAttribute('data-scheme') === 'light' ? 'light' : 'dark'}
						onToken={(t) => {
							if (!t) return;
							gate.ok(t);
							setGate(null);
						}}
						onUnavailable={() => {
							// Cloudflare can't be reached from here: try without a token; the server decides (fail-open).
							gate.ok('');
							setGate(null);
						}}
					/>
				</div>
			) : null}
		</div>
	);
}

// ---------------------------------------------------------------------------------------------

function Privacy({ text, url }: { text: string; url: string }) {
	const href = url ? safeHref(url) : null;
	if (!text && !href) return null;
	return (
		<p class="da-privacy" id="da-privacy">
			{text}
			{href ? (
				<>
					{text ? ' ' : null}
					<a href={href}>{t('Privacy')}</a>
				</>
			) : null}
		</p>
	);
}

function Footer({ privacy, url, children, count }: { privacy: string; url: string; children?: JSX.Element | null | (JSX.Element | null)[]; count?: number }) {
	return (
		<div class="da-footer">
			{children}
			{count !== undefined && count >= MAX_TEXT - 200 ? (
				<p class="da-privacy da-count" aria-live="polite">
					{count}/{MAX_TEXT}
				</p>
			) : (
				<Privacy text={privacy} url={url} />
			)}
		</div>
	);
}

function Composer(props: {
	placeholder: string;
	busy: boolean;
	locked: boolean;
	lockText: string;
	onSend: (text: string) => void;
	onCount?: (n: number) => void;
	/** §19.4: the site allows dictation (voice_input); shown only where the browser can do it. */
	voice?: boolean;
}) {
	const { placeholder, busy, locked, lockText, onSend, onCount } = props;
	const [text, setText] = useState('');
	const ref = useRef<HTMLTextAreaElement>(null);
	const textRef = useRef('');
	textRef.current = text;
	const dictation = useDictation(
		() => textRef.current,
		(v) => {
			setText(v);
			onCount?.(v.length);
			requestAnimationFrame(grow);
		},
		MAX_TEXT
	);
	const mic = !!props.voice && canDictate() && !dictation.off;

	function grow() {
		const el = ref.current;
		if (!el) return;
		el.style.height = 'auto';
		el.style.height = Math.min(el.scrollHeight, 120) + 'px';
	}

	function submit(e?: Event) {
		e?.preventDefault();
		if (!text.trim() || busy || locked) return;
		dictation.abort(); // sending ends dictation; nothing is ever sent by it
		onSend(text);
		setText('');
		onCount?.(0);
		requestAnimationFrame(grow);
	}

	return (
		<>
		<form
			class={`da-composer${locked ? ' da-composer--disabled' : ''}`}
			onSubmit={submit}
			onKeyDown={(e) => {
				// Escape stops dictation first; the panel closes only on the next Escape.
				if (e.key === 'Escape' && dictation.listening) {
					e.preventDefault();
					e.stopPropagation();
					dictation.stop();
				}
			}}
		>
			<label class="da-sr" for="da-q">
				{placeholder}
			</label>
			<textarea
				id="da-q"
				ref={ref}
				class="da-composer__input"
				data-autofocus
				rows={1}
				maxLength={MAX_TEXT}
				placeholder={locked ? lockText : placeholder}
				value={text}
				disabled={locked}
				aria-describedby="da-privacy"
				onInput={(e) => {
					const v = (e.target as HTMLTextAreaElement).value;
					setText(v);
					onCount?.(v.length);
					grow();
					if (dictation.message) dictation.clearMessage();
				}}
				onKeyDown={(e) => {
					if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) submit(e);
				}}
			/>
			{mic ? (
				<button
					type="button"
					class="da-composer__mic"
					aria-label={dictation.listening ? t('Stop dictation') : t('Dictate your question')}
					aria-pressed={dictation.listening}
					disabled={locked}
					onClick={() => dictation.toggle()}
				>
					<Icon n="mic" />
				</button>
			) : null}
			<button type="submit" class="da-composer__send" aria-label={t('Send')} disabled={busy || locked || !text.trim()}>
				<Icon n="send" />
			</button>
		</form>
		{mic || dictation.message ? (
			<>
				<p class="da-sr" aria-live="polite">
					{dictation.status}
				</p>
				{dictation.message ? (
					<p class="da-voice-msg" role="status">
						{dictation.message}
					</p>
				) : null}
			</>
		) : null}
		</>
	);
}

function Chat(props: {
	messages: ChatMessage[];
	busy: boolean;
	slow: boolean;
	stream: string | null;
	fresh: Set<string>;
	human: boolean;
	aiOn: boolean;
	lockedUntil: number;
	privacy: string;
	privacyUrl: string;
	onSend: (text: string) => void;
	onRetry: (m: ChatMessage) => void;
	onHuman: (key: string) => void;
	voice: boolean;
}) {
	const { messages, busy, slow, stream, fresh, human, aiOn, lockedUntil, privacy, privacyUrl, onSend, onRetry, onHuman, voice } = props;
	const [count, setCount] = useState(0);
	const bodyRef = useRef<HTMLDivElement>(null);
	const nearBottom = useRef(true);
	const locked = lockedUntil > Date.now();
	const last = messages[messages.length - 1];
	// A new Remy chat shows only the intro: no question yet, so "Ask a question" until the first visitor message.
	const asked = messages.some((m) => m.sender === 'visitor');
	const limitMsg = [...messages].reverse().find((m) => m.card === 'limit');

	// Stick to the bottom unless the visitor scrolled up > 80px (SPEC §7.6). A long answer is shown from its top.
	// Opening the chat (e.g. from the Continue card) shows the end of the transcript.
	const opened = useRef(false);
	useLayoutEffect(() => {
		const body = bodyRef.current;
		if (!body) return;
		if (!opened.current) {
			opened.current = true;
			body.scrollTop = body.scrollHeight;
			return;
		}
		if (!nearBottom.current) return;
		// While streaming, follow the text but never scroll the start of the answer out of view.
		const live = body.querySelector<HTMLElement>('.da-stream');
		if (live) {
			body.scrollTop = Math.min(body.scrollHeight - body.clientHeight, live.offsetTop - 16);
			return;
		}
		const el = body.querySelector<HTMLElement>('.da-log > :last-child');
		if (el && !busy && el.classList.contains('da-msg--ai') && el.offsetHeight > body.clientHeight - 48) body.scrollTop = el.offsetTop - 16;
		else body.scrollTop = body.scrollHeight;
	}, [messages.length, busy, stream]);

	return (
		<>
			<div
				class="da-body"
				ref={bodyRef}
				onScroll={(e) => {
					const b = e.currentTarget as HTMLDivElement;
					nearBottom.current = b.scrollHeight - b.scrollTop - b.clientHeight < 80;
				}}
			>
				<div class="da-log" role="log" aria-live="polite" aria-relevant="additions" aria-label={t('Conversation')}>
					{messages.map((m) => (
						<Message key={m.id} m={m} isNew={fresh.has(m.id)} human={human} busy={busy} onRetry={onRetry} onHuman={onHuman} />
					))}
				</div>
				{busy && stream ? (
					<div class="da-msg da-msg--ai da-stream" aria-busy="true">
						<AiLabel />
						{/* Plain text only while streaming: links are validated at the end (§8.3). */}
						<div class="da-msg__body da-stream__text">{stream}</div>
					</div>
				) : busy ? (
					<div class="da-msg da-msg--ai" aria-hidden="true">
						<AiLabel />
						<span class="da-typing-row">
							<span class="da-typing">
								<i />
								<i />
								<i />
							</span>
							{slow ? <span class="da-typing__slow">{t('Still reading the docs…')}</span> : null}
						</span>
					</div>
				) : null}
				<span class="da-sr" role="status">
					{busy ? t('The assistant is writing an answer') : ''}
				</span>
			</div>
			<Footer privacy={privacy} url={privacyUrl} count={count}>
				{/* §8.7 v1.14: no always-visible "Talk to a human" here; the offer appears under a reply only when the
				    docs don't cover it or the visitor asked for a person. Home's human card is always there. */}
				{aiOn ? (
					<Composer
						placeholder={last?.notInDocs ? t('Ask something else') : asked ? t('Ask a follow-up') : t('Ask a question')}
						busy={busy}
						locked={locked}
						lockText={limitMsg ? limitText(limitMsg).placeholder : ''}
						onSend={onSend}
						onCount={setCount}
						voice={voice}
					/>
				) : null}
			</Footer>
		</>
	);
}

function Message(props: {
	m: ChatMessage;
	isNew: boolean;
	human: boolean;
	busy: boolean;
	onRetry: (m: ChatMessage) => void;
	onHuman: (key: string) => void;
}) {
	const { m, isNew, human, busy, onRetry, onHuman } = props;
	const alertRole = isNew ? 'alert' : undefined;

	if (m.card === 'limit') {
		return (
			<div class="da-notice da-notice--warning" role={alertRole}>
				<Icon n="clock" />
				<p class="da-notice__title">{t('You\'ve reached the question limit')}</p>
				<p class="da-notice__text">{limitText(m).body}</p>
				{human ? (
					<div class="da-notice__actions">
						<button type="button" class="da-btn da-btn--secondary da-btn--sm" data-fk={'h' + m.id} onClick={() => onHuman('h' + m.id)}>
							{t('Talk to a human')}
						</button>
					</div>
				) : null}
			</div>
		);
	}
	if (m.card === 'down') {
		return (
			<div class="da-notice da-notice--danger" role={alertRole}>
				<Icon n="alert" />
				<p class="da-notice__title">{t('The assistant is unavailable right now')}</p>
				<p class="da-notice__text">{human ? t('You can still reach a person. We\'ll reply by email.') : t('Please try again later.')}</p>
				{human ? (
					<div class="da-notice__actions">
						<button type="button" class="da-btn da-btn--primary da-btn--sm" data-fk={'h' + m.id} onClick={() => onHuman('h' + m.id)}>
							{t('Talk to a human')}
						</button>
					</div>
				) : null}
			</div>
		);
	}

	if (m.sender === 'visitor') {
		return (
			<div class="da-msg da-msg--visitor">
				<span class="da-sr">{t('You:')}{ ' ' }</span>
				<div class="da-msg__body">{m.body}</div>
				{m.failed ? (
					<p class="da-msg__meta da-msg__meta--error" role={alertRole}>
						<Icon n="alert" />
						{m.busy ? /* translators: %s: the bot's name. */ t('%s is busy, try again in a few seconds.', botName) : t('Not sent.')}
						<button type="button" class="da-link-btn" disabled={busy} onClick={() => onRetry(m)}>
							{t('Try again')}
						</button>
					</p>
				) : null}
			</div>
		);
	}

	if (m.sender === 'system') return <p class="da-system">{m.body}</p>;

	if (m.partial) {
		return (
			<div class="da-msg da-msg--ai da-msg--partial">
				<AiLabel />
				<div class="da-msg__body da-stream__text">{m.body}</div>
				<p class="da-msg__meta da-msg__meta--error" role={alertRole}>
					<Icon n="alert" />
					{t('Not finished.')}
					<button type="button" class="da-link-btn" disabled={busy} onClick={() => onRetry(m)}>
						{t('Try again')}
					</button>
				</p>
			</div>
		);
	}

	// ai (and, in release 2, agent)
	const links = (m.links || []).map((l) => ({ ...l, href: safeHref(l.url) })).filter((l) => l.href);
	// §10b: a link in the text is clickable only if the server listed it, or it's on the site's own host.
	const scope = { allowed: new Set(links.map((l) => linkKey(l.href!))), host: siteHost };
	return (
		<div class={`da-msg da-msg--${m.sender}${m.intro ? ' da-msg--intro' : ''}`}>
			{m.sender === 'agent' ? <span class="da-msg__label">{t('Support')}</span> : <AiLabel />}
			<div class="da-msg__body">
				<Markdown text={m.body} scope={scope} />
			</div>
			{links.length ? (
				<div class="da-sources">
					<span class="da-msg__label">{t('From the docs')}</span>
					<ul class="da-sources__list">
						{links.map((l) => (
							<li key={l.href}>
								<a class="da-chip" href={l.href!}>
									<Icon n="doc" />
									<span class="da-chip__title">{l.title || l.href}</span>
								</a>
							</li>
						))}
					</ul>
				</div>
			) : null}
			{(m.notInDocs || m.handoffRequested) && human ? (
				<div class={`da-notice${m.handoffRequested ? ' da-notice--request' : ''}`}>
					<Icon n="person" />
					{/* §8.7 v1.14: a request for a person wins over not-in-docs (both can be set). */}
					<p class="da-notice__title">{m.handoffRequested ? t('Talk to a human') : t('Talk to a human?')}</p>
					<p class="da-notice__text">
						{/* Plan v1.6.1: with doc links the answer may be right (marker misfire), so don't claim it isn't in the docs. */}
						{m.handoffRequested
							? t('Send your question to the team and we\'ll reply by email.')
							: links.length
								? t('Need more than the docs? Send it to the team and we\'ll reply by email.')
								: t('Your question isn\'t in the docs. Send it to the team and we\'ll reply by email.')}
					</p>
					<div class="da-notice__actions">
						<button type="button" class="da-btn da-btn--primary da-btn--sm" data-fk={'h' + m.id} onClick={() => onHuman('h' + m.id)}>
							{t('Talk to a human')}
						</button>
					</div>
				</div>
			) : null}
		</div>
	);
}

type Field = 'name' | 'email' | 'text';
const FIELDS: Field[] = ['name', 'email', 'text'];

function Handoff(props: {
	config: SiteConfig;
	privacy: string;
	prefill: string;
	hasChat: boolean;
	send: (name: string, email: string, text: string, website: string, key: string, turnstile?: string) => Promise<void>;
	scheme: 'light' | 'dark';
}) {
	const { config, privacy, prefill, hasChat, send, scheme } = props;
	// §10b: Turnstile on the form when the site turned it on. Its script loads only now that the form is open.
	const [token, setToken] = useState('');
	const [challengeKey, setChallengeKey] = useState(0); // remount = a fresh challenge (tokens are single-use)
	const [unavailable, setUnavailable] = useState(false); // the challenge couldn't load: send without a token
	const [values, setValues] = useState<Record<Field, string>>({ name: '', email: '', text: prefill });
	const [website, setWebsite] = useState('');
	const [errors, setErrors] = useState<Partial<Record<Field, string>>>({});
	const [shown, setShown] = useState(false); // after the first submit, validate live
	const [notice, setNotice] = useState<{ tone: 'warning' | 'danger'; text: string } | null>(null);
	const [sending, setSending] = useState(false);
	const idem = useRef<{ key: string; what: string } | null>(null);
	const formRef = useRef<HTMLFormElement>(null);

	function check(f: Field, v: string): string | undefined {
		v = v.trim();
		if (f === 'name' && !v) return t('Enter your name.');
		if (f === 'email' && !EMAIL_RE.test(v)) return t('Enter an email like name@example.com.');
		if (f === 'text' && !v) return t('Write a message.');
		return undefined;
	}

	function focusFirst(e: Partial<Record<Field, string>>) {
		const f = FIELDS.find((k) => e[k]);
		if (f) requestAnimationFrame(() => formRef.current?.querySelector<HTMLElement>(`#da-${f}`)?.focus());
	}

	async function onSubmit(ev: Event) {
		ev.preventDefault();
		if (sending) return;
		const e: Partial<Record<Field, string>> = {};
		for (const f of FIELDS) {
			const msg = check(f, values[f]);
			if (msg) e[f] = msg;
		}
		setErrors(e);
		setShown(true);
		setNotice(null);
		if (Object.keys(e).length) return focusFirst(e);
		if (turnstileKey && !token && !unavailable) {
			setNotice({ tone: 'warning', text: t('Please complete the security check above.') });
			return;
		}
		setSending(true);
		const name = values.name.trim().slice(0, 100);
		const email = values.email.trim().slice(0, 190);
		const text = values.text.trim().slice(0, MAX_TEXT);
		// One key per submit (PLAN v1.7.1): a retry of the same values (after a timeout or a network error) reuses
		// it, so the server sends the support email once; a submit after an edit gets a new key.
		const what = JSON.stringify([name, email, text]);
		if (!idem.current || idem.current.what !== what) idem.current = { key: uuid4(), what };
		try {
			await send(name, email, text, website, idem.current.key, token || undefined);
		} catch (err) {
			const x = err instanceof ApiError ? err : new ApiError(0, 'unknown', '');
			if (token) {
				setToken(''); // spent by the server's siteverify: the next submit needs a new one
				setChallengeKey((n) => n + 1);
			}
			const fe: Partial<Record<Field, string>> = {};
			const fieldOf = (k: string) => (k === 'message' ? 'text' : k) as Field;
			if (x.field && FIELDS.includes(fieldOf(x.field))) {
				const f = fieldOf(x.field);
				fe[f] = x.code === 'text_too_long' ? t('Keep your message under 2,000 characters.') : check(f, '') || x.message;
			}
			for (const [k, msg] of Object.entries(x.params)) if (FIELDS.includes(fieldOf(k))) fe[fieldOf(k)] = String(msg);
			if (Object.keys(fe).length) {
				setErrors(fe);
				focusFirst(fe);
			} else if (x.code === 'turnstile_required' && !token) {
				// Sent without a token but Cloudflare is reachable after all: show the challenge again.
				setUnavailable(false);
				setChallengeKey((n) => n + 1);
				setNotice({ tone: 'warning', text: t('Please complete the security check above.') });
			} else if (x.code === 'turnstile_required' || x.code === 'turnstile_failed') setNotice({ tone: 'warning', text: t('The security check didn\'t go through. Please try it again.') });
			else if (x.code === 'handoff_busy') setNotice({ tone: 'warning', text: t('Our inbox is busy, try again later.') });
			else if (x.status === 429) setNotice({ tone: 'warning', text: t('Too many messages from this connection. Try again in an hour.') });
			else if (x.code === 'human_unavailable') setNotice({ tone: 'danger', text: t('Messages to the team are switched off right now. Please try again later.') });
			else setNotice({ tone: 'danger', text: t('Couldn\'t send your message. Check your connection and try again.') });
			setSending(false);
		}
	}

	const field = (f: Field, label: string, input: (p: Record<string, unknown>) => JSX.Element) => {
		const err = errors[f];
		const update = (v: string) => {
			setValues((o) => ({ ...o, [f]: v }));
			if (shown) setErrors((o) => ({ ...o, [f]: check(f, v) }));
		};
		return (
			<div class="da-field">
				<label class="da-label" for={`da-${f}`}>
					{label}
				</label>
				{input({
					id: `da-${f}`,
					class: 'da-input',
					value: values[f],
					required: true,
					readOnly: sending,
					'data-autofocus': f === 'name' ? true : undefined,
					'aria-invalid': err ? 'true' : undefined,
					'aria-describedby': err ? `da-${f}-err` : undefined,
					onInput: (e: Event) => update((e.target as HTMLInputElement).value),
					onBlur: (e: Event) => shown && update((e.target as HTMLInputElement).value),
				})}
				{err ? (
					<p class="da-error" id={`da-${f}-err`}>
						<Icon n="alert" />
						{err}
					</p>
				) : null}
			</div>
		);
	};

	return (
		<>
			<div class="da-body da-body--home">
				<form class="da-form" id="da-form" ref={formRef} onSubmit={onSubmit} noValidate>
					<p class="da-greeting__intro">{t('Leave your details and the team will reply by email.')}</p>
					{field('name', t('Name'), (p) => <input {...p} type="text" autocomplete="name" maxLength={100} />)}
					{field('email', t('Email'), (p) => <input {...p} type="email" autocomplete="email" inputMode="email" maxLength={190} spellcheck={false} />)}
					{field('text', t('Message'), (p) => <textarea {...p} maxLength={MAX_TEXT} />)}
					{hasChat ? <p class="da-hint">{t('We\'ll include your chat with the assistant, so you don\'t have to repeat it.')}</p> : null}
					<div class="da-hp" aria-hidden="true">
						<label for="da-website">{t('Website')}</label>
						<input id="da-website" name="website" type="text" tabIndex={-1} autocomplete="off" value={website} onInput={(e) => setWebsite((e.target as HTMLInputElement).value)} />
					</div>
					{turnstileKey ? <Challenge key={challengeKey} onToken={setToken} onUnavailable={() => setUnavailable(true)} scheme={scheme} /> : null}
					{notice ? (
						<div class={`da-notice da-notice--${notice.tone}`} role="alert">
							<Icon n={notice.tone === 'warning' ? 'clock' : 'alert'} />
							<p class="da-notice__text">{notice.text}</p>
						</div>
					) : null}
				</form>
			</div>
			<Footer privacy={privacy} url={config.privacy_url}>
				<button type="submit" form="da-form" class="da-btn da-btn--primary da-btn--block" aria-disabled={sending ? 'true' : undefined}>
					{sending ? t('Sending…') : t('Send message')}
				</button>
			</Footer>
		</>
	);
}

// ---------------------------------------------------------------------------------------------
// Team avatars on the home row (plan v1.9 §8.4). Decorative: the button carries the names.

/** "Talk to a human. Team: Maya, Raj and 2 others", or undefined (no team: the visible text is the name). */
function teamLabel(c: SiteConfig): string | undefined {
	const team = (c.team || []).filter((m) => m && typeof m.name === 'string' && m.name.trim());
	if (!team.length) return undefined;
	const total = Math.max(c.team_total || 0, team.length);
	const names = team.map((m) => m.name.trim());
	let list: string;
	if (total === 1) list = names[0];
	else if (total === 2) list = /* translators: 1, 2: names. */ t('%1$s and %2$s', names[0], names[1]);
	else if (total === 3 && names.length >= 3) list = /* translators: 1, 2, 3: names. */ t('%1$s, %2$s and %3$s', names[0], names[1], names[2]);
	else list = /* translators: 1, 2: names, 3: how many more people. */ t('%1$s, %2$s and %3$s others', names[0], names[1], total - 2);
	return /* translators: %s: list of names. */ t('Talk to a human. Team: %s', list);
}

function Team({ config }: { config: SiteConfig }) {
	const team = (config.team || []).filter((m) => m && typeof m.name === 'string' && m.name.trim());
	const total = Math.max(config.team_total || 0, team.length);
	const shown = team.slice(0, 3);
	const more = total - shown.length;
	// A copy that fails to load (deleted file, CDN miss) falls back to the initial, never a broken image.
	const [broken, setBroken] = useState<Record<number, true>>({});
	if (!team.length) return null; // after the hook, so hooks keep their order
	return (
		<span class="da-team" aria-hidden="true">
			{shown.map((m, i) => {
				const src = m.avatar_url && !broken[i] ? safeHref(m.avatar_url) : null;
				const style = { zIndex: String(10 - i) }; // the first-picked sits on top
				return src ? (
					<img
						key={i}
						class="da-team__a"
						src={src}
						alt=""
						width={28}
						height={28}
						loading="lazy"
						decoding="async"
						style={style}
						onError={() => setBroken((b) => ({ ...b, [i]: true }))}
					/>
				) : (
					<span key={i} class="da-team__a da-team__i" style={style}>
						{Array.from(m.name.trim())[0].toUpperCase()}
					</span>
				);
			})}
			{more > 0 ? <span class="da-team__a da-team__more">+{more}</span> : null}
		</span>
	);
}

// ---------------------------------------------------------------------------------------------
// Cloudflare Turnstile (§10b, optional): rendered into our shadow root; the script loads on first use.

/** onUnavailable: the challenge can't run (its script didn't load, or Cloudflare's error-callback fired). The
 *  form may then send without a token; the server accepts that only if its own probe finds Cloudflare down
 *  (§10b fail-open, 0.1.7), and otherwise answers 403 turnstile_required, which brings the challenge back. */
function Challenge({ onToken, onUnavailable, scheme }: { onToken: (token: string) => void; onUnavailable?: () => void; scheme: 'light' | 'dark' }) {
	const el = useRef<HTMLDivElement>(null);
	const [failed, setFailed] = useState(false);
	const down = () => {
		setFailed(true);
		onUnavailable?.();
	};
	useEffect(() => {
		let id: string | undefined;
		let gone = false;
		loadTurnstile()
			.then((t) => {
				if (gone || !el.current) return;
				id = t.render(el.current, {
					sitekey: turnstileKey,
					callback: (v: string) => {
						setFailed(false);
						onToken(v);
					},
					'expired-callback': () => onToken(''),
					'error-callback': () => {
						onToken('');
						down();
					},
					theme: turnstileTheme === 'auto' ? scheme : turnstileTheme, // auto: the widget's scheme, not the OS
					size: 'flexible',
				});
			})
			.catch(() => !gone && down());
		return () => {
			gone = true;
			if (id) (window as unknown as { turnstile?: { remove(i: string): void } }).turnstile?.remove(id);
		};
	}, []);
	return (
		<div class="da-challenge">
			<div ref={el} class="da-challenge__box" />
			{failed ? <p class="da-hint">{t('The security check couldn\'t load. You can still send your message.')}</p> : null}
		</div>
	);
}
