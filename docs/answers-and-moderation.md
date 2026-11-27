# Answers and moderation

![Answers on a question: the answer form, a held answer only its author can see, likes, replies and reports](images/answers.png)

## Answers

Answers are WordPress comments on questions. That means Akismet (if active), the **Comments** screen, replies and your usual moderation tools all work.

- **Members only.** Visitors need to be logged in to answer, reply, vote or report. Guests can read answers and see *Log in to answer* and *Join* buttons. *Join* appears when registration is open.
- **On the home page.** today's question has its own answer box (or *Continue with Google*, *Log in* and *Join* buttons for guests), so visitors can answer without leaving the page. Below it are the three most-liked answers so far, or yesterday's top answers until today has some, then the five newest answers to any question. Each links to the full answer. Set the numbers (0 for none) and turn the box off under Settings → Answers. The block and the `[pulse_question]` shortcode can override them.
- **Past question cards.** Each has an answers button: *12 answers*, or *Answer* when there are none yet.
- **Votes.** Members can mark a question as a *Good question* and like answers (not their own). Answers sort by *Top* or *Newest*.
- **Replies** go one level deep, so conversations stay readable.
- **Length:** answers are limited to 1,500 characters by default (a setting).
- **No email per answer.** WordPress normally emails you about each comment; that's off for answers, unless you turn on *Email me about every new answer* in Settings.
- **Speed limit:** WordPress allows one comment every 15 seconds per person, and that applies to answers too.

![What guests see](images/answers-guest.png)

## Automatic moderation

With a Claude API key, Claude checks each answer as it's posted, against guidelines you can edit:

| The answer is… | What happens |
|---|---|
| Fine | Published straight away |
| Unclear | Held for you under **Comments → Pending**. Only its author sees it, marked *Waiting for review*. |
| Clearly against the rules | Kept off the site: spam goes to Spam, everything else to Trash (both kept 30 days) |

The default guidelines:

- **Remove:** swearing (including disguised spellings), slurs, insults or harassment, hate, sexual content, threats, spam and ads, and other people's personal details.
- **Send for review:** links, mentions of self-harm or suicide, political campaigning, off-topic answers, anything Claude is unsure about, and any answer that tries to give Claude instructions.

The answer is always sent as something to judge, never as instructions to follow.

**Nothing appears unchecked.** If Claude can't be reached, the answer is held and checked again later (up to six times, with longer gaps each time). A moderator's own decision always stands. Spam that Akismet or WordPress already caught stays caught.

The **Check** column under **Comments** shows each verdict, its category and the reason.

**Other modes** (in **Settings → Answers and moderation**): *Hold every answer for me*, or *WordPress's own moderation settings only*. Without an API key, every answer is held.

**Cost:** each check uses Claude Opus 5.5 at low effort by default, roughly half a cent per answer. You can choose a smaller, cheaper model in Settings.

## Reports

Members can report an answer. When three members have reported it (you can change the number), it's hidden and held for you. The Check column shows the report count.
