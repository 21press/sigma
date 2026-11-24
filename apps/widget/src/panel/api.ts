// Client for /wp-json/sigma/v1 (the visitor routes). Visitor auth = X-DA-Token, no nonce, no cookies.
import type { DocLink, SiteConfig } from '../types';
import { t } from '../i18n';

export class ApiError extends Error {
	constructor(
		public status: number,
		public code: string,
		message: string,
		public retryAfter = 0,
		public params: Record<string, string> = {},
		public scope: 'hour' | 'day' | '' = '',
		/** 400 field errors: data.field (invalid_name | invalid_email | invalid_text | text_too_long). */
		public field = '',
		/** 503 ai_unavailable reason: status_<code> / http_error = provider hiccup; budget_spent, no_key… = off. */
		public reason = '',
	) {
		super(message);
	}
	/** Set for an error after the stream started (§8.3): partial text may be on screen. */
	midStream = false;
	retryable = false;
}

export interface Session {
	uuid: string;
	token: string;
}

export interface AskResult {
	message: { id: number | string; sender: 'ai'; body: string; links?: DocLink[] };
	/** false: the docs don't cover it → "Talk to a human?" card (PLAN §8.1). */
	answered: boolean;
	/** §8.7 v1.14: the visitor asked for a person (model marker or keyword fallback). Absent on older servers. */
	handoff_requested?: boolean;
	/** Whether the human path is on (settings, budget, rate limit). */
	can_handoff: boolean;
}

/** Where the conversation started (§10b, lazy conversations): the first ask or handoff creates the row with it. */
export interface Origin {
	page_url: string;
	locale: string;
}

export interface HandoffResult {
	status: 'emailed' | 'waiting';
	deadline_at?: string;
}

