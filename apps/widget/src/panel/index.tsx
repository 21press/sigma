// Lazy panel chunk. widget.js loads it on first use and calls window.SigmaPanel.mount().
import { render } from 'preact';
import type { MountContext, PanelApi } from '../types';
import { App, type Bridge } from './App';
import { setMemoryOnly } from './storage';
import { panelCss } from './styles';

function mount(ctx: MountContext): PanelApi {
	if (ctx.boot.preview) setMemoryOnly();
	const style = document.createElement('style');
	style.textContent = panelCss;
	ctx.shadow.appendChild(style);

	const root = document.createElement('div');
	ctx.shadow.appendChild(root);

	const bridge: Bridge = {};
	render(<App ctx={ctx} bridge={bridge} />, root);

	// Escape on the launcher itself also closes an open panel.
	ctx.launcher.addEventListener('keydown', (e) => {
		if (e.key === 'Escape' && ctx.launcher.getAttribute('aria-expanded') === 'true') bridge.setOpen?.(() => false);
	});

	return {
		toggle: () => bridge.setOpen?.((o) => !o),
		open: (quiet?: boolean) => bridge.setOpen?.(() => true, quiet),
		close: () => bridge.setOpen?.(() => false),
	};
}

(window as Window & { SigmaPanel?: { mount: typeof mount } }).SigmaPanel = { mount };
