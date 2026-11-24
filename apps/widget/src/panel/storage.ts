// Visitor-side persistence (PLAN §8, §8.1):
// - localStorage: the conversation token, 24 h.
// - sessionStorage: the rendered transcript, current view and open state, so the same-tab navigation that doc
//   links cause redraws the chat. Cleared when the tab closes. Never blocks if storage is off.
import { CHAT_KEY, OPEN_KEY, TOKEN_KEY } from '../keys';
import type { ChatMessage } from '../types';

const TTL_MS = 24 * 60 * 60 * 1000;
const MAX_MESSAGES = 60;

export interface Token {
	uuid: string;
	token: string;
}

export interface Chat {
	messages: ChatMessage[];
	view: string;
	/** text: what was sent (v1.13 follow-up: a handoff from home has no visitor message for the Continue card). */
	sent?: { email: string; text?: string } | null;
	lockedUntil?: number;
	/** Back history (SPEC §7.1): where each view was opened from, and the control that opened it. */
	history?: { view: string; fk: string | null }[];
}

/** Admin preview: read and write nothing, so it never touches a visitor's chat, token or open state. */
let memoryOnly = false;
export const setMemoryOnly = () => {
	memoryOnly = true;
};

function get<T>(store: () => Storage, key: string): T | null {
	if (memoryOnly) return null;
	try {
		const raw = store().getItem(key);
		return raw ? (JSON.parse(raw) as T) : null;
	} catch {
		return null;
	}
}

function put(store: () => Storage, key: string, value: unknown): void {
	if (memoryOnly) return;
	try {
		if (value === null) store().removeItem(key);
		else store().setItem(key, typeof value === 'string' ? value : JSON.stringify(value));
	} catch {
		/* storage full or disabled: the chat still works for this page view */
	}
}

const local = () => localStorage;
const session = () => sessionStorage;

export function loadToken(): Token | null {
	const t = get<Token & { at: number }>(local, TOKEN_KEY);
	if (!t || typeof t.at !== 'number' || Date.now() - t.at > TTL_MS || !t.uuid || !t.token) {
		put(local, TOKEN_KEY, null);
		return null;
	}
	return { uuid: t.uuid, token: t.token };
}

export const saveToken = (t: Token | null) => put(local, TOKEN_KEY, t && { ...t, at: Date.now() });

export function loadChat(): Chat | null {
	const c = get<Chat>(session, CHAT_KEY);
	return c && Array.isArray(c.messages) ? c : null;
}

export const saveChat = (c: Chat | null) => put(session, CHAT_KEY, c && { ...c, messages: c.messages.slice(-MAX_MESSAGES) });

export const saveOpen = (open: boolean) => put(session, OPEN_KEY, open ? '1' : null);
