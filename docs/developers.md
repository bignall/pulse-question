# Developers

## Actions

| Action | Arguments | When |
|---|---|---|
| `pulse_question_new_day` | `$id, $day, $reused` | A new question of the day is chosen |
| `pulse_question_claude_wrote` | `$ids, $trigger` | Claude's questions were added (`manual` or `auto`) |
| `pulse_question_member_joined` | `$user_id, $marketing, $list` | A member made their mailing-list choice |
| `pulse_question_answer_hidden` | `$comment_id` | Reports hid an answer |
| `pulse_question_email_sent` | `$ok, $detail` | After each try at sending the daily email |
| `pulse_question_unsubscribed` | `$user_id` | A member unsubscribed from the built-in email |
| `pulse_question_social_posted` | `$ok, $message` | After each automatic social post |

## Filters

| Filter | Value |
|---|---|
| `pulse_question_claude_base_url` | API address, for a proxy or gateway |
| `pulse_question_claude_timeout` | Seconds for writing questions (default 180) |
| `pulse_question_moderation_timeout` | Seconds for an answer check while the member waits (default 25) |
| `pulse_question_email` | The daily email: `subject`, `preheader`, `body`, `url` |
| `pulse_question_email_colors` | `accent`, `text`, `muted` |
| `pulse_question_email_batch_size` | Built-in sender, emails per minute (default 40) |
| `pulse_question_share_image` | The share image for a question: `url`, `width`, `height`, `path` (or null) |
| `pulse_question_share_image_colors` | `background`, `text`, `label`, `accent`, `line` |
| `pulse_question_share_image_logo` | Path to the logo file |
| `pulse_question_share_image_font` | Path to a TTF or OTF font |
| `pulse_question_share_links` | Share links: `key => [ label, url ]` |
| `pulse_question_social_payload` | The data sent to the social webhook |
| `pulse_question_seo_plugin` | Name of an SEO plugin writing share tags (the plugin then skips its own) |
| `pulse_question_setup_steps` | The setup checklist |
| `pulse_question_block_templates` | `false` turns off the block-theme templates |

## REST routes

Both need a logged-in member and an `X-WP-Nonce` header (`wp_rest`):

- `POST /wp-json/pulse-question/v1/vote` with `type` (`question` or `answer`) and `id`: toggles a vote and returns the count.
- `POST /wp-json/pulse-question/v1/report` with `id`: reports an answer.

## Data

- **Questions:** post type `pq_question`, categories `pq_category`. The statuses are `draft`, `pending`, `pq_approved` (queued) and `publish` (asked). `_pq_asked` holds each day asked; `_pq_last_asked` holds the last.
- **Answers:** comments on questions, with `_pq_votes` (likes), `_pq_check` (the moderation verdict) and `_pq_reports`.
- **Votes:** the table `{prefix}pq_votes`.
- **Settings:** the option `pq_settings`.

## Building from source

```bash
composer install      # installs WP Foundation and the Anthropic SDK, prefixed with Strauss
composer test         # unit tests
vendor/bin/phpcs      # WordPress coding standards and PHP 8.1 compatibility
bin/build-zip.sh      # writes dist/pulse-question-<version>.zip
npm install && npm run site:build   # this site, into _site/
```

Pushing a tag such as `v0.6.0` that matches the `Version:` header builds the zip and publishes a GitHub release.
