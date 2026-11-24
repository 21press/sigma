// esbuild loads .svg as text (build.mjs).
declare module '*.svg' {
	const markup: string;
	export default markup;
}
