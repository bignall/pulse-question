# Pulse Question

A WordPress plugin for a thought-provoking question every day. Add questions (or approve ones waiting for approval), and each day the next one in the queue becomes the question of the day, with cards of past questions below it.

- **Queue** (Questions → Queue): add questions one per line, approve the ones waiting, drag to set the order, and see the day each will be asked. "Ask now" swaps today's question.
- **Approval:** questions go draft → waiting for approval → approved (in the queue) → asked. Your own questions can skip approval (Settings). A question only goes public when it's asked.
- **Never runs dry:** every approved question is asked once, in order. When the queue is empty, the question asked longest ago is asked again (never the same one two days running).
- **When it changes:** at the time you choose, in the site's timezone, on the first visit or hourly check after that time, so it doesn't depend on WP-Cron running on time.
- **On your site:** "Question of the day" and "Past questions" blocks, `[pulse_question]` and `[pulse_question_past]` shortcodes, a page for each question, categories, and the list of asked questions at `/questions/`. Settings has a button that makes a page with both (and can make it the home page).
- **Styling:** inherits your theme's fonts; uses Automatic.css variables (`--primary`, `--secondary`, `--space-*`, `--h1`, `--text-*`, `--radius-m`) when present. Change `--pq-accent`, `--pq-accent-2`, `--pq-card-bg`, `--pq-border` and `--pq-radius` to restyle, or turn the plugin's styles off in Settings and style the `pq-` classes yourself.
- **Page builders (Etch and others):** query `pulse_question` posts with `pulse_question_view` = `today` or `past` in any WP Query loop (the pre-0.6.0 names `pq_question` and `pq_view` still work). A question's date is the day it was last asked.
- **Works with [To-do Hub](https://github.com/bignall/todo-hub):** a task when the queue gets short (high priority once it's empty) or questions are waiting for approval.

- **Questions written by Claude:** press **Write questions** in the Queue (with an optional theme), or turn on automatic top-up so Claude writes a batch whenever the queue gets short. They wait for your approval unless you turn on auto-approve. See below.

- **Answers from members, checked automatically:** members log in to answer, vote and reply. Every new member is offered the mailing list. Claude checks every answer before it appears, and members can report answers. See below.

- **Daily email and social posts:** each new question is emailed to the mailing list, as a FluentCRM campaign or by the built-in sender, with yesterday's top answers. Each question gets a share image, share tags and share links, and can be posted automatically through a webhook (Zapier, Make, n8n…). See below.

- **Ready for other sites:** your logo and colours (Settings → Branding), a setup checklist for new sites, and [guides](https://bignall.github.io/pulse-question/).

**Guides:** https://bignall.github.io/pulse-question/ (built from `docs/` and `site/` by `.github/workflows/pages.yml`).

## Questions written by Claude

Add an [Anthropic API key](https://platform.claude.com/settings/keys) under **Questions → Settings**, or keep it out of the database with this in `wp-config.php`:

```php
define( 'PULSE_QUESTION_ANTHROPIC_API_KEY', 'sk-ant-...' );
```

- **On demand:** Questions → Queue → *Write questions with Claude*: how many, and an optional theme.
- **Automatic top-up:** when the questions in the queue plus those waiting for approval cover fewer days than you set (14 by default), the hourly check writes a batch (10 by default). At most one batch every 6 hours; after a failure the wait doubles, up to 2 days.
- **Approval:** Claude's questions wait for approval (marked *Claude* in the Queue) unless *Approve Claude's questions straight away* is on.
- **Guidance:** editable in Settings. The default asks for big variety and keeps politics to rare occasions.
- **What's sent:** the guidance, the categories in use and the titles of the latest 300 questions (so it doesn't repeat itself). Each question comes back with a background line and a category. Repeats of existing questions are left out.
- **Model:** Claude Opus 5.5 at medium effort by default, through the official [Anthropic PHP SDK](https://github.com/anthropics/anthropic-sdk-php) (bundled and prefixed, sending requests through WordPress's HTTP API). Requests use server-side fallbacks, so one the model declines for policy reasons is retried on a fallback model. Recent batches, with token counts, are listed in Settings.

Filters: `pulse_question_claude_base_url` (a proxy or gateway address), `pulse_question_claude_timeout` (seconds, default 180). Action: `pulse_question_claude_wrote( $ids, $trigger )`.

## Answers, members and moderation

Answers are WordPress comments on questions, so replies, Akismet (if active) and **Comments** in the admin all work as usual.

- **Members only:** you have to be logged in to answer, vote or report. Guests see the answers, a *Log in to answer* button and a *Join with email* button, plus *Continue with Google* when it's set up.
- **Sign in with Google:** add a Google OAuth client id and secret under Settings → Members (or `PULSE_QUESTION_GOOGLE_CLIENT_ID` and `PULSE_QUESTION_GOOGLE_CLIENT_SECRET` in `wp-config.php`). Members can then join or log in with their Google account on the log-in and sign-up pages and in the answer box. A Google account matches a member by its verified email address; accounts that can edit posts keep using their password. New members get the mailing list boxes with their first answer. *Join* appears when **Settings → General → Anyone can register** is on; use *Subscriber* as the new user role.
- **Mailing list:** the sign-up form has two boxes, *Send me the question of the day by email* and *Also send me occasional news and offers*. Both are ticked to begin with and neither is required. Members who signed up some other way see the boxes once, with their first answer. A setting turns off the pre-ticking. In the EU and UK, a pre-ticked box doesn't count as consent. Members are added to FluentCRM on the same site or on another site; see Settings → Members. With double opt-in, FluentCRM sends its confirmation email. If a sign-up fails, it's tried again every hour. Recent sign-ups are listed in Settings. **Test connection** (Settings → Members) checks the setup step by step and says what to fix.
- **On the home page:** today's question has its own answer box (or *Continue with Google*, *Log in* and *Join* buttons for guests), so visitors can answer without leaving the page. Below it are the three most-liked answers so far, or yesterday's top answers until today has some, then the five newest answers to any question. Each links to the full answer. Set the numbers (0 for none) and turn the box off under Settings → Answers. The block and the `[pulse_question]` shortcode can override them.
- **Past question cards** have an answers button: *12 answers*, or *Answer* when there are none yet.
- **Votes:** members can mark a question as *Good question* and like answers (not their own). Answers sort by *Top* or *Newest*. Replies go one level deep.
- **Automatic moderation (default):** Claude checks each answer as it's posted, against guidelines you can edit:
  - fine → published straight away;
  - unsure → held for you under **Comments → Pending**;
  - clearly against the rules (swearing, slurs, harassment, hate, sexual content, threats, spam, other people's personal details) → kept off the site (sent to Spam or Trash, both kept 30 days).

  The default guidelines send links, mentions of self-harm, political campaigning and off-topic answers for review. The member sees their held answer marked *Waiting for review*; nobody else does. If Claude can't be reached, the answer is held and checked again later, so nothing appears unchecked. Without an API key, every answer is held. The **Check** column under Comments shows each verdict and the reason for it.
- **Other modes:** hold every answer for you, or use WordPress's own discussion settings only.
- **Reports:** when three members report an answer (you can change the number), it's hidden until you look at it.
- **To-do Hub:** a task when answers are waiting for review.
- **Cost:** a check uses Claude Opus 5.5 at low effort by default, roughly half a cent per answer. You can choose a smaller, cheaper model in Settings.

WordPress's own limit of one comment every 15 seconds per person applies to answers too.

Filters: `pulse_question_moderation_timeout` (seconds to wait while the member waits, default 25). Actions: `pulse_question_member_joined( $user_id, $marketing, $list )`, `pulse_question_google_joined( $user_id )` and `pulse_question_answer_hidden( $comment_id )`. REST: `POST pulse-question/v1/vote` and `/report` (logged in, with a `X-WP-Nonce`).

## Daily email

Turn it on under **Questions → Settings → Daily email**. Each new question is sent about a minute after it goes up, once per question day. Turning it on starts with the next question; **Send today's email now** sends today's.

- **With FluentCRM** (on this site or another, set up under Members): a FluentCRM campaign goes to the daily email lists, to subscribed contacts only. FluentCRM adds its footer and unsubscribe link, does the sending through your email service, and reports opens and clicks. With FluentCRM on another site, the user needs the *Write and send emails* permission, as well as *Add, update and import contacts* and *Create and update tags, lists, companies and segments*.
- **Without FluentCRM**, the built-in sender emails members who chose the daily email, 40 a minute through `wp_mail` (an SMTP plugin is used if you have one). It includes an unsubscribe link and one-click unsubscribe headers. It's fine for a few hundred members; for more, use FluentCRM and an email service.
- **The email** has today's question, its background, an Answer button and the three most-liked answers to yesterday's question. The subject (`{question}`, `{date}`, `{site}`), an opening line, the button text and the number of answers are all settings.
- **Preview** and **Send me a test** in Settings. A failed send is tried again hourly (up to five times), and To-do Hub gets a task until it works.

Filters: `pulse_question_email` (subject, preheader, body), `pulse_question_email_colors`, `pulse_question_email_batch_size`. Actions: `pulse_question_email_sent( $ok, $detail )`, `pulse_question_unsubscribed( $user_id )`.

## Social sharing

- **Share image:** 1200×630 for each question, with the logo, label, question and site address, made with PHP's GD library. A question's featured image is used instead when it has one.
- **Share tags:** Open Graph and X card tags on question pages, so shared links show the question and image. With Yoast SEO or Rank Math, they write the tags and the plugin gives them the share image.
- **Share links** under each question: Facebook, X, LinkedIn, Pinterest, Bluesky, email and copy link, plus the phone's share sheet. These are plain links, with no third-party scripts.
- **Automatic posts:** paste a webhook address from Zapier, Make, n8n, Pabbly or IFTTT. Each new question is sent there as JSON: `question`, `url`, `text` (from your post template), `hashtags`, `image_url`, `background`, `category`, `date` and `test`. Your automation posts it to the networks you connect. Requests are signed: `X-Pulse-Question-Signature: sha256=` followed by the HMAC-SHA256 of the body, keyed with the secret shown in Settings. Use **Send a test post** to map the fields. Failures are retried and get a To-do task.

Filters: `pulse_question_share_image`, `pulse_question_share_image_colors`, `pulse_question_share_image_logo`, `pulse_question_share_image_font`, `pulse_question_share_links`, `pulse_question_social_payload`, `pulse_question_seo_plugin`. Action: `pulse_question_social_posted( $ok, $message )`.

## Shortcodes

```
[pulse_question]                          today's question
[pulse_question background="0"]           without its background text
[pulse_question answers="5"]              with its five top answers (0 = none)
[pulse_question recent="0" box="0"]       without recent answers or the answer box
[pulse_question_answer_box]               the answer box for today's question
[pulse_question_top_answers count="3"]    today's top answers (yesterday's until today has some)
[pulse_question_recent_answers count="5"] the newest answers to any question
[pulse_question_answers_button]           a question's answers button, as on the cards (in a loop)
[pulse_question_past]                     cards of past questions, with page links
[pulse_question_past count="6" paged="0"] the six latest, no page links
[pulse_question_past category="ethics"]   one category (slug or id)
```

## For developers

`do_action( 'pulse_question_new_day', $question_id, $day, $reused )` fires when a new question of the day is chosen.

## Install

Download `pulse-question-x.y.z.zip` from the [latest release](https://github.com/bignall/pulse-question/releases/latest) and upload it under **Plugins → Add New → Upload Plugin**. Requires PHP 8.1+ and WordPress 6.4+.

## Build from source

```bash
composer install      # installs WP Foundation and prefixes it with Strauss
composer test         # runs the unit tests (PHPUnit)
vendor/bin/phpcs      # WordPress coding standards and PHP 8.1 compatibility
npm install && npm run site:build   # the guides site, into _site/
bin/build-zip.sh      # writes dist/pulse-question-<version>.zip
```

Pushing a tag such as `v0.1.0` that matches the `Version:` header builds the zip and publishes a GitHub release. Notes come from `release-notes/<tag>.md`.

Built on [WP Foundation](https://bignall.github.io/wp-foundation/). GPL-2.0-or-later. Share images use the [Inter](https://github.com/rsms/inter) font (SIL Open Font License 1.1, see `assets/fonts/LICENSE-Inter.txt`).
