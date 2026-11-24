export interface KeyStatus {
	set: boolean;
	/** unreadable: stored but can't be decrypted (the site's keys changed); never counts as set (§10b). */
	source: 'constant' | 'settings' | 'none' | 'unreadable';
	last4: string;
}

export interface Settings {
	enabled: boolean;
	admins_only: boolean;
	ai_enabled: boolean;
	human_enabled: boolean;
	provider: 'anthropic' | 'openai' | 'mock';
	anthropic_model: string;
	openai_model: string;
	sources: string[];
	context_budget: number;
	support_email: string;
	support_email_effective: string;
	brand_name: string;
	logo_url: string;
	color_light: string;
	color_dark: string;
	/** Panel palette (#39 follow-up). Optional until the server stores them: empty or missing = Delta's colours. */
	color_bg_light?: string;
	color_bg_dark?: string;
	color_text_light?: string;
	color_text_dark?: string;
	/** Remy's icon (§8.8, 0.1.8): launcher, teaser, cards, chat, menu. `image` = the uploaded bot_avatar. */
	launcher_icon?: 'assistant' | 'remy' | 'chat' | 'sparkles' | 'help' | 'image';
	scheme: 'auto' | 'light' | 'dark';
	position: 'right' | 'left';
	offset_x: number;
	offset_y: number;
	font: 'inherit' | 'system';
	greeting: string;
	intro: string;
	human_reply_text: string;
	privacy_text_ai: string;
	privacy_text_human: string;
	pricing_url: string;
	privacy_url: string;
	retention_days: number;
	ip_source: 'auto' | 'remote_addr' | 'cloudflare';
	team: number[];
	team_avatars: boolean;
	bot_name: string;
	/** §7.7: the editable instructions; '' = the default. */
	instructions: string;
	bot_avatar: number;
	human_card_title: string;
	bot_card_title: string;
	bot_card_text: string;
	bot_intro: string;
	continue_card_title: string;
	continue_human_title: string;
	teaser_text: string;
	teaser_enabled: boolean;
	launcher_pulse: boolean;
	voice_input: boolean;
	mail_transport: 'php' | 'smtp';
	smtp_host: string;
	smtp_port: number;
	smtp_encryption: 'none' | 'ssl' | 'starttls';
	smtp_auth: boolean;
	smtp_username: string;
	smtp_password: { set: boolean; source: 'constant' | 'settings' | 'none' | 'unreadable' };
	/** Optional Cloudflare Turnstile on the handoff form (§10b). */
	turnstile_enabled: boolean;
	turnstile_site_key: string;
	turnstile_appearance: 'auto' | 'light' | 'dark';
	turnstile_secret: KeyStatus;
	mail_from_email: string;
	mail_from_name: string;
	mail_warnings: string[];
	delete_on_uninstall: boolean;
	anthropic_key: KeyStatus;
	openai_key: KeyStatus;
	killed_by_constant: boolean;
	mock_allowed: boolean;
	text_defaults: Record< string, string >;
	/** Models offered per provider, newest first, one marked default (plan v1.17). */
	models: Record< 'anthropic' | 'openai', ModelOption[] >;
}

export interface Corpus {
	built: boolean;
	sources: string[];
	tokens: number;
	context_budget: number;
	over_budget?: boolean;
	mode?: 'whole' | 'retrieval';
	prefix_tokens_exact?: number | null;
	tokens_exact?: number | null;
	tokens_model?: string;
	tokens_error?: string;
	budget_tokens?: number;
	rebuild_pending?: boolean;
	built_at?: string;
	hash?: string;
	docs: { id: number; type: string; url: string; title: string; updated: string; chars: number }[];
}

export interface SettingsResponse {
	settings: Settings;
	post_types: { name: string; label: string; published: number }[];
	usage: { this: UsageMonth; last: UsageMonth };
	mail: MailStatus;
	team: TeamMember[];
	bot_avatar: BotAvatar;
	/** Each built-in Remy icon on the brand disc (data URIs from the shared icon files). */
	remy_icons: Record< 'assistant' | 'remy' | 'chat' | 'sparkles' | 'help', string >;
	/** §7.6: the knowledge base (always a source). */
	knowledge?: { active: number; draft: number; active_words: number };
	/** §7.7: the default instructions (placeholders unfilled), the protected system rules (filled, read-only), the cap. */
	instructions_default?: string;
	system_rules?: string;
	instructions_max?: number;
	corpus: Corpus;
	cloudflare: { source: 'bundled' | 'refreshed'; count: number; fetched_at: string; age_days: number; stale: boolean; behind: boolean };
	ai_status: string;
	killed: boolean;
}

export interface BotAvatar {
	/** What the widget shows: the copy, or the default SVG. */
	url: string;
	default: boolean;
	default_url: string;
	/** The chosen Media Library image (thumbnail), or ''. */
	preview: string;
	status: AvatarStatus | null;
	message: string;
}

export interface TeamMember {
	id: number;
	name: string;
	chat_name: string;
	role: string;
	/** The copy when there is one, else the WordPress avatar (admin screen only). */
	avatar: string;
	/** The copy the widget gets; '' means initials. */
	avatar_copy?: string;
	avatar_source?: 'chat' | 'gravatar';
	/** The last refresh; null when never refreshed. */
	avatar_status?: AvatarStatus | null;
	avatar_message?: string;
}

export interface AvatarStatus {
	source: 'chat' | 'gravatar' | 'media';
	result: 'ok' | 'unchanged' | 'no_picture' | 'kept' | 'failed';
	reason: string;
	detail: string;
	/** Unix time. */
	at: number;
}

export interface UsageMonth {
	month: string;
	calls: number;
	cost: number;
}

export interface MailStatus {
	last_success_at: string;
	last_error: string;
	last_error_at: string;
	consecutive_failures: number;
	failed_handoffs: number;
}

export interface CheckStep {
	step: 'dns' | 'tcp' | 'greeting' | 'tls' | 'auth';
	ok: boolean | null;
	detail: string;
	hint: string;
}

export interface Conversation {
	id: number;
	uuid: string;
	mode: string;
	status: string;
	visitor_name: string | null;
	visitor_email: string | null;
	page_url: string;
	locale: string;
	created_at: string;
	updated_at: string;
	handoff_at: string | null;
	emailed_at: string | null;
	email_status?: 'sent' | 'failed' | null;
	message_count?: number;
	first_question?: string | null;
}

export interface Message {
	id: number;
	sender: 'visitor' | 'ai' | 'agent' | 'system';
	body: string;
	meta: { model?: string; cost?: number; links?: string[]; answered?: boolean; usage?: Record< string, number >; kb?: { id: number; title: string }[] };
	created_at: string;
}

export interface ModelOption {
	id: string;
	label: string;
	default: boolean;
}

/** A Knowledge entry (plan §7.6). List items carry no `content`. Times are UTC 'Y-m-d H:i:s'. */
export interface KnowledgeEntry {
	id: number;
	title: string;
	content?: string;
	status: 'active' | 'draft';
	/** The original file name, or '' for "Written here". */
	source: string;
	imported_at: string | null;
	updated_at: string;
	words: number;
	bytes?: number;
}

export interface KnowledgeCounts {
	all: number;
	active: number;
	draft: number;
}

export interface KnowledgeList {
	items: KnowledgeEntry[];
	total: number;
	pages: number;
	counts: KnowledgeCounts;
	active_words: number;
}

export interface KnowledgeImportResult {
	name: string;
	result: 'created' | 'replaced' | 'refused';
	id?: number;
	reason?: 'extension' | 'size' | 'encoding' | 'empty' | string;
}
