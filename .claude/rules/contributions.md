# Contributions

Puls takes pull requests from outside, and many are written with Claude Code, so this file is read on both sides: by whoever writes a change and by whoever reviews it.

## Writing a change

- A new feature or a visible change to the dashboard starts with an issue or a draft pull request, so the direction is agreed before the work (CONTRIBUTING.md). Bug fixes and docs can go straight to a pull request
- The dashboard is calm by default (Design in CLAUDE.md): a new card or panel usually opens on demand, not always on
- A pull request carries its tests, a step in `scripts/smoke.mjs` for a dashboard change, the docs it affects, and a line in `CHANGELOG.md` under Unreleased
- Fix what the change itself needs; open an issue for unrelated problems you notice on the way

## Reviewing a pull request

- Run it, don't only read it: check out the branch and open the dashboard on demo data (`scripts/seed-demo.php`). Green CI shows that the code does what the PR says, not that it is what Puls wants: #104 had tidy code and green CI, and whether its card should always be on only became clear on screen
- Review against the product as well as for bugs
- Ask for everything in one round, say why, and mark what is optional. Thank first and say what is good
- Ask the contributor for what the PR itself brings in: its bugs, regressions and product direction. Problems that were there before are ours: say so, and don't make the PR wait for them
- Don't merge and fix afterwards when the fix changes what users see, since merging to main deploys puls.wrlabs.se
- Credit outside contributors in `CHANGELOG.md`: `Contributed by @handle (#n).`, or `Reported by` for an issue
