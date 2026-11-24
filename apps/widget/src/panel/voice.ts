// Voice dictation in the AI chat composer (plan §19.4): the browser's own speech recognition (Web Speech API).
// No server endpoint and no audio through the site; in Chrome and Edge the browser maker's service does the work.
// The text goes into the composer and is never sent automatically.
import { useEffect, useRef, useState } from 'preact/hooks';
import { t } from '../i18n';

type Result = { isFinal: boolean; 0: { transcript: string } };
type Recognition = {
	lang: string;
	continuous: boolean;
	interimResults: boolean;
	start(): void;
	stop(): void;
	abort(): void;
	onresult: ((e: { results: ArrayLike<Result> }) => void) | null;
	onerror: ((e: { error: string }) => void) | null;
	onend: (() => void) | null;
};
type RecognitionCtor = new () => Recognition;

const OFF_KEY = 'delta-assistant:voice-off'; // sessionStorage: dictation failed for this visit (legacy key prefix)
const SILENCE_MS = 8000;

function ctor(): RecognitionCtor | null {
	const w = window as unknown as { SpeechRecognition?: RecognitionCtor; webkitSpeechRecognition?: RecognitionCtor };
	return w.SpeechRecognition || w.webkitSpeechRecognition || null;
}

/** The browser can dictate here: it has the API and the page is a secure context (https or localhost). */
export function canDictate(): boolean {
	return !!ctor() && window.isSecureContext === true;
}

function offForVisit(): boolean {
	try {
		return sessionStorage.getItem(OFF_KEY) === '1';
	} catch {
		return false;
	}
}

export type Dictation = {
	listening: boolean;
	/** The button is gone for this visit after a network or service error. */
	off: boolean;
	/** A visible message under the composer (an error), '' when none. */
	message: string;
	/** For the visually hidden live region: "Listening… speak now", then "Stopped". */
	status: string;
	toggle(): void;
	stop(): void;
	/** Stop at once without waiting for a last result (the composer is being sent). */
	abort(): void;
	clearMessage(): void;
};

/**
 * Dictation into a text field. `getText` gives the text when dictation starts; `setText` receives the text with the
 * dictated words (interim words live, then the final ones), cut to `max` characters.
 */
export function useDictation(getText: () => string, setText: (v: string) => void, max: number): Dictation {
	const [listening, setListening] = useState(false);
	const [off, setOff] = useState(offForVisit);
	const [message, setMessage] = useState('');
	const [status, setStatus] = useState('');
	const rec = useRef<Recognition | null>(null);
	const silence = useRef(0);
	const abortRef = useRef<() => void>(() => {});

	const quiet = () => window.clearTimeout(silence.current);
	const armSilence = () => {
		quiet();
		silence.current = window.setTimeout(() => rec.current?.stop(), SILENCE_MS);
	};
	useEffect(() => () => {
		quiet();
		rec.current?.abort();
	}, []);
	// A hidden tab may pause the 8 s silence timer, so the microphone could stay open out of sight: stop at once.
	useEffect(() => {
		const onHidden = () => {
			if (document.hidden) abortRef.current();
		};
		document.addEventListener('visibilitychange', onHidden);
		return () => document.removeEventListener('visibilitychange', onHidden);
	}, []);

	function start() {
		const R = ctor();
		if (!R || off) return;
		const r = new R();
		const before = getText();
		const base = before && !/\s$/.test(before) ? before + ' ' : before;
		r.lang = document.documentElement.lang || navigator.language || 'en';
		r.continuous = true;
		r.interimResults = true;
		r.onresult = (e) => {
			if (rec.current !== r) return; // a late event from an aborted session
			// Every result since the start: final ones stay, interim ones are redrawn each time.
			let said = '';
			for (let i = 0; i < e.results.length; i++) said += e.results[i][0].transcript;
			setText((base + said.replace(/^\s+/, '')).slice(0, max));
			armSilence();
		};
		r.onerror = (e) => {
			if (rec.current !== r) return;
			if (e.error === 'aborted') return;
			if (e.error === 'not-allowed' || e.error === 'service-not-allowed') setMessage(t('Microphone access is blocked. Allow it in your browser to dictate.'));
			else if (e.error === 'no-speech') setMessage(t("Didn't catch that. Try again."));
			else {
				// network, audio-capture, language-not-supported…: no dictation for the rest of the visit; typing works.
				setMessage(t("Voice input isn't available right now. You can still type."));
				setOff(true);
				try {
					sessionStorage.setItem(OFF_KEY, '1');
				} catch {
					/* storage off: for this page only */
				}
			}
		};
		r.onend = () => {
			if (rec.current !== r) return;
			quiet();
			rec.current = null;
			setListening(false);
			setStatus(t('Stopped'));
		};
		rec.current = r;
		setMessage('');
		try {
			r.start();
		} catch {
			rec.current = null;
			return;
		}
		setListening(true);
		setStatus(t('Listening… speak now'));
		armSilence();
	}

	function abort() {
		if (!rec.current) return;
		const r = rec.current;
		// Reset here: the session's own late onend (or onresult) must not touch a session started after this one.
		rec.current = null;
		quiet();
		setListening(false);
		setStatus(t('Stopped'));
		r.abort();
	}
	abortRef.current = abort;

	return {
		listening,
		off,
		message,
		status,
		toggle: () => (rec.current ? rec.current.stop() : start()),
		stop: () => rec.current?.stop(),
		abort,
		clearMessage: () => setMessage(''),
	};
}
