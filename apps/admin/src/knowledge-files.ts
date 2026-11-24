import { __, _n, sprintf } from '@wordpress/i18n';
/**
 * Knowledge files (plan §7.6), read in the browser: `.md` and `.txt` only, at most 1 MB, UTF-8. Only the text is
 * sent to the server, which checks the same things again. Pure helpers, no DOM.
 */

/** 1 MB of content, as the server counts it (bytes of UTF-8). */
export const MAX_BYTES = 1048576;
export const MAX_FILES = 50;
export const ACCEPT = '.md,.txt';

export type FileProblem = 'extension' | 'size' | 'encoding' | 'empty';

export interface ReadFile {
	name: string;
	ok: boolean;
	problem?: FileProblem;
	title: string;
	content: string;
	words: number;
}

/** What a problem means, in the screen's words. */
export const PROBLEM_TEXT: Record< string, string > = {
	extension: __( 'only .md and .txt files', 'sigma' ),
	size: __( 'larger than 1 MB', 'sigma' ),
	encoding: __( 'not UTF-8 text', 'sigma' ),
	empty: __( 'the file is empty', 'sigma' ),
};

export function countWords( text: string ): number {
	const m = text.trim().match( /\S+/g );
	return m ? m.length : 0;
}

/* translators: %s: number of words. */
export const formatWords = ( n: number ) => sprintf( _n( '%s word', '%s words', n, 'sigma' ), n.toLocaleString() );

/** Control characters except tab and newline are removed (the server strips them too); CRLF becomes LF. */
export function cleanText( text: string ): string {
	return text
		.replace( /^﻿/, '' )
		.replace( /\r\n?/g, '\n' )
		// eslint-disable-next-line no-control-regex
		.replace( /[\u0000-\u0008\u000B\u000C\u000E-\u001F\u007F]/g, '' );
}

/** The title: the file's first `# ` heading, else its name without the extension. */
export function titleFrom( content: string, name: string ): string {
	const h = content.match( /^[ \t]{0,3}#[ \t]+(.+?)[ \t#]*$/m );
	const t = h ? h[ 1 ].trim() : name.replace( /\.(md|txt)$/i, '' ).replace( /[-_]+/g, ' ' ).trim();
	return t.slice( 0, 200 );
}

export const hasAllowedExtension = ( name: string ) => /\.(md|txt)$/i.test( name );

/** Read one file: checks the extension, the size and UTF-8, then derives the title and the word count. */
export async function readKnowledgeFile( file: File ): Promise< ReadFile > {
	const base = { name: file.name, title: '', content: '', words: 0 };
	if ( ! hasAllowedExtension( file.name ) ) return { ...base, ok: false, problem: 'extension' };
	if ( file.size > MAX_BYTES ) return { ...base, ok: false, problem: 'size' };
	let text: string;
	try {
		text = new TextDecoder( 'utf-8', { fatal: true } ).decode( await file.arrayBuffer() );
	} catch {
		return { ...base, ok: false, problem: 'encoding' };
	}
	const content = cleanText( text );
	if ( ! content.trim() ) return { ...base, ok: false, problem: 'empty' };
	return { name: file.name, ok: true, title: titleFrom( content, file.name ), content, words: countWords( content ) };
}

/** Bytes of a string in UTF-8. */
export const byteLength = ( s: string ) => new TextEncoder().encode( s ).length;

/** Case-insensitive, trimmed comparison key for titles (as the server matches on import). */
export const titleKey = ( t: string ) => t.trim().toLowerCase();
