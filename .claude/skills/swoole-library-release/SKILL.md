---
name: swoole-library-release
description: Use when asked to tag, publish, or release Swoole Library for a specific Swoole extension version (e.g. "release swoole/library for v6.2.3", "publish the library release for 6.1.9", "tag swoole-library for the v6.0.1 Swoole release").
argument-hint: [version]
disable-model-invocation: true
allowed-tools: Bash(git:*), Bash(curl:*), Bash(env -u GH_TOKEN gh:*), Bash(grep:*), Bash(head:*), Bash(awk:*), Bash(sort:*), Read, Write
---

# Swoole Library Release

## Overview

Publishes a `swoole/library` GitHub release (repo `swoole/library`, git remote `origin`) that corresponds to an already-published Swoole extension release. The Swoole extension embeds a snapshot of this library, and every Swoole release records the exact `swoole/library` commit it embeds inside `ext-src/php_swoole_library.h`. This skill tags that commit and publishes a matching GitHub release with a changelog relative to the previous library release.

## Input

The input (`$ARGUMENTS`) is a Swoole release version. Normalize it to `vX.Y.Z` (add the `v` prefix if missing). Call it `$VERSION` throughout. The library tag name is always identical to the Swoole release tag name.

## Failure handling

Follow the steps below in order. If any step fails, stop immediately — do not continue to later steps or attempt a workaround — and tell the user clearly, prefixing the message with `ERROR:`, explaining which step failed and why.

## GitHub authentication

The `swoole` organization rejects fine-grained personal access tokens with a lifetime greater than 366 days. If the `GH_TOKEN` environment variable is set to such a token, every `gh` call against `swoole/*` fails with a policy error. Run all `gh` commands in this workflow as `env -u GH_TOKEN gh ...` so `gh` falls back to the keyring OAuth credential, which the organization accepts.

## Step 1 — Prerequisite: the Swoole release must exist

```bash
git ls-remote https://github.com/swoole/swoole-src.git "refs/tags/$VERSION"
```

If this returns no output, the Swoole release does not exist. Report `ERROR: Swoole release $VERSION not found in swoole/swoole-src.` and stop.

A tag can exist before the GitHub release is published, and the release message links to the Swoole release page. Also check:

```bash
env -u GH_TOKEN gh release view "$VERSION" -R swoole/swoole-src --json tagName
```

If this fails, the tag exists but the release is not published yet. Do not stop — warn the user and continue.

## Step 2 — Get the swoole/library commit hash used by that Swoole release

The header comment of `ext-src/php_swoole_library.h` in the Swoole source records the hash on a line of the form `/* $Id: 26e2e98b4bdaf3da1497910c21c44cd96f7a8dc8 */` (near the top of the file):

```bash
HASH=$(curl -sfL "https://raw.githubusercontent.com/swoole/swoole-src/$VERSION/ext-src/php_swoole_library.h" | grep -oE '\$Id: [0-9a-f]{40}' | head -1 | awk '{print $2}')
```

If `$HASH` is not a 40-character hex string, report `ERROR: could not extract the swoole/library commit hash from php_swoole_library.h of Swoole $VERSION.` and stop.

## Step 3 — Verify the hash exists in this repository

```bash
git fetch origin --tags --force
git cat-file -t "$HASH"
```

`--force` matters: a plain `git fetch --tags` silently leaves an existing local tag untouched even when `origin` has moved it, which would make Step 4 compare against a stale local tag.

The object type must be `commit`. If not, report `ERROR: commit $HASH is not present in the swoole/library repository.` and stop.

## Step 4 — Create and push the tag

Check the remote and the local tag separately — a previous interrupted run can leave a local tag that was never pushed.

```bash
git ls-remote origin "refs/tags/$VERSION" "refs/tags/$VERSION^{}"   # remote: empty output means no tag
git rev-parse -q --verify "refs/tags/$VERSION^{commit}"             # local: empty output means no tag
```

For an annotated tag `ls-remote` prints two lines; the `^{}` (peeled) line holds the commit. For a lightweight tag there is one line and it is the commit.

