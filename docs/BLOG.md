# From Shell Scripts to Symfony: Wrestling AI Through a Real Migration

I've been using AI off and on for about two years, consistently for the last year, and daily for the last six months.
It was a curiosity, then a Google replacement, and now an invaluable tool.

Recently I used a large task for a case study in AI assisted development.
In addition to writing code, we (the AI and I) documented patterns for the AI to do or not do, wrote documentation for users and collaborators, and wrote a case study and this blog post.
Our collaboration created a well documented open source project that is a useful tool for myself and my client.
See https://github.com/douggreen/solarwinds-php-cli for the full project.

AI is also changing rapidly.
The AI I first experimented with two years ago is not the AI of a year ago.
The AI I used for this case study a month or so ago is not even the AI of today.
Since the initial project, Claude has introduced memory that is better able to pick up past threads.

I'd previously learned that AI's are good at straight forward programming tasks but not very good at architectural problems.
Ask it how to write an array comparison function, or the scaffolding for a new drush command, or how to alter a link in Drupal 10 and it will save you time.
Ask it to write a new module to rule the world and you're in for a long conversation that will be disappointing.

This task was somewhere in the middle (well closer to a simple task than world domination), but still a bit more complicated that a simple sort...

## The Problem

I have a client with 8 sites and lots of questions.
Initially they had simple watchdog logging, then watchdog logging to a local file.
But these were 8 separate servers I wanted to consolidate the Drupal and Web Server (Fastly) logs to one site. We ended up with Papertrail because it was cheap. And while Papertrail worked fine for storage, searching in the UI was difficult. So pretty quickly I found myself creating scripts (shell scripts) to search. Then over a period of years, as things happened, and I needed to investigate more things, I added options, I wrote more scripts, and I created a monitoring cron job that wrote results to slack when things happened. Eventually we had about 20 shell scripts, many of them with copy-pasted code. Nothing here was planned. It was now unmaintainable. They were production tools that I used to analyze web traffic patterns, diagnose performance issues, and identify security threats.

Then SolarWinds bought Papertrail and broke everything. We went without proper logs and monitoring for months. I had to migrate from the Papertrail API to the SolarWinds API, which I finally did. And it was while doing this that I decided to rewrite the entire project.

## The AI problem

Unlike code, which always does exactly what you tell it to do, even if what you tell it to do is not what you really want it to do, an AI does not always follow directions.
Large Language Model AI's are good a predicting the next word or next thing, and as such, they often think they know what you want and do that instead of what you ask it to do.
It's frustrating.
"Claude please follow Drupal coding standards."
"Claude please strip blank spaces from the end of lines ```s/ *$//g```."
"Claude please ask me questions before implementing anything."
Repeatedly.

## The Documentation Experiment

We started migrating one command.
This effort took a lot of back and forth and as it took a lot of back and forth, I quickly ran into the AI token memory problem.
I was using a Claude subscription that costs $100 / month, which gave me a little more headroom, but still it had limited memory.
After some time, Claude would just say it had hit it's memory limit (which I appreciated because ChatGPT would just start hallucinating at this point)
and that I'd have to continue in a new thread.
When I started a new thread, Claude would then repeat all the bad things I'd already told it not to do, and we'd spend more time
fixing the behavior.

Finally it occurred to me to have the AI keep a new document to transcend each new thread.
Every time the AI did something I didn't want, I'd ask it to document that in this new document.
At first we called it README1st or something like that.
And it ignored that sometimes.
I asked Claude what we should name it so that it would know to read it and that's how we ended up with `CLAUDE-MUST-READ-FIRST.md`.
I tried to give Claude agency over this document, that it could update it without asking me, and I'm not quite sure that this really worked.
But we did fall into a pattern that on each new thread I was able to mostly continue where I left off without having to repeat my requests.

This became the most successful part of the entire project.

The file evolved into a living record of what actually worked and what didn't:

1. Ask permission before implementing solutions
2. Read reference files - check uploaded bash scripts for exact implementation patterns  
3. Provide download links immediately after every file change
4. Never mark tasks as complete

These weren't abstract principles. They were specific responses to specific recurring problems.

For example, "Never mark tasks as complete" emerged because AI kept declaring things done when they weren't. "Provide download links immediately" came from AI discussing changes without actually letting me see the files. Each rule had a backstory of failure.

The behavioral documentation worked remarkably well - for a while. After about half a dozen conversation threads, even the documentation itself became meandering. The AI needed my help to reorganize its own guidance document. We'd hit some kind of complexity ceiling.

