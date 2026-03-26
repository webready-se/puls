---
name: release
description: >
  Prepare and manage releases. Use PROACTIVELY to suggest a new release when
  an epic is completed or significant fixes have landed since the last tag.
  Also use when the user mentions 'release', 'tagga', 'version', 'ship it'.
---

# Release Management

Puls uses semantic versioning and GitHub Releases with automated builds.

## When to suggest a release

Proactively suggest a new release when:
- An epic is completed
- Multiple bug fixes have landed since the last tag
- Security fixes have been merged
- The user says "release", "tagga", "version", or "ship it"

## How to prepare a release

1. **Determine version bump:**
   - Check the last tag: `git tag -l --sort=-v:refname | head -1`
   - Review commits since last tag: `git log $(git describe --tags --abbrev=0)..HEAD --oneline`
   - Major: breaking changes (rare for Puls)
   - Minor: new features (epic completed, new endpoints, dashboard features)
   - Patch: bug fixes, security fixes, small improvements

2. **Update CHANGELOG.md:**
   - Add a new `## [x.y.z] — YYYY-MM-DD` section above the previous version
   - Group changes under: Added, Changed, Fixed, Security (use only the relevant ones)
   - Add the version link at the bottom of the file
   - Keep descriptions concise — one line per change

3. **Test the build:**
   - Run `./vendor/bin/pest` to verify tests pass
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
