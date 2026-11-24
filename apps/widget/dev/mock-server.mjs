// Mock of the visitor REST API (the visitor routes) + a test page, for building the widget before the plugin exists.
//   node dev/mock-server.mjs [port]      → http://localhost:5178/?s=<scenario>
// Scenarios (path prefix /s/<name>/wp-json/…): ok, aioff, humanoff, disabled, configfail, slow, brand, forcelight, left.
// Trigger words in a question: "limit" → 429 hour, "daily" → 429 day, "down" → 503 ai_unavailable, "boom" → 500, "price" → answered:false with a link, "talk to a human" → answered:false + handoff_requested, "person please" → answered + handoff_requested, "peru" → answered:false without links,
// "inject" → hostile markdown. Handoff email "taken@example.com" → 400 field error, name "flood" → 429, name "crash" → 500.
// GET /__log returns every API request seen (tests use it to prove no REST call before opening).
import { createServer } from 'node:http';
import { readFileSync, existsSync } from 'node:fs';
import { randomBytes, randomUUID } from 'node:crypto';
import { dirname, join, resolve, normalize } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const buildDir = resolve(here, '../../../build/widget');
const port = Number(process.argv[2] || process.env.PORT || 5178);
const convs = new Map();
const log = [];
/** §10b test details (request bodies, busy, Turnstile) kept apart so /__log stays the list of API calls. */
const debug = [];

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const send = (res, status, body, headers = {}) => {
	res.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store', ...headers });
	res.end(JSON.stringify(body));
};
const wpError = (res, status, code, message, extra = {}, headers = {}) => send(res, status, { code, message, data: { status, ...extra } }, headers);
const readBody = (req) =>
	new Promise((r) => {
		let b = '';
		req.on('data', (c) => (b += c));
		req.on('end', () => {
			try {
				r(JSON.parse(b || '{}'));
			} catch {
				r({});
			}
		});
	});

const config = (scenario, host = '') => ({
	// §10b (0.1.7): links on this host are clickable; Turnstile on for ts* scenarios (Cloudflare's always-pass test key).
	site_host: scenario.startsWith('otherhost') ? 'deltatheme.com' : host, // otherhost*: home_url() differs from the page's host
	turnstile_site_key: scenario.startsWith('ts') ? '1x00000000000000000000AA' : '',
	turnstile_theme: scenario.startsWith('tslight') ? 'light' : 'auto',
	enabled: scenario !== 'disabled',
	ai_enabled: scenario !== 'aioff',
	human_enabled: scenario !== 'humanoff',
	confirmation_email: false, // always false since PLAN v1.6
	greeting: 'Hi. How can we help?',
	intro: 'Ask about Delta and get an answer from our docs, or leave a message for the team.',
	human_reply_text: 'We reply by email',
	privacy_text_ai: scenario.startsWith('nobot') ? '' : "Sigma's answers come from our docs and collective knowledge of Delta. Chats are stored for 90 days for better user experience.",
	privacy_text_human: 'We use your name and email only to reply. Chats are stored for 90 days.',
	privacy_url: '/privacy-policy/',
	retention_days: 90,
	brand: {
		name: 'Delta help',
		logo_url: null,
		color_light: scenario === 'brand' ? '#B4482A' : '#26292A',
		color_dark: '#EDE8DD',
		scheme: scenario === 'forcelight' ? 'light' : 'auto',
		position: scenario === 'left' ? 'left' : 'right',
		offset_x: 24,
		offset_y: 24,
		font: scenario === 'sysfont' ? 'system' : 'inherit',
		// §8.8: ic<kind>* sets the bot's icon (icimage: an upload; icimagebad: an upload that 404s).
		launcher_icon: (scenario.match(/^ic(assistant|remy|chat|sparkles|help|image)/) || [])[1] || 'assistant',
	},
	agents_online: scenario.startsWith('online') ? 2 : 0, // online*: the server reports people online (R2); 0.2.5 shows nothing for it
	voice_input: !scenario.startsWith('novoice'), // §19.4: on by default; novoice*: the site switched it off
	// nobot*: a site with no name and no texts set (the widget's own fallbacks: "Sigma", 0.2.5).
	bot_name: scenario.startsWith('nobot') ? '' : 'Sigma',
	// §8.6 (placeholders replaced, as the server does). nobotav: no avatar URL; badbotav: a URL that 404s.
	// §8.6 v1.12.1: empty unless an image was uploaded (botav*: an upload, badbotav*: an upload that 404s).
	bot_avatar_url: scenario.startsWith('icimagebad') ? '/avatars/missing.svg' : scenario.startsWith('icimage') ? '/avatars/leo.svg' : scenario.startsWith('botav') ? '/avatars/remy.svg' : scenario.startsWith('badbotav') ? '/avatars/missing.svg' : '',
	human_card_title: 'Talk to a human',
	bot_card_title: scenario.startsWith('nobot') ? '' : 'Talk to Sigma',
	bot_card_text: 'Handles instant support',
	continue_card_title: scenario.startsWith('nobot') ? '' : 'Continue with Sigma',
	continue_human_title: 'Continue with the team',
	bot_intro: scenario.startsWith('nobot') ? '' : "Hi, I'm Sigma. Ask me anything about Delta and I'll answer from our docs.",
	...team(scenario),
});