/** nonce: only while "Show to admins only" is on; then cookies + X-WP-Nonce identify the admin (PR #2). */
export function createApi(root: string, nonce?: string) {
	const base = root.endsWith('/') ? root : root + '/';

	function headersFor(body: unknown, token: string | undefined, accept: string): Record<string, string> {
		const headers: Record<string, string> = { Accept: accept };
		if (body !== undefined) headers['Content-Type'] = 'application/json';
		if (token) headers['X-DA-Token'] = token;
		if (nonce) headers['X-WP-Nonce'] = nonce;
		return headers;
	}

	async function send(method: string, path: string, body: unknown, token: string | undefined, accept: string, ctrl: AbortController): Promise<Response> {
		try {
			return await fetch(base + path, {
				method,
				headers: headersFor(body, token, accept),
				body: body === undefined ? undefined : JSON.stringify(body),
				credentials: nonce ? 'same-origin' : 'omit',
				cache: 'no-store',
				signal: ctrl.signal,
			});
		} catch {
			throw new ApiError(0, ctrl.signal.aborted ? 'timeout' : 'network', 'network');
		}
	}

	/** A non-2xx response → ApiError from the WordPress error JSON. */
	async function fail(res: Response): Promise<never> {
		let data: any = null;
		try {
			data = await res.json();
		} catch {
			/* empty or non-JSON body */
		}
		const d = (data && data.data) || {};
		const retry = Number(d.retry_after ?? res.headers.get('Retry-After') ?? 0) || 0;
		const params = d.params && typeof d.params === 'object' ? d.params : {};
		const scope = d.scope === 'day' || d.scope === 'hour' ? d.scope : '';
		const field = typeof d.field === 'string' ? d.field : '';
		const reason = typeof d.reason === 'string' ? d.reason : '';
		throw new ApiError(res.status, String((data && data.code) || 'http_' + res.status), String((data && data.message) || ''), retry, params, scope, field, reason);
	}

	async function call<T>(method: string, path: string, body?: unknown, token?: string, timeoutMs = 20000): Promise<T> {
		const ctrl = new AbortController();
		const timer = setTimeout(() => ctrl.abort(), timeoutMs);
		try {
			const res = await send(method, path, body, token, 'application/json', ctrl);
			if (!res.ok) return await fail(res);
			try {
				return (await res.json()) as T;
			} catch {
				return null as T;
			}
		} finally {
			clearTimeout(timer);
		}
	}

	/**
	 * /ask as a stream (PLAN §8.3): `Accept: text/event-stream`, read with fetch + a ReadableStream reader
	 * (EventSource can't POST or send X-DA-Token). onDelta gets plain text as it arrives. Resolves with the
	 * `done` payload (the JSON /ask shape). A response that isn't text/event-stream (old server, a host that
	 * can't stream) is read as today's JSON. A mid-stream `error` throws an ApiError with midStream = true.
	 * Limits: 75 s in total (the server caps at 60 s), 30 s without a byte (the server pings every 10 s).
	 */
	/** onAccepted: the server took the question (the stream's `start` event, or a 200 on the JSON path). */
	async function askStream(s: Session, text: string, onDelta: (t: string) => void, onAccepted?: () => void, origin?: Origin): Promise<AskResult> {
		const ctrl = new AbortController();
		const total = setTimeout(() => ctrl.abort(), 75000);
		let idle = setTimeout(() => ctrl.abort(), 30000);
		const poke = () => {
			clearTimeout(idle);
			idle = setTimeout(() => ctrl.abort(), 30000);
		};
		try {
			const res = await send('POST', `conversations/${encodeURIComponent(s.uuid)}/ask`, { text, ...origin }, s.token, 'text/event-stream, application/json', ctrl);
			if (!res.ok) return await fail(res);
			if (!(res.headers.get('Content-Type') || '').includes('text/event-stream') || !res.body) {
				onAccepted?.();
				return (await res.json()) as AskResult; // no stream: today's behaviour
			}
			const reader = res.body.getReader();
			const decoder = new TextDecoder();
			let buf = '';
			try {
				for (;;) {
					let chunk: ReadableStreamReadResult<Uint8Array>;
					try {
						chunk = await reader.read();
					} catch {
						throw Object.assign(new ApiError(0, ctrl.signal.aborted ? 'timeout' : 'network', 'network'), { midStream: true, retryable: true });
					}
					if (chunk.done) break;
					poke();
					buf += decoder.decode(chunk.value, { stream: true }).replace(/\r\n?/g, '\n');
					let cut: number;
					while ((cut = buf.indexOf('\n\n')) >= 0) {
						const block = buf.slice(0, cut);
						buf = buf.slice(cut + 2);
						let event = 'message';
						const data: string[] = [];
						for (const line of block.split('\n')) {
							if (line.startsWith(':')) continue; // comment / ping
							if (line.startsWith('event:')) event = line.slice(6).trim();
							else if (line.startsWith('data:')) data.push(line.slice(5).replace(/^ /, ''));
						}
						if (!data.length) continue;
						let payload: any;
						try {
							payload = JSON.parse(data.join('\n'));
						} catch {
							continue;
						}
						if (event === 'start') onAccepted?.();
						else if (event === 'delta' && typeof payload.text === 'string') onDelta(payload.text);
						else if (event === 'done') return payload as AskResult;
						else if (event === 'error') {
							const d = (payload && payload.data) || {};
							throw Object.assign(new ApiError(0, String(payload.code || 'stream_error'), String(payload.message || ''), 0, {}, '', '', typeof d.reason === 'string' ? d.reason : ''), {
								midStream: true,
								retryable: d.retryable !== false,
							});
						}
					}
				}
			} finally {
				reader.cancel().catch(() => {});
			}
			throw Object.assign(new ApiError(0, 'stream_incomplete', ''), { midStream: true, retryable: true });
		} finally {
			clearTimeout(total);
			clearTimeout(idle);
		}
	}

	return {
		config: () => call<SiteConfig>('GET', 'config'),
		/** turnstile_token: only when the server asked for one (403 turnstile_required, §10b). */
		create: (page_url: string, locale: string, turnstile_token?: string) =>
			call<Session & { greeting?: string }>('POST', 'conversations', turnstile_token ? { page_url, locale, turnstile_token } : { page_url, locale }),
		ask: (s: Session, text: string, origin?: Origin) => call<AskResult>('POST', `conversations/${encodeURIComponent(s.uuid)}/ask`, { text, ...origin }, s.token, 45000),
		askStream,
		/** idempotency_key (PLAN v1.7.1): same key on every retry of one submit, so a retry never sends twice. */
		handoff: (s: Session, name: string, email: string, text: string, website: string, idempotency_key: string, turnstile_token?: string, origin?: Origin) =>
			// 45 s like /ask: the server sends the support email (built-in SMTP, 10 s per step) inside this request.
			// A shorter timeout would show "Couldn't send" for a stored message and invite a duplicate.
			call<HandoffResult>(
				'POST',
				`conversations/${encodeURIComponent(s.uuid)}/handoff`,
				{ name, email, text, website, idempotency_key, ...(turnstile_token ? { turnstile_token } : {}), ...origin },
				s.token,
				45000,
			),
	};
}

