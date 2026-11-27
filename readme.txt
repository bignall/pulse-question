=== Pulse Question ===
Contributors: bignall
Tags: question of the day, daily question, prompts, engagement, journal
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.8.2
License: GPLv2 or later

A question of the day for WordPress: a queue you control, member answers checked by Claude, a daily email and share images.

== Description ==

Pulse Question asks your visitors one thought-provoking question each day. Members answer, vote and reply. Claude can write the questions and check every answer before it appears. Each new question goes out by email and to social media. Guides: https://bignall.github.io/pulse-question/


* Questions → Queue: add questions one per line, approve the ones waiting for approval, drag to set the order and see the day each will be asked. "Ask now" makes a question today's.
* Questions move from draft to waiting for approval to approved (in the queue) to asked. Your own questions can be approved straight away. A question only appears on the site once it has been asked.
* Each day at the time you choose, the next approved question becomes the question of the day. When the queue is empty, the question asked longest ago is asked again, so there's always a question.
* Show it with the "Question of the day" and "Past questions" blocks or the [pulse_question] and [pulse_question_past] shortcodes. Each question has its own page; asked questions are listed at /questions/, and by category.
* Settings can make a page with both blocks and set it as the home page.
* Your logo and colours on share images, in the email and on the site. It inherits your theme's fonts and follows Automatic.css; restyle with the --pq-accent, --pq-card-bg, --pq-border and --pq-radius CSS properties, or turn the plugin's styles off and use Etch or another builder (pulse_question_view query argument).
* A setup checklist walks new sites through everything.
* Questions written by Claude (optional, with your own Anthropic API key): on demand or to keep the queue topped up, waiting for your approval unless you choose otherwise.
* Answers from logged-in members, with votes, replies and reports. Members can join with their Google account. Today's question has its own answer box, with the top answers so far and recent answers under it. New members are offered your mailing list (FluentCRM), with the boxes ticked by default.
* Automatic moderation: Claude checks each answer against your guidelines and publishes it, holds it for you, or keeps it off the site.
* Daily email: each new question, with yesterday's top answers, sent as a FluentCRM campaign (on this site or another) or by a simple built-in sender with unsubscribe links.
* Social sharing: a share image for each question, share tags for links, share buttons, and automatic posts through a webhook (Zapier, Make, n8n and others).
* Works with To-do Hub: a task when the queue gets short, when questions or answers are waiting for approval, or when the daily email or a social post fails.
* Built on WP Foundation (activation, upgrades, uninstall, multisite, deactivation survey).

== Installation ==

1. Upload the plugin and activate it, or install it from the Plugins screen.
2. Open Questions → Settings and follow the setup checklist at the top.
3. To let visitors answer, turn on "Anyone can register" (role: Subscriber) under Settings → General.
4. Optional: add an Anthropic API key for questions written by Claude and automatic moderation, and connect FluentCRM for the mailing list.

== Frequently Asked Questions ==

= Do I need a Claude (Anthropic) API key? =

No. Without one, you write the questions and every answer waits for you to approve it. With one, Claude can write questions for your approval and check each answer as it's posted. You pay Anthropic for what you use: checking an answer costs roughly half a cent with the default model.

= Can people answer without an account? =

No. Answering, voting and reporting need a free account, which keeps spam down and lets members get the daily email. Guests can read everything.

= Do I need FluentCRM? =

No. Without it, members' choices are kept on your site and the built-in sender emails the daily question (fine for a few hundred members). With FluentCRM, on the same site or another, the email is sent as a FluentCRM campaign through your email service.

= What happens when I run out of questions? =

The question asked longest ago is asked again, so there's always a question. You also get a To-do Hub task, if you use it, and Claude can top up the queue automatically.

= Does it post to Facebook, Instagram or X directly? =

It sends each new question, with a ready-written post and its share image, to a webhook address from Zapier, Make, n8n, Pabbly or IFTTT. Your automation posts it to whichever networks you connect there.

= Will it work with my theme or page builder? =

It works with block and classic themes, with blocks, shortcodes and its own templates. For Etch and other builders, query questions with the pulse_question_view argument (today or past) and turn the plugin's styles off if you style everything yourself.

== Screenshots ==

1. Today's question, with the Answer button, share links and past questions.
2. Answers: members answer, like, reply and report. A held answer is shown only to its author.
3. The Queue: today's question, adding questions, Claude, and questions waiting for approval.
4. The daily email, with yesterday's top answers.
5. A share image, made for every question.
6. The setup checklist.

== Upgrade Notice ==

= 0.6.0 =
Adds branding settings and a setup checklist, and renames the plugin's data to longer, unique names. The data moves by itself; back up first, as with any update. Check any page-builder template you made for questions afterwards.

== External services ==

