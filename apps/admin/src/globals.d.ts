/** A Sigma notice (0.2.5): rendered inside the app, never by wp-admin's admin_notices on Sigma screens. */
interface AppNoticeData {
	id: string;
	tone: 'info' | 'success' | 'warning' | 'danger';
	title?: string;
	/** Plain text; `backticks` mark code. */
	text: string;
	dismissible: boolean;
	action?: { label: string; url: string };
}

declare global {
	type AppNotice = AppNoticeData;
	interface Window {
		SigmaAdmin: { canManage: boolean; adminUrl: string; homeUrl: string; restRoot: string; nonce: string; remyIcon?: { kind: 'builtin' | 'image'; url: string }; botName?: string; widgetI18n?: Record< string, string >; notices?: AppNoticeData[] };
	}
}
export {};
