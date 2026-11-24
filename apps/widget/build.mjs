// Builds the visitor widget into <repo>/build/widget/:
//   widget.js          classic deferred script (bubble), budget 8 KB gzip
//   panel-<hash>.js    lazy panel, loaded by widget.js on first open, budget 30 KB gzip
//   sizes.json         raw + gzip sizes, for the PR report
// Fails (exit 1) when a budget is exceeded. `--watch` rebuilds on change.
import { build } from 'esbuild';
import { createHash } from 'node:crypto';
import { gzipSync } from 'node:zlib';
import { mkdirSync, readdirSync, rmSync, watch, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
// DA_WIDGET_OUT: build somewhere else, e.g. a checkout symlinked into a local site.
const outDir = process.env.DA_WIDGET_OUT ? resolve(process.env.DA_WIDGET_OUT) : resolve(here, '../../build/widget');
const BUDGET = { 'widget.js': 8 * 1024, panel: 30 * 1024 };

const common = {
	bundle: true,
	minify: true,
	write: false,
	format: 'iife',
	target: ['es2020', 'safari15', 'firefox100', 'chrome100'],
	jsx: 'automatic',
	jsxImportSource: 'preact',
	legalComments: 'none',
	// §8.6 v1.12.1: assets/remy-avatar.svg is bundled as text and drawn inline (never an <img>).
	loader: { '.svg': 'text' },
	logLevel: 'warning',
};

async function run() {
	const panel = await build({ ...common, entryPoints: [join(here, 'src/panel/index.tsx')] });
	const panelCode = panel.outputFiles[0].contents;
	const hash = createHash('sha256').update(panelCode).digest('hex').slice(0, 10);
	const panelFile = `panel-${hash}.js`;

	const bubble = await build({
		...common,
		entryPoints: [join(here, 'src/bubble.ts')],
		define: { __PANEL_FILE__: JSON.stringify(panelFile) },
	});
	const bubbleCode = bubble.outputFiles[0].contents;

	mkdirSync(outDir, { recursive: true });
	for (const f of readdirSync(outDir)) {
		if (/^panel-[0-9a-f]+\.js$/.test(f)) rmSync(join(outDir, f));
	}
	writeFileSync(join(outDir, 'widget.js'), bubbleCode);
	writeFileSync(join(outDir, panelFile), panelCode);

	const size = (buf) => ({ raw: buf.length, gzip: gzipSync(buf, { level: 9 }).length });
	const sizes = {
		'widget.js': { ...size(bubbleCode), budget_gzip: BUDGET['widget.js'] },
		[panelFile]: { ...size(panelCode), budget_gzip: BUDGET.panel },
	};
	writeFileSync(join(outDir, 'sizes.json'), JSON.stringify(sizes, null, 2) + '\n');

	let over = false;
	for (const [name, s] of Object.entries(sizes)) {
		const ok = s.gzip <= s.budget_gzip;
		over ||= !ok;
		console.log(
			`${ok ? 'ok  ' : 'OVER'} ${name.padEnd(22)} ${(s.raw / 1024).toFixed(1).padStart(6)} KB raw  ${(s.gzip / 1024).toFixed(2).padStart(6)} KB gzip  (budget ${(s.budget_gzip / 1024).toFixed(0)} KB)`,
		);
	}
	return !over;
}

if (process.argv.includes('--watch')) {
	let timer;
	const again = () => {
		clearTimeout(timer);
		timer = setTimeout(() => run().catch((e) => console.error(e.message)), 80);
	};
	await run().catch((e) => console.error(e.message));
	watch(join(here, 'src'), { recursive: true }, again);
	console.log('watching src/ …');
} else {
	const ok = await run();
	if (!ok) {
		console.error('Size budget exceeded.');
		process.exit(1);
	}
}