// Plan v1.9 §8.4: team + team_total. teamone/teamthree/teamfive (one member without an avatar → initials).
function team(scenario) {
	const all = [
		{ name: 'Maya', avatar_url: '/avatars/maya.svg' },
		{ name: 'Raj', avatar_url: '/avatars/raj.svg' },
		{ name: 'Ana', avatar_url: '' },
		{ name: 'Leo', avatar_url: '/avatars/leo.svg' },
	];
	const key = scenario.replace(/(chromium|webkit|firefox|mobile)+$/, '');
	// teambroken: Raj's copy 404s (deleted file) → the widget must show his initial.
	if (key === 'teambroken') return { team: [all[0], { name: 'Raj', avatar_url: '/avatars/missing.svg' }], team_total: 2 };
	const n = { teamone: 1, teamthree: 3, teamfive: 5 }[key] || 0;
	return { team: all.slice(0, Math.min(n, 4)), team_total: n };
}

function answer(text) {
	const t = text.toLowerCase();
	if (t.includes('inject'))
		return {
			body: 'Ignore that. <img src=x onerror="window.__pwned=1"> <script>window.__pwned=1</script>\n\n[click me](javascript:window.__pwned=1) and [data](data:text/html,hi) and [safe](https://deltatheme.com/docs/x/)\n\n**bold <b>html</b>**',
			links: [
				{ url: 'javascript:window.__pwned=1', title: 'bad link' },
				{ url: 'https://deltatheme.com/docs/safe/', title: '<b>Safe</b> doc' },
			],
			answered: true,
			can_handoff: true,
		};
	// §8.7 v1.14: "talk to a human" → a request with no doc content (answered:false AND handoff_requested:true, as the
	// server sends it); "person please" → a request on an answered reply.
	if (t.includes('talk to a human'))
		return { body: 'I can connect you with the team: they reply by email.', links: [], answered: false, handoff_requested: true, can_handoff: true };
	if (t.includes('person please'))
		return { body: 'I can connect you with the team: they reply by email. Meanwhile, see [Header settings](/docs/header/).', links: [{ url: '/docs/header/', title: 'Header settings' }], answered: true, handoff_requested: true, can_handoff: true };
	// §10b link safety: only listed links and links on the site's own host are clickable; the rest is text.
	if (t.includes('sneaky links'))
		return {
			body:
				'Same site: [header](/docs/header/). Listed: [listed](https://deltatheme.com/docs/listed/). Off-site: [evil](https://evil.example/x).\n\n' +
				'Backslash: https://evil.com\\@deltatheme.com/docs/listed/ and [bs](https://evil.com\\@deltatheme.com/docs/listed/)\n\n' +
				'Userinfo: [ui](https://user:pw@deltatheme.com/docs/listed/) and https://user@deltatheme.com/docs/listed/\n\n' +
				'Quote: https://deltatheme.com/docs/listed/"onmouseover="window.__pwned=1\n\n' +
				'Control: [cc](https://deltatheme.com/docs/listed/\u0007x)',
			links: [
				{ url: 'https://deltatheme.com/docs/listed/', title: 'Listed doc' },
				{ url: 'https://evil.com\\@deltatheme.com/docs/listed/', title: 'Backslash doc' },
				{ url: 'https://user:pw@deltatheme.com/docs/listed/', title: 'Userinfo doc' },
			],
			answered: true,
			can_handoff: true,
		};
	if (t.includes('peru'))
		return {
			body: "I don't know. The docs don't cover that, so I don't want to guess.",
			links: [],
			answered: false,
			can_handoff: true,
		};
	if (t.includes('price'))
		return {
			body: "I couldn't find that in the docs, so I don't want to guess. Pricing is on the [pricing page](https://deltatheme.com/pricing/).",
			links: [{ url: 'https://deltatheme.com/pricing/', title: 'Pricing' }],
			answered: false,
			can_handoff: true,
		};
	return {
		body: "To change the header layout in **Delta**:\n\n1. Open **Appearance → Editor**.\n2. Select the `Header` template part.\n3. Pick a pattern from the inserter.\n\nYou can also set it per page:\n\n- Edit the page\n- Choose a template in the sidebar\n\n```\nadd_filter( 'delta_header_layout', fn() => 'centered' );\n```\n\nSee [Header settings](/docs/header/) for details.",
		links: [
			{ url: '/docs/header/', title: 'Header settings' },
			{ url: 'https://deltatheme.com/docs/templates/', title: 'Template parts and the Site Editor' },
		],
		answered: true,
		can_handoff: true,
	};
}

