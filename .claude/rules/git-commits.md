# Git & Commits

## Commit format

```
type(scope): short description in imperative form

Optional body with context
```

Types: feat, fix, refactor, docs, style, chore, test

## Rules

- Never bundle unrelated changes in one commit
- One commit per logical change — if in doubt, split it
- Always stage files explicitly — never git add . or git add -A
- **Always ask the user before committing or pushing**
- Never push directly to main during development — use feature branches
- Tests must pass before pushing (pre-push hook enforces this)
- Delete feature branches (local + remote) after merge — use `gh api` for remote to avoid pre-push hook on delete
