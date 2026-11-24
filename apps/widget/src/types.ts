// Shapes shared by the bubble and the panel. REST shapes follow the visitor routes, plus the optional
// fields from the design spec (all optional: the widget works without them).

export interface Brand {
	name?: string;
	/** PLAN §8 single colour (treated as the light-scheme colour). */
	accent?: string;
	color_light?: string;
	color_dark?: string;
	logo_url?: string | null;
	scheme?: 'auto' | 'light' | 'dark';
	position?: 'left' | 'right';
	offset_x?: number;
	offset_y?: number;
	/** "inherit" = the page's body font, "system" = OS UI font (PLAN §8.1). */
	font?: 'inherit' | 'system';
	/** Panel palette per scheme (#39 follow-up): Background and Text; the rest is generated (color.ts). */
	bg_light?: string;
	bg_dark?: string;
	text_light?: string;
	text_dark?: string;
	/** Launcher icon (#41): "assistant" (the plugin mark, default), "chat", "sparkles" or "help". */
	/** Remy's icon everywhere he appears (plan §8.8); `image` = the uploaded bot_avatar (bot_avatar_url). */
	launcher_icon?: 'assistant' | 'remy' | 'chat' | 'sparkles' | 'help' | 'image';
}

/** Inline data the plugin prints before widget.js (window.SigmaWidget). Cache-safe: no nonce, nothing per user. */
export interface BootData {
	/** REST base, e.g. https://site/wp-json/sigma/v1/ */
	root: string;
	/** §19.4: dictation allowed (inline, so the composer knows before /config). */
	voice_input?: boolean;
	/** §8.5: the AI's name, the launcher teaser and the beacon pulse travel inline (no REST before opening). */
	bot_name?: string;
	/** §8.6: the uploaded Remy avatar (local copy), or empty for the default inline SVG; the teaser shows it before any REST call. */
	bot_avatar_url?: string;
	teaser?: { enabled?: boolean; text?: string };
	pulse?: boolean;
	/** Only while "Show to admins only" is on: sent as X-WP-Nonce with cookies. */
	nonce?: string;
	/** Optional look settings, so the launcher is right before /config loads. */
	brand?: Brand;
	/** @deprecated use brand.color_light */
	accent?: string;
	/**
	 * Admin live preview only (Settings → Bubble). Never printed on the site. When set, the widget mounts like on the site (or inside
	 * #da-preview-stage if the page has one), opens the panel, takes this as its /config, sends nothing to the server (placeholder
	 * answers), stores nothing, and redraws on the window event "sigma:preview" (detail: SiteConfig).
	 */
	preview?: SiteConfig;
}

export interface SiteConfig {
	/** §19.4: dictation in the AI chat composer (the browser's own speech recognition). */
	voice_input?: boolean;
	enabled: boolean;
	ai_enabled: boolean;
	human_enabled: boolean;
	greeting: string;
	privacy_url: string;
	brand: Brand;
	intro: string;
	human_reply_text: string;
	privacy_text_ai: string;
	privacy_text_human: string;
	confirmation_email: boolean;
	retention_days: number;
	/** R2; R1 always 0. */
	agents_online: number;
	/** §8.5: the AI's name, "Remy" by default. */
	bot_name?: string;
	/** §8.6 (placeholders already replaced by the server). */
	bot_avatar_url?: string;
	human_card_title?: string;
	bot_card_title?: string;
	bot_card_text?: string;
	bot_intro?: string;
	/** §8.6 v1.12.2: Continue card titles (placeholders already replaced). */
	continue_card_title?: string;
	continue_human_title?: string;
	/** §10b (0.1.7): the site's own host (links to it are clickable) and the Turnstile site key ('' = off). */
	site_host?: string;
	turnstile_site_key?: string;
	/** Admin live preview only (§7.7): answer with the real AI through the settings screen's hook. Never on the site. */
	preview_real_ai?: boolean;
	/** Turnstile appearance: 'auto' (default) follows the widget's light/dark scheme. */
	turnstile_theme?: 'auto' | 'light' | 'dark';
	/** Plan v1.9 §8.4: up to 4 team members in picker order (privacy-safe avatar copies), and the full count. */
	team?: { name: string; avatar_url: string }[];
	team_total?: number;
	/** PLAN §8 v1 single privacy string; only a fallback now. */
	privacy_text?: string;
}

export interface DocLink {
	url: string;
	title: string;
}

export type Sender = 'visitor' | 'ai' | 'agent' | 'system';

export interface ChatMessage {
	/** Server id when known, else a local id prefixed with "l". */
	id: string;
	sender: Sender;
	body: string;
	links?: DocLink[];
	/** §8.6: Remy's intro, rendered by the widget at the start of a new AI chat; never sent or stored server-side. */
	intro?: boolean;
	/** AI answer the docs didn't cover → "Talk to a human?" card. */
	notInDocs?: boolean;
	/** §8.7 v1.14: the visitor asked for a person (`handoff_requested`) → the "Talk to a human" card with the request copy. */
	handoffRequested?: boolean;
	/** Visitor message whose /ask failed (network or 5xx) → "Not sent. Try again". */
	failed?: boolean;
	/** With failed: the server had no free slot (429 busy, §10b) even after one automatic retry. */
	busy?: boolean;
	/** AI answer cut off mid-stream (§8.3): partial text, greyed, "Not finished. Try again". */
	partial?: boolean;
	/** For a partial answer: the question to resend and its visitor message id. */
	question?: string;
	retryOf?: string;
	/** System cards. */
	card?: 'limit' | 'down';
	retryAfter?: number;
	scope?: 'hour' | 'day';
}

/** What the bubble hands to the panel chunk. */
export interface MountContext {
	shadow: ShadowRoot;
	host: HTMLElement;
	launcher: HTMLButtonElement;
	boot: BootData;
	/** Remy's icon (plan §8.8), the one painter shared with the launcher and the teaser. */
	paintRemy(el: HTMLElement, name?: string | null, url?: string | null): void;
	/** Re-resolve light/dark (after brand.scheme arrives from /config). */
	setScheme(pref: Brand['scheme']): void;
}

export interface PanelApi {
	toggle(): void;
	/** quiet: restoring after a page change, so don't move focus. */
	open(quiet?: boolean): void;
	close(): void;
}