const server = createServer(async (req, res) => {
	const url = new URL(req.url, `http://${req.headers.host}`);
	const p = url.pathname;

	if (p === '/__log') return send(res, 200, log);
	if (p === '/__debug') return send(res, 200, debug);
	if (p === '/__reset') {
		log.length = 0;
		return send(res, 200, { ok: true });
	}

	const api = p.match(/^\/s\/([a-z]+)\/wp-json\/sigma\/v1\/(.*)$/);
	if (api) {
		const [, scenario, route] = api;
		log.push({ method: req.method, route, scenario, token: req.headers['x-da-token'] ? 'yes' : 'no', cookie: req.headers.cookie ? 'yes' : 'no', accept: String(req.headers.accept || '') });
		if (scenario === 'slow') await sleep(2500);
		if (req.method === 'GET' && route === 'config') {
			if (scenario === 'configfail') return wpError(res, 500, 'internal', 'fail');
			return send(res, 200, config(scenario, req.headers.host));
		}
		if (req.method === 'POST' && route === 'conversations') {
			const body = await readBody(req);
			// scenario "busyip": the first create is rate-limited like the plugin's 1 per 10 s per IP.
			if (scenario.startsWith('busyip') && !convs.has('busy:' + scenario)) {
				convs.set('busy:' + scenario, {});
				return wpError(res, 429, 'rate_limited', 'Too many.', { retry_after: 1, scope: 'hour' });
			}
			// §10b: tsgate* asks for a Turnstile token on a new conversation (the site's question breaker past 50%).
			// tsgateout*: Cloudflare is down from the server too, so a tokenless create is accepted (fail-open).
			if (scenario.startsWith('tsgate')) debug.push({ method: 'CREATE', route: 'create-turnstile', scenario, token: String(body.turnstile_token || '') });
			if (scenario.startsWith('tsgate') && !(scenario.startsWith('tsgateout') && !body.turnstile_token)) {
				if (!body.turnstile_token) return wpError(res, 403, 'turnstile_required', 'Please complete the security check.');
				if (body.turnstile_token !== 'tok-ok') return wpError(res, 403, 'turnstile_failed', 'The security check failed.');
			}
			const uuid = randomUUID();
			const token = randomBytes(32).toString('hex');
			convs.set(uuid, { token, page_url: body.page_url, locale: body.locale, messages: [] });
			return send(res, 201, { uuid, token, greeting: config(scenario, req.headers.host).greeting });
		}
		const m = route.match(/^conversations\/([0-9a-f-]+)\/(ask|handoff)$/);
		if (m && req.method === 'POST') {
			const c = convs.get(m[1]);
			if (!c) return wpError(res, 404, 'not_found', 'Conversation not found.');
			if (req.headers['x-da-token'] !== c.token) return wpError(res, 403, 'forbidden', 'Bad token.');
			const body = await readBody(req);
			if (m[2] === 'ask') {
				const text = String(body.text || '');
				debug.push({ method: 'ASK', route: 'ask-body', scenario, keys: Object.keys(body).sort().join(','), page_url: body.page_url, locale: body.locale });
				// §10b: 429 busy (no free concurrency slot). "busyonce" → the first try per conversation; "busyalways" → every try.
				if (/busyalways/i.test(text) || (/busyonce/i.test(text) && !c.busied)) {
					c.busied = true;
					debug.push({ method: 'ASK', route: 'busy', scenario });
					return wpError(res, 429, 'busy', 'Busy.', { retry_after: 1 }, { 'Retry-After': '1' });
				}
				c.messages.push({ sender: 'visitor', text });
				if (/daily/i.test(text)) return wpError(res, 429, 'rate_limited', 'Too many questions.', { retry_after: 50000, scope: 'day' });
				if (/limit/i.test(text)) return wpError(res, 429, 'rate_limited', 'Too many questions.', { retry_after: 1200, scope: 'hour' }, { 'Retry-After': '1200' });
				if (/overload/i.test(text)) return wpError(res, 503, 'ai_unavailable', 'AI is unavailable.', { reason: 'status_529' });
				if (/warming/i.test(text)) return wpError(res, 503, 'ai_unavailable', 'AI is unavailable.', { reason: 'corpus_building' });
				if (/down/i.test(text)) return wpError(res, 503, 'ai_unavailable', 'AI is unavailable.', { reason: 'no_key' });
				if (/boom/i.test(text)) return wpError(res, 500, 'internal', 'Boom.');
				if (/slowly/i.test(text)) await sleep(9000);
				const a = answer(text);
				const result = () => {
					const id = c.messages.push({ sender: 'ai', text: a.body });
					return { message: { id, sender: 'ai', body: a.body, links: a.links }, answered: a.answered, handoff_requested: !!a.handoff_requested, can_handoff: a.can_handoff };
				};
				// Streaming (PLAN §8.3) when asked for, except scenario "nostream" (a host that can't stream → JSON).
				const wantsStream = String(req.headers.accept || '').includes('text/event-stream') && !scenario.startsWith('nostream');
				if (!wantsStream) {
					await sleep(Number(url.searchParams.get('delay') || 700));
					return send(res, 200, result());
				}
				// Triggers: "progressive" → slow 250 ms deltas; "buffered" → every event at once at the end (a buffering
				// proxy); "midfail" → 3 deltas then an error event the first time in a conversation; "earlyfail" →
				// start then an error before any text.
				res.writeHead(200, { 'Content-Type': 'text/event-stream; charset=UTF-8', 'Cache-Control': 'no-cache, no-store, no-transform, private', 'X-Accel-Buffering': 'no' });
				const buffered = /buffered/i.test(text);
				const out = [];
				const write = async (chunk, gap = 0) => {
					if (buffered) out.push(chunk);
					else {
						res.write(chunk);
						if (gap) await sleep(gap);
					}
				};
				const ev = (event, data) => `event: ${event}\ndata: ${JSON.stringify(data)}\n\n`;
				await write(':' + ' '.repeat(2048) + '\n\n');
				await write(ev('start', { conversation: m[1] }), 300);
				if (/earlyfail/i.test(text)) {
					await write(ev('error', { code: 'ai_unavailable', message: 'The AI is not available right now.', data: { retryable: true, status: 503, reason: 'http_error' } }));
					c.messages.pop();
					return res.end(out.join(''));
				}
				const gap = /progressive/i.test(text) ? 250 : 30;
				const pieces = a.body.match(/[\s\S]{1,12}/g) || [];
				const failNow = /midfail/i.test(text) && !c.midfailed;
				for (let i = 0; i < pieces.length; i++) {
					if (failNow && i === 3) {
						c.midfailed = true;
						await write(': ping\n\n');
						await write(ev('error', { code: 'ai_unavailable', message: 'The AI is not available right now.', data: { retryable: true, status: 503, reason: 'status_529' } }));
						c.messages.pop();
						return res.end(out.join(''));
					}
					await write(ev('delta', { text: pieces[i] }), gap);
				}
				await write(ev('done', result()));
				return res.end(out.join(''));
			}
			// handoff
			if (body.website) {
				log.push({ method: 'HANDOFF', route: 'honeypot', scenario });
				return send(res, 200, { status: 'emailed' });
			}
			debug.push({ method: 'HANDOFF', route: 'handoff-fields', scenario, keys: Object.keys(body).sort().join(','), turnstile: String(body.turnstile_token || '') });
			if (body.name === 'inboxbusy') return wpError(res, 503, 'handoff_busy', 'Our inbox is busy, try again later.');
			// §10b: with Turnstile on (ts*), the handoff needs a valid token; the fake widget in the tests issues "tok-ok".
			// tsout*: Cloudflare is down from the server too, so a tokenless handoff is accepted (fail-open, 0.1.7).
			if (scenario.startsWith('ts') && !(scenario.startsWith('tsout') && !body.turnstile_token)) {
				if (!body.turnstile_token) return wpError(res, 403, 'turnstile_required', 'Please complete the security check.');
				if (body.turnstile_token !== 'tok-ok') return wpError(res, 403, 'turnstile_failed', 'The security check failed.');
			}
			if (body.name === 'flood') return wpError(res, 429, 'rate_limited', 'Too many handoffs.', { retry_after: 1200, scope: 'hour' });
			if (body.name === 'crash') return wpError(res, 500, 'internal', 'Boom.');
			if (body.name === 'slowmail') await sleep(25000); // a slow SMTP server inside the request
			if (body.email === 'taken@example.com') return wpError(res, 400, 'invalid_email', 'Enter a valid email address.', { field: 'email' });
			if (String(body.text || '').length > 2000) return wpError(res, 400, 'text_too_long', 'Too long.', { field: 'text' });
			if (!body.name || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(body.email || '') || !body.text)
				return wpError(res, 400, 'rest_invalid_param', 'Invalid parameter(s)', { params: {} });
			// Idempotent handoff (PLAN v1.7.1), as the plugin does it: claim the key first, then send once.
			const key = String(body.idempotency_key || '');
			log.push({ method: 'HANDOFF', route: 'handoff-request', scenario, key });
			if (c.handoffKey) {
				if (key && key === c.handoffKey) return send(res, 200, { status: 'emailed', email_status: 'sent' });
				return wpError(res, 409, 'already_handed_off', 'This conversation was already sent to the team.', { result: { status: 'emailed', email_status: 'sent' } });
			}
			c.handoffKey = key;
			await sleep(500);
			c.handoff = { name: body.name, email: body.email, text: body.text };
			log.push({ method: 'HANDOFF', route: 'handoff-body', scenario, website: body.website, name: body.name, key });
			return send(res, 200, { status: 'emailed', email_status: 'sent' });
		}
		return wpError(res, 404, 'rest_no_route', 'No route was found matching the URL and request method.');
	}

	const av = p.match(/^\/avatars\/([a-z]+)\.svg$/);
	if (av) {
		if (av[1] === 'missing') return send(res, 404, { error: 'not found' });
		const hue = { maya: 20, raj: 200, leo: 120 }[av[1]] ?? 300;
		res.writeHead(200, { 'Content-Type': 'image/svg+xml', 'Cache-Control': 'no-cache' });
		return res.end(`<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 96 96"><rect width="96" height="96" fill="hsl(${hue} 55% 55%)"/><circle cx="48" cy="38" r="18" fill="hsl(${hue} 60% 85%)"/><ellipse cx="48" cy="88" rx="30" ry="22" fill="hsl(${hue} 60% 85%)"/></svg>`);
	}

	if (p.startsWith('/build/widget/')) {
		const file = normalize(join(buildDir, p.slice('/build/widget/'.length)));
		if (!file.startsWith(buildDir) || !existsSync(file)) return send(res, 404, { error: 'not found' });
		res.writeHead(200, { 'Content-Type': 'text/javascript; charset=utf-8', 'Cache-Control': 'no-cache' });
		return res.end(readFileSync(file));
	}

	if (p === '/' || p === '/index.html' || p.startsWith('/docs/') || p === '/privacy-policy/') {
		res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
		return res.end(readFileSync(join(here, 'index.html')));
	}
	send(res, 404, { error: 'not found' });
});

server.listen(port, () => console.log(`mock: http://localhost:${port}/?s=ok`));
