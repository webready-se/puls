---
name: release
description: >
  Prepare and manage releases. Suggest a release when an epic or a coherent
  feature set is done, or right away for security, data-loss or broken
  install/upgrade fixes. Do not suggest one per merged PR. Also use when the
  user mentions 'release', 'tagga', 'version', 'ship it'.
---

# Release Management

Puls uses semantic versioning and GitHub Releases with automated builds.

## Two audiences, two paths

- **The production installs and their customers run on `main`.** Forge deploys
  every merge to main automatically. A customer waiting for a change gets it when
  the PR is merged; tell them it is live, no release needed.
- **Tagged releases are for self-hosters** (zip download, Docker). They should
  get few, coherent upgrades, not one per PR.

## When to release

**Minor release** when:
- An epic or a coherent feature set is complete, or
- `Unreleased` holds user-visible changes that have waited a week or two.

**Patch release immediately** only when a self-hoster would otherwise be hurt:
- Security fixes
- Data loss or corruption
- A broken fresh install or upgrade (migrations)
- Crashes in normal use

**Never a release of its own:** tests, CI, docs, screenshots, internal
refactors. They ship with the next release.

Several small releases in one day is a smell: batch them. (On 2026-10-09 nine
tags shipped in a day; v1.13 to v1.18 could have been one or two releases, and
only v1.19.1, a broken fresh install, needed to go out on its own.)

## CHANGELOG rule

`CHANGELOG.md` always has **exactly one** `## [Unreleased]` section. Every PR
adds its lines there, under Added / Changed / Fixed / Security. If a PR would
create a second Unreleased heading, merge into the existing one instead.

Credit people outside the team on their line: `Contributed by @handle (#81).`
for a pull request, `Reported by @handle (#80).` for an issue. The release notes
are built from the changelog, so the credit shows there and GitHub notifies them.
Leave it out for anyone who asks not to be credited (security reporters often do).

## How to prepare a release

1. **Determine version bump:**
   - Check the last tag: `git tag -l --sort=-v:refname | head -1`
   - Review commits since last tag: `git log $(git describe --tags --abbrev=0)..HEAD --oneline`
   - Major: breaking changes (rare for Puls)
   - Minor: new features (epic completed, new endpoints, dashboard features)
   - Patch: bug fixes, security fixes, small improvements

2. **Update CHANGELOG.md:**
   - Check there is exactly one `## [Unreleased]` heading
     (`grep -c '^## \[Unreleased\]' CHANGELOG.md` must print 1); merge duplicates first
   - Rename it to `## [x.y.z] — YYYY-MM-DD`
   - Bump `PULS_VERSION` in `public/index.php` to the same version (it shows in the dashboard footer; `tests/Unit/VersionTest.php` fails until the two match)
   - Group changes under: Added, Changed, Fixed, Security (use only the relevant ones)
   - Add the version link at the bottom of the file
   - Keep descriptions concise — one line per change

3. **Test the build:**
   - Run `./vendor/bin/pest` to verify tests pass
   - Run `node scripts/smoke.mjs` to verify the dashboard
   - Run `bash scripts/build-release.sh x.y.z` to verify the zip builds correctly

4. **Ask the user for confirmation before tagging**

5. **Create the tag and push:**
   ```bash
   git tag -a vx.y.z -m "Release vx.y.z"
   git push origin vx.y.z
   ```
   The GitHub Action will automatically build the zip and create the release.

## Files

- `CHANGELOG.md` — human-written changelog
- `scripts/build-release.sh` — builds the release zip with only runtime files
- `.github/workflows/release.yml` — CI: runs tests, builds zip, publishes GitHub Release
