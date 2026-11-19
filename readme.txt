=== Pulse Question ===
Contributors: arbe
Tags: question of the day, daily question, prompts, engagement, journal
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.3.0
License: GPLv2 or later

A thought-provoking question every day: a queue you control, a daily pick, today's question and cards of past ones.

== Description ==

* Questions → Queue: add questions one per line, approve the ones waiting for approval, drag to set the order and see the day each will be asked. "Ask now" makes a question today's.
* Questions move from draft to waiting for approval to approved (in the queue) to asked. Your own questions can be approved straight away. A question only appears on the site once it has been asked.
* Each day at the time you choose, the next approved question becomes the question of the day. When the queue is empty, the question asked longest ago is asked again, so there's always a question.
* Show it with the "Question of the day" and "Past questions" blocks or the [pulse_question] and [pulse_question_past] shortcodes. Each question has its own page; asked questions are listed at /questions/, and by category.
* Settings can make a page with both blocks and set it as the home page.
* Inherits your theme's fonts and colours. Restyle with the --pq-accent, --pq-card-bg, --pq-border and --pq-radius CSS properties.
* Questions written by Claude (optional, with your own Anthropic API key): on demand or to keep the queue topped up, waiting for your approval unless you choose otherwise.
* Works with To-do Hub: a task when the queue gets short or questions are waiting for approval.
* Built on WP Foundation (activation, upgrades, uninstall, multisite, deactivation survey).

== External services ==

When you use "Write questions with Claude" or automatic top-up, the plugin sends the guidance from Settings, the names of your question categories and the titles of your latest 300 questions to the Anthropic API (api.anthropic.com), to write new questions. Nothing is sent until you add an API key. Anthropic's terms: https://www.anthropic.com/legal/commercial-terms and privacy policy: https://www.anthropic.com/legal/privacy

== Changelog ==

= 0.3.0 =
* Questions written by Claude: a "Write questions" button in the Queue (with an optional theme), and optional automatic top-up when the queue gets short. Claude's questions wait for approval unless auto-approve is on. Editable guidance (default: big variety, politics only on rare occasions), model and effort settings, and a log of recent batches. Needs an Anthropic API key (Settings, or PULSE_QUESTION_ANTHROPIC_API_KEY in wp-config.php).

= 0.2.0 =
* Styles follow Automatic.css (colours, spacing, type sizes, corners) when the site has it, with the logo's orange and teal as fallbacks, and a pulse line under today's question. A new setting turns the plugin's styles off for sites styled entirely in a builder.
* New query argument for page builders such as Etch: pq_view = today (the question of the day) or past (asked questions, last asked first).
* A question's date is now the day it was last asked, so builders and themes can show and sort by it.

= 0.1.0 =
* First release: questions with approval, the queue, the daily pick (with reuse when the queue is empty), blocks, shortcodes, question pages and categories, and To-do Hub tasks.
