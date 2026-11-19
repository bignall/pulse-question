# Branding and design

## Your logo and colours

In **Settings → Branding**:

- **Logo**, shown on share images. Choose one of:
  - **Automatic**: your site's logo (from the Customizer or Site Editor), or the site icon if there's no logo.
  - **The Pulse Question logo**.
  - **An image** from the Media Library. A wide logo with a transparent background works best.
- **Main colour** for buttons and links, and **second colour** for labels and the pulse line. They're used on the site, in the daily email and on share images. Leave them empty to follow your theme's Automatic.css colours on the site, with orange and teal elsewhere.

## The plugin's styles

The front-end styles are deliberately light. They inherit your theme's fonts. When the site has [Automatic.css](https://automaticcss.com/), they also use its variables: `--primary`, `--secondary`, `--space-*`, `--h1`, `--text-*` and `--radius-m`. Your theme's styles always win.

To restyle, set these custom properties on any parent element:

| Property | What it's for |
|---|---|
| `--pq-accent` | Buttons, links, the current sort, highlights |
| `--pq-accent-2` | The label and the pulse line |
| `--pq-card-bg` | Past-question cards and boxes |
| `--pq-border` | Borders |
| `--pq-radius` | Corners |
| `--pq-gap` | Spacing between cards |

To style everything yourself, untick **Settings → Styles → Use the plugin's styles**. The markup uses `pq-` classes, for example `.pq-today`, `.pq-today__question`, `.pq-cards`, `.pq-card`, `.pq-answers`, `.pq-answer`, `.pq-share` and `.pq-button`.

## Showing questions

- **Blocks:** *Question of the day* (with an option to hide the background) and *Past questions* (how many, page links, a category).
- **Shortcodes:**

```text
[pulse_question]                          today's question
[pulse_question background="0"]           without its background text
[pulse_question_past]                     cards of past questions, with page links
[pulse_question_past count="6" paged="0"] the six latest, no page links
[pulse_question_past category="ethics"]   one category (slug or id)
```

- **Pages:** each question has its own page (with answers and share links), and all asked questions are listed at `/questions/`.
- **Block themes** (WordPress 6.7+) get templates for a question's page, `/questions/` and category pages. Your theme's own templates, or ones you change in the Site Editor, take over.

## Etch and other page builders

Query questions in any WP Query loop with the `pulse_question_view` argument:

```php
new WP_Query( array( 'post_type' => 'pulse_question', 'pulse_question_view' => 'today' ) );                  // today's question
new WP_Query( array( 'post_type' => 'pulse_question', 'pulse_question_view' => 'past', 'posts_per_page' => 12 ) ); // asked, newest first
```

Loops set up before 0.6.0 with `pq_question` and `pq_view` keep working. A question's post date is the day it was last asked, so builders can show and sort by it. The background is the post content. To show the answers and share links on a custom question template, use the post content, or call `PulseQuestion\Front\AnswersView::render( $post_id )` and `PulseQuestion\Social\ShareButtons::render( $post_id )`.