export type Api = ReturnType<typeof createApi>;

const PREVIEW_ANSWER = t('This is a preview, so nothing was sent. On your site, the agent answers from your content here.');

/** Admin live preview: the same calls as createApi, answered locally. No request leaves the page. */
/** The settings screen's hook for "Try with real AI" (§7.7): POST /admin/preview/ask with the unsaved instructions. */
type PreviewAsk = (question: string) => Promise<{ body?: string; links?: DocLink[]; answered?: boolean; handoff_requested?: boolean }>;

export function createPreviewApi(config: () => SiteConfig): Api {
	const answer = (): AskResult => ({ message: { id: 'p' + Date.now().toString(36), sender: 'ai', body: PREVIEW_ANSWER }, answered: true, can_handoff: true });
	const later = <T>(v: T) => new Promise<T>((r) => setTimeout(() => r(v), 400));
	/** With "Try with real AI" on, the admin's answer; the widget then renders it like any answer (safe Markdown, link rules). */
	const real = (text: string): Promise<AskResult> | null => {
		const hook = (window as unknown as { SigmaPreviewAsk?: PreviewAsk }).SigmaPreviewAsk;
		// The settings screen installs the hook only while its switch is on (no wait for the next config event).
		if (typeof hook !== 'function') return null;
		return hook(text).then(
			(r) => ({
				message: { id: 'p' + Date.now().toString(36), sender: 'ai', body: String(r?.body ?? ''), links: Array.isArray(r?.links) ? r.links : [] },
				answered: r?.answered !== false,
				handoff_requested: r?.handoff_requested === true,
				can_handoff: true,
			}),
			(e: { code?: string; message?: string; data?: { status?: number; retry_after?: number; field?: string; reason?: string } }) => {
				const d = e?.data || {};
				throw new ApiError(Number(d.status) || 0, String(e?.code || 'unknown'), String(e?.message || ''), Number(d.retry_after) || 0, {}, 'hour', String(d.field || ''), String(d.reason || ''));
			},
		);
	};
	return {
		config: () => Promise.resolve(config()),
		create: () => Promise.resolve({ uuid: 'preview', token: 'preview' }),
		ask: (_s: Session, text: string) => real(text) ?? later(answer()),
		askStream: async (_s: Session, text: string, onDelta: (t: string) => void, onAccepted?: () => void) => {
			onAccepted?.();
			const a = await (real(text) ?? later(answer()));
			onDelta(a.message.body);
			return a;
		},
		handoff: () => later<HandoffResult>({ status: 'emailed' }),
	};
}

/** Minutes from retry_after, rounded up (SPEC §6.6). */
export const minutes = (seconds: number) => Math.max(1, Math.ceil(seconds / 60));

/** UUID v4. crypto.randomUUID needs a secure context; plain-http sites fall back to getRandomValues. */
export function uuid4(): string {
	if (typeof crypto.randomUUID === 'function') return crypto.randomUUID();
	const b = crypto.getRandomValues(new Uint8Array(16));
	b[6] = (b[6] & 0x0f) | 0x40;
	b[8] = (b[8] & 0x3f) | 0x80;
	const h = Array.from(b, (x) => x.toString(16).padStart(2, '0')).join('');
	return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`;
}