But the investment paid off. Once the documentation matured, I could start new conversation threads by just uploading the project files. The AI would orient itself from the documentation without extensive re-prompting.

## What AI Actually Does Well (And Doesn't)

### A Junior Assistant

AI is a poor Junior Assistant.

The AI didn't test anything. It wrote code that it thought should work.
I'd do a code review or test it, and discover a problem, and it would be quick to say "Oh yeah I see the problem..." something that it shouldn't have done in the first place.
This wasn't a Drupal project, but I've discovered on other AI collaboration, that it often makes up hook names that it thinks should exist and provides this as a solution.

It doesn't do what you ask. It makes assumptions. It wants to mark things as done.
It tries to give the right answer and will start a long period of work before we agreed on the implementation.
Many times I could tell in 10 seconds it was going down the wrong road, but I had to wait 2 minutes for it to complete it's process (because it couldn't be interrupted) to tell it to do something differently (and document again that it should ask before doing anything).

I admit that I see my own behavior in the AI's behavior.

### AI Makes Assumptions Instead of Following Specifications

Even while using the AI to help write this blog post, and after repeatedly asking it to only use text from the CASE STUDY,
it added flourishes that came from elsewhere, weren't my ideas, and didn't sound like me.

Here's an example. The AI wrote this next section, and it's completely wrong.

```
The most consistent pattern: AI delivers logically correct code that fails on basic usage scenarios.
The code compiled.
The logic was sound.
```

AI often delivered broken code, maybe it was logically correct, but it certainly wasn't always syntactically correct.
This is a PHP project, it's not compiled.

## The Collaboration Breakdown and Reset

After successfully implementing several commands with established patterns,
I noticed the AI violating it's own rules.
I looked at the `CLAUDE-MUST-READ-FIRST.md` and realized that it had over 40 bulleted rules, they weren't organized well, it was hard to follow.
I asked Claude if it even read the document and it admitted that it hadn't.
It admitted that it's own documentation was hard to follow.

The collaboration had become counterproductive.
I almost stopped here.
But instead I chose to pause development and work on documentation.
We documented this as the phase we were in.
I'd start a new thread and check that it understood the documentation and over the next couple of threads that's all we did.
Finally

It worked.

With clean foundations, the AI came back focused. It asked clarifying questions instead of making assumptions. It followed the behavioral guidelines. The reset strategy successfully restored productive collaboration.

This proved something important: AI collaboration breakdowns are recoverable. But recovery requires strategic intervention, not just persistence.

## Patterns Worth Keeping

Some collaboration patterns emerged that I'd use again:

**Living documentation with permission to self-modify.** Let AI maintain behavioral documentation for incremental changes. But intervene for major reorganization when complexity accumulates.

**One thread per discrete task.** After documentation matured, starting fresh threads for each task avoided memory limits and context drift. The documentation investment made this viable.

**Permission-based workflow.** AI asks before implementing. This prevents assumption-making and scope creep. Every time I relaxed this rule, problems emerged.

**Strategic pauses and resets.** When collaboration becomes counterproductive, step back. Clean up foundations manually. Then resume with clearer constraints.

**Reality-check completion claims.** Test basic usage scenarios. The "should obviously work" cases are the ones most likely to fail.

**Treat AI delivery as first draft.** It will look complete. It won't be complete. Plan for validation and iteration.

## What I would try the next time 

**Test driven development.** I didn't like being the "tester" for my "Junior Assistant". For the next big project I'll ask it to write tests before development and see if it can run it's own tests before asking me to.

## What This Actually Means for AI-Assisted Development

AI implements what you specify. You have to do the specifying.

AI generates code quickly. You have to validate it actually works.

AI optimizes for logical correctness. You have to test for usage correctness.

AI excels at implementation. You have to handle architecture.

AI makes assumptions. You have to provide specifications.

AI claims completion. You have to verify completion.

The partnership can be productive. But only with constant human oversight and willingness to intervene when patterns break down.

I spent several days on this migration. The collaboration broke down once. I reset it with clean foundations. It worked again. The code is in production.

Was it worth it? Yes. The final system is better than what I would have built alone in the same timeframe.

Would I do it differently next time? Absolutely. I now know to watch for specific failure patterns and intervene earlier.
I need to copy the `CLAUDE-MUST-READ-FIRST.md` and generalize it, and upload it with every project I work on.

## The Meta-Learning

The most important thing I learned isn't about AI.
It's about collaboration patterns.
I need to look in the mirror.
AI has learned from us.
And many of the gripes I have against the AI I see in myself.
It makes me think twice before I'm quick to call something complete.
