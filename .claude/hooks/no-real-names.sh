#!/bin/bash
# PreToolUse hook: stop a gh command that would send a real name, domain, IP
# or URL to GitHub: a PR, issue, comment, review, merge or release text, a
# repository description, or anything sent with gh api; inline or in a file
# (--body-file, -F, --notes-file, --input, -F field=@file). Commits, branch
# and tag names are covered by the git hooks (pre-commit, commit-msg,
# pre-push). See .claude/rules/no-real-names.md.

CMD=$(jq -r '.tool_input.command // empty' 2>/dev/null)
# Only gh run as a command (at a line start or after ; & | ( or $(), not gh
# mentioned in a commit message or other text
if ! printf '%s' "$CMD" | grep -qE '(^|[;&|(]|\$\()[[:space:]]*gh ((pr|issue) (create|edit|comment|review|merge|close)|release (create|edit)|repo (create|edit)|gist (create|edit)|api)'; then
  exit 0
fi

# What reaches GitHub is the text, not local paths (which hold the user name):
# leave out absolute, home and $VAR/ paths, and add the files they point to
TEXT=$(printf '%s' "$CMD" | sed -E 's#(^|[[:space:]=@])(/|~/|\$[A-Za-z_]+/)[^[:space:];&|"'"'"']*#\1#g')
FILES=$(printf '%s' "$CMD" | grep -oE -- '(--body-file|--notes-file|--input)[= ]+[^ ;&|]+|-F +[^ ;&|=]+( |$)|=@[^ ;&|]+' \
  | sed -E 's/^(--body-file|--notes-file|--input)[= ]+//; s/^-F +//; s/^=@//; s/ $//; s/^["'\'']//; s/["'\'']$//')
for FILE in $FILES; do
  [ "$FILE" = "-" ] && continue  # stdin: a heredoc is in the command text, checked above
  if [ ! -f "$FILE" ]; then
    echo "Stopped: cannot read $FILE to check it for real names. Give it as a path without shell variables." >&2
    exit 2
  fi
  TEXT="$TEXT
$(cat "$FILE")"
done

FOUND=$(printf '%s\n' "$TEXT" | PULS_ROOT="$CLAUDE_PROJECT_DIR" "$CLAUDE_PROJECT_DIR/scripts/hooks/real-names")
if [ -n "$FOUND" ]; then
  echo "Stopped: the text for GitHub contains names from .private/real-names.txt. Describe the case with example names instead (see .claude/rules/no-real-names.md):" >&2
  echo "$FOUND" >&2
  exit 2
fi
exit 0
