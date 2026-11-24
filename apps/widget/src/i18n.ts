// Translations for the chat box, with no i18n runtime: the plugin prints the strings that differ from English as
// SigmaWidget.i18n ({ English: translation }), translated in PHP with __() (includes/class-widget-strings.php,
// generated from the t( '…' ) calls by scripts/widget-strings.mjs). English is the code itself, so it costs nothing.
type Win = Window & { SigmaWidget?: { i18n?: Record<string, string> } };

/** The translation of `text`, with %s / %1$s filled from `args`. Always call with a literal (the generator reads it). */
export function t(text: string, ...args: Array<string | number>): string {
	const out = (window as Win).SigmaWidget?.i18n?.[text] || text;
	let i = 0;
	return args.length ? out.replace(/%(?:(\d)\$)?s/g, (_, n) => String(args[n ? +n - 1 : i++] ?? '')) : out;
}