When you use "Write questions with Claude" or automatic top-up, the plugin sends the guidance from Settings, the names of your question categories and the titles of your latest 300 questions to the Anthropic API (api.anthropic.com), to write new questions. Nothing is sent until you add an API key. When automatic moderation is on (the default once a key is added), the text of each answer and the question it answers are sent to the Anthropic API, together with your moderation guidelines, to decide whether to publish it. Member names and email addresses are not sent. Anthropic's terms: https://www.anthropic.com/legal/commercial-terms and privacy policy: https://www.anthropic.com/legal/privacy

If you connect a FluentCRM site on another domain (Settings → Members), each new member's email address, name, chosen lists and tags are sent to that site's REST API. With FluentCRM on the same site, nothing leaves your site.

If you set a social webhook address (Settings → Social sharing), each new question's text, link, background, category and share image address are sent to that address, which you choose (for example Zapier, Make or n8n). Nothing is sent until you add one.

The daily email, when turned on, goes through FluentCRM (on this site, or the FluentCRM site set under Members, as a campaign to your lists) or through WordPress's own email (wp_mail) to members who chose it.

Share links on question pages are plain links to Facebook, X, LinkedIn, Pinterest and Bluesky's share pages; nothing is loaded from those sites until a visitor clicks one.

Share images use the Inter font by The Inter Project Authors, under the SIL Open Font License 1.1 (assets/fonts/LICENSE-Inter.txt).

== Changelog ==

= 0.8.2 =
* Fixed curly quotes and other special characters showing as codes such as &#8217; in social posts, the daily email subject and heading, share-button text and share tags.

= 0.8.1 =
* A member whose name is an email address (often a display name nobody changed) is shown by the part before the @, so email addresses never appear with answers or in the daily email.

= 0.8.0 =
* Sign in with Google: members can join or log in with their Google account, on the log-in and sign-up pages and in the answer box. Set it up under Settings → Members.
* An answer box under today's question, so visitors can answer without leaving the home page.
* Until today's question has answers, yesterday's top answers are shown instead. The newest answers to any question are listed too (five by default).
* Past question cards have an answers button, and take the site's colours.
* New shortcodes for page builders: [pulse_question_answer_box], [pulse_question_top_answers], [pulse_question_recent_answers] and [pulse_question_answers_button].

= 0.7.0 =
* Today's question now shows its top answers so far (three by default), each linking to the full answer, with a "Read all" link. Set the number, or 0 for none, under Settings → Answers; the Question of the day block and [pulse_question answers="5"] can override it.

= 0.6.2 =
* Fixed the message shown when Anthropic's servers are busy, which read "PulseQuestion\Vendor\Anthropic's servers…".

= 0.6.1 =
* The plugin's author is now RB Creative Solutions.

= 0.6.0 =
* Branding: choose the logo for share images (your site's logo or icon, the Pulse Question logo, or any image) and your two colours, used on the site, in the daily email and on share images.
* A From name and address for the built-in daily email.
* A setup checklist at the top of Settings, and a notice after activation that leads to it.
* Guides at https://bignall.github.io/pulse-question/
* Tested with WordPress 7.1. Checked with the WordPress coding standards.
* Uninstalling (with "delete all data" on) also removes members' Pulse Question details.
* Sites upgrading keep the Pulse Question logo on share images until they choose another.
* Longer, unique names (wordpress.org asks for prefixes of at least four letters): pq_ becomes pulse_question_, the post type is now pulse_question, the taxonomy pulse_question_category, and the query argument pulse_question_view. Upgrading moves each site's data once, by itself. Queries using the old names (such as Etch loops) keep working, and so do unsubscribe links in emails already sent.

= 0.5.0 =
* Daily email: each new question goes out about a minute after it's asked, once a day, with yesterday's three most-liked answers. With FluentCRM, it's sent as a campaign to your daily email lists; without it, a built-in sender emails members who chose the daily email, 40 a minute, with unsubscribe links. Settings for the subject, an opening line, the button text and the number of answers, plus Preview, Send me a test and Send now. Failures are retried hourly and get a To-do Hub task.
* Social sharing: a 1200×630 share image for each question (or its featured image), share tags for links (handing the image to Yoast SEO or Rank Math when they're active), share links under each question, and automatic posts through a signed webhook, with a test button. Failures are retried and get a To-do Hub task.
* Fix: with FluentCRM on another site, double opt-in contacts now get FluentCRM's confirmation email. Before, they stayed pending without one.
* The mailing list settings and Test connection now name the FluentCRM permissions the user needs.

= 0.4.1 =
* A "Test connection" button for the mailing list (Settings → Members). It checks the saved settings step by step and says which step failed and what to do: a redirect that drops the login, wrong or refused details, a server that drops the login header, a user without FluentCRM permission, FluentCRM not active, or list ids that don't exist.

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
