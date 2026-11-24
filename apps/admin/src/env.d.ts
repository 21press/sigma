declare module 'react-dom/client' {
	import type { ReactNode } from 'react';
	export function createRoot( el: Element ): { render( node: ReactNode ): void };
}
declare module '@wordpress/api-fetch' {
	export interface ApiFetchOptions {
		path?: string;
		method?: string;
		data?: unknown;
		parse?: boolean;
	}
	export default function apiFetch<T = unknown>( options: ApiFetchOptions ): Promise<T>;
}
declare module '*.css';
