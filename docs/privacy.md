# Privacy and data

## What's sent elsewhere

Nothing leaves your site until you set up the feature that sends it.

| Feature | Sent to | What |
|---|---|---|
| Questions written by Claude | Anthropic (api.anthropic.com) | Your guidance, category names and the titles of your latest 300 questions |
| Automatic moderation | Anthropic | Each answer's text and the question it answers. Not the member's name or email. |
| FluentCRM on another site | That site | Each new member's email address, name, chosen lists and tags |
| Daily email via FluentCRM | Your FluentCRM site | The email's subject and content, and which lists to send to |
| Sign in with Google | Google | Members who choose it are sent to Google to sign in. Google sends back their name, email address and Google account id. |
| Social webhook | The address you enter | Each new question's text, link, background, category and share image address |

Anthropic's [commercial terms](https://www.anthropic.com/legal/commercial-terms) and [privacy policy](https://www.anthropic.com/legal/privacy) apply to what's sent to Claude. Share links on question pages are plain links: nothing is loaded from Facebook, X, LinkedIn, Pinterest or Bluesky until a visitor clicks one.

## What's stored on your site

- **Members** are WordPress users. Pulse Question stores their mailing-list choices (`_pulse_question_joined`, `_pulse_question_marketing`, `_pulse_question_join_asked`), their Google account id if they sign in with Google (`_pulse_question_google_sub`), and their votes.
- **Answers** are comments, with their votes, moderation verdict and the ids of members who reported them.
- **Logs** of recent Claude batches, sign-ups, daily emails and social posts. Each keeps the latest 14 to 20 entries.

When a user is deleted, their votes are removed and the counts are updated. Their answers follow WordPress's usual choice: delete them, or give them to another user.

Add a section about answers, the mailing list and Claude's checks to your privacy policy (**Settings → Privacy**).

## Uninstalling

Deactivating keeps everything. To remove all of Pulse Question's data when you delete the plugin, first tick **Settings → Uninstall**. Deleting the plugin then removes:

- the questions, with their answers;
- the categories;
- the votes table;
- the settings and logs;
- the share images;
- members' mailing-list choices.

Members' accounts stay, because they're WordPress users. Contacts already in FluentCRM stay there too.
