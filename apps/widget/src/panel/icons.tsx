// Icons from the design set (24px grid, stroke, currentColor). Decorative: the control carries the name.
const P: Record<string, [string, number?]> = {
	close: ['M6.5 6.5l11 11M17.5 6.5l-11 11'],
	back: ['M14.5 6l-6 6 6 6'],
	right: ['M9.5 6l6 6-6 6'],
	send: ['M12 19V5.5M6.5 11L12 5.5 17.5 11', 1.75],
	doc: ['M7 3.5h6.5L18 8v12.5H7zM13.5 3.5V8H18M9.75 12.5h5.5M9.75 16h5.5'],
	person: ['M12 11.5a3.75 3.75 0 1 0 0-7.5 3.75 3.75 0 0 0 0 7.5zM5 20c.9-3.4 3.7-5.5 7-5.5s6.1 2.1 7 5.5'],
	check: ['M5 12.5l4.5 4.5L19 7.5', 1.75],
	alert: ['M12 20.5a8.5 8.5 0 1 0 0-17 8.5 8.5 0 0 0 0 17zM12 8v4.75M12 16.25v.01'],
	// v1.13 New chat: the design set's chat bubble with a plus (no dedicated icon yet; the design set may replace it).
	newchat: ['M4 6.5A2.5 2.5 0 0 1 6.5 4h11A2.5 2.5 0 0 1 20 6.5v8a2.5 2.5 0 0 1-2.5 2.5H10.5L6 20.5V17h.5A2.5 2.5 0 0 1 4 14.5zM12 7.5v6M9 10.5h6'],
	clock: ['M12 20.5a8.5 8.5 0 1 0 0-17 8.5 8.5 0 0 0 0 17zM12 7.5V12l3 2'],
	// §19.4 dictation: a microphone (Tabler's, on the same 24px grid).
	mic: ['M9 5a3 3 0 0 1 6 0v5a3 3 0 0 1-6 0zM5 10a7 7 0 0 0 14 0M8 21h8M12 17v4'],
};

export function Icon({ n }: { n: keyof typeof P }) {
	const [d, w = 1.5] = P[n];
	return (
		<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
			<path d={d} fill="none" stroke="currentColor" stroke-width={w} stroke-linecap="round" stroke-linejoin="round" />
		</svg>
	);
}

