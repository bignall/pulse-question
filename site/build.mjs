/**
 * Builds the GitHub Pages site into _site/.
 *
 * The landing page comes from site/index.html; every guide in docs/ is rendered from Markdown
 * into the same layout, with docs/images copied alongside. Run with `npm run site:build`, then
 * open _site/index.html. (The same build as WP Foundation's site, in Pulse Question's colours.)
 */
import { cpSync, existsSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { dirname, join, posix } from 'node:path';
import { fileURLToPath } from 'node:url';
import { Marked } from 'marked';
import hljs from 'highlight.js/lib/core';
import bash from 'highlight.js/lib/languages/bash';
import json from 'highlight.js/lib/languages/json';
import php from 'highlight.js/lib/languages/php';

hljs.registerLanguage( 'bash', bash );
hljs.registerLanguage( 'json', json );
hljs.registerLanguage( 'php', php );

const root = join( dirname( fileURLToPath( import.meta.url ) ), '..' );
const out = join( root, '_site' );
const repo = 'https://github.com/bignall/pulse-question';

// Guides, in reading order, with the summary shown on the home page and in search results.
const summaries = {
	'getting-started': 'Install the plugin, follow the setup checklist, and ask your first question.',
	questions: 'The queue, approval, the daily pick, categories, and questions written by Claude.',
	'answers-and-moderation': 'Members answer, vote and reply; Claude checks every answer against your guidelines.',
	'members-and-mailing-list': 'Sign-up boxes, FluentCRM on this site or another, permissions and Test connection.',
	'daily-email': 'Each new question emailed with yesterday\'s top answers, through FluentCRM or the built-in sender.',
	'social-sharing': 'Share images, share tags and links, and automatic posts through Zapier, Make or n8n.',
	'branding-and-design': 'Your logo and colours, Automatic.css, Etch and other builders, and styling it yourself.',
	developers: 'Blocks, shortcodes, query arguments, REST routes, and every action and filter.',
	privacy: 'What the plugin stores, what it sends where, and what uninstalling removes.',
};
const docs = Object.keys( summaries );

const read = ( path ) => readFileSync( join( root, path ), 'utf8' );

const escapeHtml = ( s ) =>
	String( s ).replace( /[&<>"']/g, ( c ) => ( { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' } )[ c ] );

const slugify = ( text ) =>
	text
		.toLowerCase()
		.replace( /<[^>]+>/g, '' )
		.replace( /&[a-z]+;/g, '' )
		.replace( /[^a-z0-9]+/g, '-' )
		.replace( /^-|-$/g, '' );

/**
 * Syntax-highlight a code sample. Snippets in the docs often omit `<?php`, so PHP is
 * highlighted as embedded code.
 */
function highlight( code, lang ) {
	if ( lang === 'php' ) {
		const hasOpenTag = code.trimStart().startsWith( '<?php' );
		const html = hljs.highlight( hasOpenTag ? code : `<?php\n${ code }`, { language: 'php' } ).value;
		return hasOpenTag ? html : html.replace( /^<span class="hljs-meta">&lt;\?php<\/span>\n/, '' );
	}
	if ( lang && hljs.getLanguage( lang ) ) {
		return hljs.highlight( code, { language: lang } ).value;
	}
	return escapeHtml( code );
}

/**
 * Rewrite a link found in a Markdown file so it works on the site.
 *
 * @param {string} href     Link target as written.
 * @param {string} fromPath Repository path of the file containing the link.
 */
function rewriteLink( href, fromPath ) {
	if ( /^([a-z]+:|#|\/)/i.test( href ) ) {
		return href;
	}
	const [ path, hash = '' ] = href.split( '#' );
	const resolved = posix.normalize( posix.join( posix.dirname( fromPath ), path ) );
	const anchor = hash ? `#${ hash }` : '';

	const doc = resolved.match( /^docs\/([a-z0-9-]+)\.md$/ );
	if ( doc && docs.includes( doc[ 1 ] ) ) {
		return `${ doc[ 1 ] }.html${ anchor }`;
	}

	// Anything else lives in the repository.
	const isFile = /\.[a-z0-9]+$/i.test( resolved );
	return `${ repo }/${ isFile ? 'blob' : 'tree' }/main/${ resolved }${ anchor }`;
}

function markdown( source, fromPath ) {
	const headings = [];
	const marked = new Marked( {
		gfm: true,
		renderer: {
			heading( { tokens, depth } ) {
				const text = this.parser.parseInline( tokens );
				const id = slugify( text );
				if ( depth === 2 ) {
					headings.push( { id, text } );
				}
				if ( depth === 1 ) {
					return `<h1>${ text }</h1>\n`;
				}
				return `<h${ depth } id="${ id }"><a class="anchor" href="#${ id }" aria-hidden="true" tabindex="-1">#</a>${ text }</h${ depth }>\n`;
			},
			code( { text, lang } ) {
				const language = ( lang || '' ).split( /\s/ )[ 0 ];
				return `<pre class="code" data-lang="${ escapeHtml( language ) }"><code>${ highlight( text, language ) }</code></pre>\n`;
			},
			link( { href, title, tokens } ) {
				const target = rewriteLink( href, fromPath );
				// A link whose text is a guide's file name shows the guide's title instead.
				const linked = docMeta.find( ( d ) => `${ d.slug }.md` === href.split( '#' )[ 0 ] );
				if ( linked && tokens.length === 1 && tokens[ 0 ].text === `${ linked.slug }.md` ) {
					return `<a href="${ escapeHtml( target ) }">${ escapeHtml( linked.title ) }</a>`;
				}
				const external = /^https?:/.test( target ) ? ' rel="noopener"' : '';
				const titleAttr = title ? ` title="${ escapeHtml( title ) }"` : '';
				return `<a href="${ escapeHtml( target ) }"${ titleAttr }${ external }>${ this.parser.parseInline( tokens ) }</a>`;
			},
		},
	} );

	// Let wide tables scroll on narrow screens.
	const html = marked.parse( source ).replace( /<table>/g, '<div class="table-wrap"><table>' ).replace( /<\/table>/g, '</table></div>' );

	return { html, headings };
}

function titleOf( source ) {
	const match = source.match( /^#\s+(.+)$/m );
	return match ? match[ 1 ].trim() : 'Untitled';
}

function versionFromReadme() {
	const match = read( 'readme.txt' ).match( /^Stable tag:\s*(\d+\.\d+\.\d+)/m );
	return match ? match[ 1 ] : 'dev';
}

const version = versionFromReadme();
const docMeta = docs.map( ( slug ) => {
	const source = read( `docs/${ slug }.md` );
	return { slug, source, title: titleOf( source ) };
} );

function layout( { title, description, body, active, bodyClass = '', head = '', scripts = '' } ) {
	const pageTitle = active === 'home' ? 'Pulse Question: a question of the day for WordPress' : `${ title } · Pulse Question`;
	return `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>${ escapeHtml( pageTitle ) }</title>
<meta name="description" content="${ escapeHtml( description ) }">
<meta property="og:title" content="${ escapeHtml( pageTitle ) }">
<meta property="og:description" content="${ escapeHtml( description ) }">
<meta name="theme-color" content="#fb3f03">
<link rel="icon" href="assets/favicon-32.png" type="image/png">
<link rel="apple-touch-icon" href="assets/apple-touch-icon.png">
<link rel="stylesheet" href="assets/site.css">
${ head }</head>
<body class="${ bodyClass }">
<a class="skip-link" href="#main">Skip to content</a>
<header class="site-header">
	<div class="wrap header-inner">
		<a class="brand" href="index.html" aria-label="Pulse Question home">
			<img src="assets/logo.png" alt="" width="50" height="30">
			<span>Pulse Question</span>
		</a>
		<nav class="site-nav" aria-label="Main">
			<a href="getting-started.html"${ active === 'docs' ? ' aria-current="page"' : '' }>Docs</a>
			<a href="${ repo }/releases" rel="noopener">v${ version }</a>
			<a href="${ repo }" rel="noopener" class="nav-github">GitHub</a>
		</nav>
	</div>
</header>
<main id="main">
${ body }
</main>
<footer class="site-footer">
	<div class="wrap footer-inner">
		<p>Pulse Question is free software, licensed GPL-2.0-or-later, the same license as WordPress. Built on <a href="https://bignall.github.io/wp-foundation/" rel="noopener">WP Foundation</a>.</p>
		<p><a href="${ repo }/releases/latest" rel="noopener">Download</a> · <a href="${ repo }" rel="noopener">Source</a> · <a href="${ repo }/issues" rel="noopener">Issues</a> · <a href="${ repo }/blob/main/readme.txt" rel="noopener">Changelog</a></p>
	</div>
</footer>
<script src="assets/site.js" defer></script>
${ scripts }</body>
</html>
`;
}

function docPage( doc, index ) {
	const { html, headings } = markdown( doc.source, `docs/${ doc.slug }.md` );
	const prev = docMeta[ index - 1 ];
	const next = docMeta[ index + 1 ];

	const sidebar = docMeta
		.map( ( d ) => `<li><a href="${ d.slug }.html"${ d.slug === doc.slug ? ' aria-current="page"' : '' }>${ escapeHtml( d.title ) }</a></li>` )
		.join( '\n' );

	const toc = headings.length > 1
		? `<nav class="toc" aria-label="On this page"><p class="toc-title">On this page</p><ul>${ headings
			.map( ( h ) => `<li><a href="#${ h.id }">${ h.text }</a></li>` )
			.join( '' ) }</ul></nav>`
		: '';

	const pager = `<nav class="pager" aria-label="Guides">
		${ prev ? `<a class="pager-prev" href="${ prev.slug }.html"><span>Previous</span>${ escapeHtml( prev.title ) }</a>` : '<span></span>' }
		${ next ? `<a class="pager-next" href="${ next.slug }.html"><span>Next</span>${ escapeHtml( next.title ) }</a>` : '<span></span>' }
	</nav>`;

	const body = `<div class="wrap docs-layout">
	<aside class="docs-sidebar">
		<details class="docs-menu" open>
			<summary>Guides</summary>
			<ul>${ sidebar }</ul>
		</details>
	</aside>
	<article class="prose">
		${ html }
		<p class="edit-link"><a href="${ repo }/edit/main/docs/${ doc.slug }.md" rel="noopener">Edit this page on GitHub</a></p>
		${ pager }
	</article>
	${ toc }
</div>`;

	return layout( { title: doc.title, description: summaries[ doc.slug ], body, active: 'docs', bodyClass: 'is-docs' } );
}

function landingPage() {
	let html = read( 'site/index.html' );

	const docCards = docMeta
		.map( ( d ) => `<li><a href="${ d.slug }.html"><strong>${ escapeHtml( d.title ) }</strong><span>${ escapeHtml( summaries[ d.slug ] ) }</span></a></li>` )
		.join( '\n' );

	// Replace {{code:lang}}…{{/code}} blocks with highlighted code.
	html = html.replace( /\{\{code:(\w+)\}\}\n?([\s\S]*?)\{\{\/code\}\}/g, ( _, lang, code ) =>
		`<pre class="code" data-lang="${ lang }"><code>${ highlight( code.replace( /\n$/, '' ), lang ) }</code></pre>`
	);
	html = html
		.replaceAll( '{{version}}', version )
		.replaceAll( '{{repo}}', repo )
				.replaceAll( '{{docCards}}', docCards );

	return layout( {
		title: 'Pulse Question',
		description: 'A WordPress plugin for a question of the day: a queue you control, questions written by Claude, member answers checked automatically, a daily email and social sharing.',
		body: html,
		active: 'home',
		bodyClass: 'is-home',
	} );
}

// Build.
rmSync( out, { recursive: true, force: true } );
mkdirSync( join( out, 'assets' ), { recursive: true } );

cpSync( join( root, 'site/assets' ), join( out, 'assets' ), { recursive: true } );
cpSync( join( root, 'docs/images' ), join( out, 'images' ), { recursive: true } );
writeFileSync( join( out, '.nojekyll' ), '' );

writeFileSync( join( out, 'index.html' ), landingPage() );
docMeta.forEach( ( doc, i ) => writeFileSync( join( out, `${ doc.slug }.html` ), docPage( doc, i ) ) );

if ( ! existsSync( join( out, 'index.html' ) ) ) {
	throw new Error( 'Build failed: no index.html' );
}
console.log( `Built Pulse Question site v${ version }: ${ docMeta.length + 1 } pages in _site/` );
