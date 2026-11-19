# Pulse Question

A WordPress plugin for a thought-provoking question every day. Add questions (or approve ones waiting for approval), and each day the next one in the queue becomes the question of the day, with cards of past questions below it.

- **Queue** (Questions → Queue): add questions one per line, approve the ones waiting, drag to set the order, and see the day each will be asked. "Ask now" swaps today's question.
- **Approval:** questions go draft → waiting for approval → approved (in the queue) → asked. Your own questions can skip approval (Settings). A question only goes public when it's asked.
- **Never runs dry:** every approved question is asked once, in order. When the queue is empty, the question asked longest ago is asked again (never the same one two days running).
- **When it changes:** at the time you choose, in the site's timezone, on the first visit or hourly check after that time, so it doesn't depend on WP-Cron running on time.
- **On your site:** "Question of the day" and "Past questions" blocks, `[pulse_question]` and `[pulse_question_past]` shortcodes, a page for each question, categories, and the list of asked questions at `/questions/`. Settings has a button that makes a page with both (and can make it the home page).
- **Styling:** inherits your theme's fonts; uses Automatic.css variables (`--primary`, `--secondary`, `--space-*`, `--h1`, `--text-*`, `--radius-m`) when present. Change `--pq-accent`, `--pq-accent-2`, `--pq-card-bg`, `--pq-border` and `--pq-radius` to restyle, or turn the plugin's styles off in Settings and style the `pq-` classes yourself.
- **Page builders (Etch and others):** query questions with `pq_view` = `today` or `past` in any WP Query loop. A question's date is the day it was last asked.
- **Works with [To-do Hub](https://github.com/bignall/todo-hub):** a task when the queue gets short (high priority once it's empty) or questions are waiting for approval.

- **Questions written by Claude:** press **Write questions** in the Queue (with an optional theme), or turn on automatic top-up so Claude writes a batch whenever the queue gets short. They wait for your approval unless you turn on auto-approve. See below.

Coming next: answers, votes and comments; then a daily email and social posts.

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

## Shortcodes

```
[pulse_question]                          today's question
[pulse_question background="0"]           without its background text
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
bin/build-zip.sh      # writes dist/pulse-question-<version>.zip
```

Pushing a tag such as `v0.1.0` that matches the `Version:` header builds the zip and publishes a GitHub release. Notes come from `release-notes/<tag>.md`.

Built on [WP Foundation](https://bignall.github.io/wp-foundation/). GPL-2.0-or-later.
