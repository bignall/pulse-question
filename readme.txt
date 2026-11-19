=== Pulse Question ===
Contributors: arbe
Tags: question of the day, daily question, prompts, engagement, journal
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.4.0
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
* Answers from logged-in members, with votes, replies and reports. New members are offered your mailing list (FluentCRM), with the boxes ticked by default.
* Automatic moderation: Claude checks each answer against your guidelines and publishes it, holds it for you, or keeps it off the site.
* Works with To-do Hub: a task when the queue gets short, or when questions or answers are waiting for approval.
* Built on WP Foundation (activation, upgrades, uninstall, multisite, deactivation survey).

== External services ==

When you use "Write questions with Claude" or automatic top-up, the plugin sends the guidance from Settings, the names of your question categories and the titles of your latest 300 questions to the Anthropic API (api.anthropic.com), to write new questions. Nothing is sent until you add an API key. When automatic moderation is on (the default once a key is added), the text of each answer and the question it answers are sent to the Anthropic API, together with your moderation guidelines, to decide whether to publish it. Member names and email addresses are not sent. Anthropic's terms: https://www.anthropic.com/legal/commercial-terms and privacy policy: https://www.anthropic.com/legal/privacy

If you connect a FluentCRM site on another domain (Settings → Members), each new member's email address, name, chosen lists and tags are sent to that site's REST API. With FluentCRM on the same site, nothing leaves your site.

== Changelog ==

= 0.4.0 =
* Answers: logged-in members answer each question, vote for good questions, like answers, reply and report. Answers sort by top or newest.
* Members: the sign-up form offers the daily question email and news and offers. Both boxes are ticked by default (a setting turns this off), and members who signed up earlier see them with their first answer. Sign-ups go to FluentCRM on this site or on another site, with double opt-in, and are retried if they fail.
* Automatic moderation by Claude against guidelines you can edit: fine answers appear straight away, doubtful ones wait for you, and clearly bad ones are kept off the site. If the check fails, the answer is held and checked again. Other modes: hold everything, or WordPress's own settings.
* Reported answers are hidden after a number of reports you choose. To-do Hub task for answers waiting for review. A Check column under Comments.

= 0.3.0 =
* Questions written by Claude: a "Write questions" button in the Queue (with an optional theme), and optional automatic top-up when the queue gets short. Claude's questions wait for approval unless auto-approve is on. Editable guidance (default: big variety, politics only on rare occasions), model and effort settings, and a log of recent batches. Needs an Anthropic API key (Settings, or PULSE_QUESTION_ANTHROPIC_API_KEY in wp-config.php).

= 0.2.0 =
* Styles follow Automatic.css (colours, spacing, type sizes, corners) when the site has it, with the logo's orange and teal as fallbacks, and a pulse line under today's question. A new setting turns the plugin's styles off for sites styled entirely in a builder.
* New query argument for page builders such as Etch: pq_view = today (the question of the day) or past (asked questions, last asked first).
* A question's date is now the day it was last asked, so builders and themes can show and sort by it.

= 0.1.0 =
* First release: questions with approval, the queue, the daily pick (with reuse when the queue is empty), blocks, shortcodes, question pages and categories, and To-do Hub tasks.