- **Remote tag exists, points to `$HASH`**: skip tagging and continue (idempotent re-run).
- **Remote tag exists, points elsewhere**: report `ERROR: tag $VERSION already exists on origin and points to a different commit.` and stop.
- **No remote tag, local tag exists at `$HASH`**: push it, then continue.

  ```bash
  git push origin "$VERSION"
  ```

- **No remote tag, local tag exists at a different commit**: report `ERROR: a local tag $VERSION already exists and points to a different commit; delete or correct it before re-running.` and stop. Do not move or delete it automatically.
- **Neither exists**:

  ```bash
  git tag "$VERSION" "$HASH"
  git push origin "$VERSION"
  ```

## Step 5 — Skip if the release already exists

If `env -u GH_TOKEN gh release view "$VERSION" -R swoole/library` succeeds, report `ERROR: release $VERSION already exists.` and stop (the tag work above is still valid and does not need to be undone).

## Step 6 — Find the previous release to compare against

Compare against GitHub **releases**, not bare tags (some tags have no release):

```bash
env -u GH_TOKEN gh release list -R swoole/library --limit 200 --json tagName,isPrerelease \
  --jq '.[] | select(.isPrerelease == false) | .tagName' | grep -E '^v[0-9]+\.[0-9]+\.[0-9]+$'
```

From that list, pick `$PREV` as follows (use `sort -V` for version ordering; only consider versions strictly lower than `$VERSION`):

1. The highest release in the **same series** (same `X.Y` as `$VERSION`), if any.
2. Otherwise (first release of a new series), the highest release overall — i.e. the last release of the previous series.

If no previous release exists at all, note that in the release message instead of a comparison.

## Step 7 — Determine the differences

```bash
git log --oneline "$PREV".."$VERSION"
git diff --stat "$PREV" "$VERSION"
```

There is "no difference" when the diff is empty (both tags may even point to the same commit).

## Step 8 — Compose the release message

Always start with exactly this line (adjusting the version), followed by a blank line:

```text
Built-in PHP library included in [Swoole $VERSION](https://github.com/swoole/swoole-src/releases/tag/$VERSION).
```

Then:

- **If there are no differences** from `$PREV`:

  ```text
  This release is the same as Swoole Library [$PREV](https://github.com/swoole/library/releases/tag/$PREV).
  ```

- **If there are differences**: write a short changelog based on the commits and diff — a flat bullet list of user-facing changes in plain language (fixes, features, refactorings), no category headers. Reference PR numbers like `#189` when a commit subject contains them. Skip merge commits and pure CI/style noise unless they are the only changes. Lead the bullet list with a `Changes since [$PREV](https://github.com/swoole/library/releases/tag/$PREV):` line followed by a blank line, as v6.1.0 and v6.2.2 do.

## Step 9 — Publish the release

Write the message with the Write tool to a scratch file outside the repository (never inside the working tree), then:

```bash
env -u GH_TOKEN gh release create "$VERSION" -R swoole/library --verify-tag --latest=false --title "$VERSION" --notes-file <file>
```

`--verify-tag` requires the tag to be on `origin` already, which Step 4 guarantees.

Hard rules:

- NEVER pass `--prerelease`.
- ALWAYS pass `--latest=false`. The release must never become the "latest" release, because these releases track Swoole versions, not the library's own timeline.

**Known GitHub limitation:** `--latest=false` only excludes this release from being *chosen* as latest at creation time — it does not guarantee another release stays latest. If no release in the repo has ever been explicitly pinned latest, GitHub can still fall back to showing the most recently published release as "Latest" regardless of this flag. After publishing, verify:

```bash
env -u GH_TOKEN gh api repos/swoole/library/releases/latest --jq .tag_name
```

If this prints `$VERSION`, do not try to silently correct it (`gh release edit --latest=false` mutates a shared, already-published resource) — call it out in the final report so the user can decide.

## Step 10 — Report

Report a short summary: the tag created (or reused), the commit hash it points to, the previous release used for comparison, the URL of the published release, and whether the latest-release check in Step 9 came back clean.
