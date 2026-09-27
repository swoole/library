---
name: swoole-library-upstream-pr
description: Use when asked to merge, sync, or ship the current swoole/library code into the Swoole extension, i.e. to rebuild `ext-src/php_swoole_library.h` in a local swoole-src fork and open a pull request against swoole/swoole-src (e.g. "prepare a PR to update the embedded library in Swoole 6.2", "sync the library to swoole-src master").
argument-hint: [path-to-swoole-src] [target-branch]
disable-model-invocation: true
allowed-tools: Bash(git:*), Bash(env -u GH_TOKEN gh:*), Bash(composer:*), Bash(php:*), Bash(mktemp:*), Bash(mkdir:*), Bash(cp:*), Bash(ln:*), Bash(rm:*), Bash(grep:*), Bash(head:*), Bash(awk:*), Bash(sed:*), Bash(test:*), Read, Write
---

# Swoole Library Upstream PR

## Overview

The Swoole extension embeds a snapshot of this library as one generated C header, `ext-src/php_swoole_library.h`, which records the `swoole/library` commit it was built from on a line of the form `/* $Id: <40-hex commit> */`. This skill rebuilds that header from the current commit of this repository inside a local fork of [swoole/swoole-src](https://github.com/swoole/swoole-src), commits it on a new branch, and after confirmation pushes the branch to the fork and opens a pull request against `swoole/swoole-src`.

The header is built the same way the `Build Swoole` step of `.github/workflows/build-swoole.yml` builds it: install the tooling of `tools/` with Composer, then run `tools/build-library.php`. The extension itself is not compiled here; the `Build Swoole` workflow of this repository already does that for every pushed commit, and Step 1 looks up its result.

## Input

`$ARGUMENTS` holds two values, in this order:

1. `$SRC` — path to a local Git checkout of a fork of `swoole/swoole-src`. Turn it into an absolute path.
2. `$BASE` — name of the branch of `swoole/swoole-src` the change is to be merged into (e.g. `master`, `6.2`).

If either is missing, report `ERROR: usage: /swoole-library-upstream-pr <path-to-swoole-src> <target-branch>` and stop.

`$LIB` is the root of this repository (`git rev-parse --show-toplevel`).

## Failure handling

Follow the steps below in order. If any step fails, stop immediately — do not continue to later steps or attempt a workaround — and tell the user clearly, prefixing the message with `ERROR:`, explaining which step failed and why. Before stopping, remove the scratch directory of Step 5 if it exists, and say in which state `$SRC` was left (which branch is checked out, whether the new branch exists, whether anything was pushed). Never delete a branch or discard changes in `$SRC` to clean up.

## GitHub authentication

The `swoole` organization rejects fine-grained personal access tokens with a lifetime greater than 366 days. If the `GH_TOKEN` environment variable is set to such a token, every `gh` call against `swoole/*` fails with a policy error. Run all `gh` commands in this workflow as `env -u GH_TOKEN gh ...` so `gh` falls back to the keyring OAuth credential, which the organization accepts.

## Step 1 — Check the library

```bash
git -C "$LIB" status --porcelain --untracked-files=no   # must print nothing
NEW=$(git -C "$LIB" rev-parse HEAD)
LIB_BRANCH=$(git -C "$LIB" branch --show-current)
git -C "$LIB" fetch origin
git -C "$LIB" branch -r --contains "$NEW" | grep -E '^ *origin/'
```

- Tracked files with uncommitted changes: report `ERROR: the library has uncommitted changes.` and stop. The header must correspond to a commit exactly.
- No branch of `origin` (`swoole/library`) contains `$NEW`: report `ERROR: commit $NEW is not on swoole/library yet; push it first.` and stop. The header names this commit, and the `swoole-library-release` skill later tags it, so it has to exist in the official repository. Do not push it yourself.

Release series are paired by branch: library `master` goes to swoole-src `master`, library `X.Y.x` goes to swoole-src `X.Y` (e.g. `6.2.x` → `6.2`). If `$LIB_BRANCH` and `$BASE` are not such a pair, do not stop on that alone — Step 3 decides — but mention the mismatch in the confirmation of Step 7.

Look up the result of the `Build Swoole` workflow for the commit. It is the check that the library compiles into the extension and that the unit tests pass against the embedded copy:

```bash
env -u GH_TOKEN gh run list -R swoole/library --workflow build-swoole.yml --commit "$NEW" \
  --json status,conclusion,url,event
```

Remember the outcome (`success`, failed, still running, or no run found) for Steps 7 and 9. Anything other than `success` is a warning, not an error.

## Step 2 — Check the Swoole source

```bash
test "$(git -C "$SRC" rev-parse --show-toplevel)" = "$SRC"        # a Git checkout, and $SRC is its root
test -f "$SRC/tools/build-library.php" && test -f "$SRC/ext-src/php_swoole_library.h"
git -C "$SRC" status --porcelain --untracked-files=no             # must print nothing
ORIGINAL=$(git -C "$SRC" branch --show-current)                   # empty on a detached HEAD: use the commit hash
git -C "$SRC" remote -v
```

Compare the real paths on both sides of the first test (`cd "$SRC" && pwd -P`), so a symbolic link in the path does not fail it.

- Not a Git checkout, or the two files are missing: report `ERROR: $SRC is not a Git checkout of swoole-src.` and stop.
- Tracked files with uncommitted changes: report `ERROR: $SRC has uncommitted changes.` and stop. Do not stash them.

From the remotes, determine the fork:

- `$FORK` is the remote whose URL points to a `swoole-src` repository on GitHub owned by someone other than `swoole`; `$FORK_OWNER` is that owner (`git@github.com:deminy/swoole-src.git` → `deminy`). Prefer `origin` when several qualify.
- If no remote qualifies (e.g. the checkout is a clone of `swoole/swoole-src` itself), report `ERROR: $SRC has no remote pointing to a fork of swoole/swoole-src.` and stop. Never push a branch to `swoole/swoole-src`.

## Step 3 — Fetch the target branch and compare

Base the work on the branch as it is in the official repository right now, not on the fork's or the local copy of it, which may be behind:

```bash
git -C "$SRC" fetch https://github.com/swoole/swoole-src.git "refs/heads/$BASE"
BASE_SHA=$(git -C "$SRC" rev-parse FETCH_HEAD)
OLD=$(git -C "$SRC" show "$BASE_SHA:ext-src/php_swoole_library.h" | grep -oE '\$Id: [0-9a-f]{40}' | head -1 | awk '{print $2}')
```

- The fetch fails: report `ERROR: branch $BASE not found in swoole/swoole-src.` and stop.
- `$OLD` is not a 40-character hex string: report `ERROR: could not read the library commit from php_swoole_library.h of $BASE.` and stop.
- `$OLD` equals `$NEW`: report that `$BASE` already embeds this commit, and stop. This is not an error.

Then check that the update only adds changes:

```bash
git -C "$LIB" cat-file -t "$OLD"                       # must print "commit"
git -C "$LIB" merge-base --is-ancestor "$OLD" "$NEW"   # exit code 0: $NEW contains $OLD
git -C "$LIB" log --oneline "$OLD..$NEW"
git -C "$LIB" diff --stat "$OLD" "$NEW" -- src
```

If `$OLD` is not an ancestor of `$NEW`, the pull request would take away library changes that `$BASE` has today, which usually means the wrong pair of branches (e.g. library `6.2.x` into swoole-src `master`). Report `ERROR: the library commit embedded in $BASE ($OLD) is not part of $NEW.` together with the output of `git -C "$LIB" log --oneline "$NEW..$OLD"`, and stop.

If the diff of `src` is empty, the header would change in its `$Id` line only. Say so in the confirmation of Step 7; the user decides whether such a pull request is worth opening.

## Step 4 — Create the branch

```bash
SHORT=$(git -C "$LIB" rev-parse --short=7 "$NEW")
BRANCH="update-library-$SHORT"
git -C "$SRC" rev-parse -q --verify "refs/heads/$BRANCH"      # local: empty output means no such branch
git -C "$SRC" ls-remote "$FORK" "refs/heads/$BRANCH"          # fork: empty output means no such branch
git -C "$SRC" switch -c "$BRANCH" "$BASE_SHA"
```

If the branch exists already, locally or on the fork, report `ERROR: branch $BRANCH already exists.` and stop. It is what an earlier run left behind; the user decides what to do with it. Do not reuse, move or delete it.

## Step 5 — Rebuild the header

Install the build tooling, as the workflow does. `tools/vendor` is ignored by Git in swoole-src, so this leaves the checkout clean:

```bash
composer install -d "$SRC/tools" -n -q --no-progress
```

`tools/build-library.php` reads the library from the folder `library` next to `tools`, and writes the header into `ext-src` next to it. `$SRC/library` is ignored by Git in swoole-src and may hold a clone of the user's own in any state, so leave it alone. Build in a scratch directory that has the same layout instead, from a fresh clone of the library at `$NEW`. A clone holds the committed files only, which is what the build needs: the builder refuses to run when it finds a file under `src` that the manifest does not list.

```bash
TMP=$(mktemp -d)
git clone -q --no-hardlinks "$LIB" "$TMP/library"
git -C "$TMP/library" checkout -q --detach "$NEW"
mkdir "$TMP/tools" "$TMP/ext-src"
cp "$SRC/tools/build-library.php" "$TMP/tools/build-library.php"
ln -s "$SRC/tools/vendor" "$TMP/tools/vendor"
php "$TMP/tools/build-library.php"
```

Run the script without its `dev` argument: that argument turns off the check for uncommitted changes. The run ends with `Generated swoole php library successfully!` and exit code 0; it prints the reason and exits with 255 otherwise.

Check the header before it goes anywhere:

```bash
grep -c 'Generated by build-library.php' "$TMP/ext-src/php_swoole_library.h"            # 1
grep -oE '\$Id: [0-9a-f]{40}' "$TMP/ext-src/php_swoole_library.h" | awk '{print $2}'   # equals $NEW
grep -nE "\?\?[=/'()!<>-]" "$TMP/ext-src/php_swoole_library.h"                          # must print nothing
```

The last command looks for C trigraph sequences (see `CLAUDE.md`): the compiler rewrites them inside the string literals of the header, and the extension then fails at startup. If it prints anything, report `ERROR: the library contains a C trigraph sequence.` with the lines found, and stop; the fix belongs in the library, not in the header.

Then put the header in place, and remove the scratch directory:

```bash
cp "$TMP/ext-src/php_swoole_library.h" "$SRC/ext-src/php_swoole_library.h"
rm -rf "$TMP"
git -C "$SRC" status --porcelain
git -C "$SRC" diff --stat
```

`ext-src/php_swoole_library.h` must be the only file reported. If anything else changed, report it as an error and stop. Never edit the header by hand.

## Step 6 — Commit

```bash
git -C "$SRC" add ext-src/php_swoole_library.h
git -C "$SRC" commit -m "Update the embedded library to swoole/library $LIB_BRANCH ($SHORT)"
git -C "$SRC" show --stat --format='%H %s' HEAD
```

The commit holds that one file and nothing else. It is local to `$SRC`; nothing is pushed in this step.

## Step 7 — Confirm with the user

Everything up to here is local and can be undone. The remaining steps publish a branch and open a pull request in a repository other people work in, so ask first, unless the user said when invoking the skill to go ahead without asking. Show:

- the library commit going in (`$OLD` → `$NEW`, branch `$LIB_BRANCH`) and the target (`swoole/swoole-src`, branch `$BASE`), with the mismatch warning of Step 1 if there is one;
- the commits of `$OLD..$NEW`, and the commit created in `$SRC` in Step 6 with the size of its diff;
- the result of the `Build Swoole` workflow for `$NEW` from Step 1;
- the branch name, the fork it is pushed to, and the title and body of the pull request as composed in Step 9.

If the user declines, stop there and leave the branch and its commit in `$SRC` as they are, with the branch still checked out, and say so.

## Step 8 — Push

```bash
git -C "$SRC" push -u "$FORK" "$BRANCH"
```

## Step 9 — Open the pull request

Title: the subject line of the commit.

Body, written with the Write tool to a scratch file outside both repositories:

```text
Rebuild `ext-src/php_swoole_library.h` via `tools/build-library.php` from branch `$LIB_BRANCH` of [swoole/library](https://github.com/swoole/library) (swoole/library@<first 7 of $OLD> → swoole/library@$SHORT).

## Library changes included

<the changes>

## Testing

<what was tested>
```

- **Library changes included**: the changes of `$OLD..$NEW` that matter to a user of the extension, grouped under `**Added:**`, `**Changed:**`, `**Deprecated:**`, `**Removed:**`, `**Fixed:**`, `**Security:**` — only the groups that have entries. Take the bullets from the sections of `CHANGELOG.md` that are not released yet (headed `## X.Y.Z (unreleased)` or `## Unreleased`), and check each against `git log "$OLD..$NEW"`: leave out what `$OLD` contains already, and add what the log shows but the changelog lacks. For a pull request to a release branch such as `6.2`, leave out the sections of later series. Follow the content rules of `CLAUDE.md` ("Release notes and CHANGELOG.md"); write references in full (`swoole/library#185`), since a bare `#185` would link to swoole-src.
- **Testing**: only what was actually done. Normally that is one line, naming the `Build Swoole` run of Step 1 with its URL and result; when there is no successful run, say that instead. Add local compilation or tests only if they were run in this session.

Make sure no open pull request does the same already, then create it:

```bash
env -u GH_TOKEN gh pr list -R swoole/swoole-src --state open --base "$BASE" --search "php_swoole_library.h in:body" \
  --json number,title,author,url
env -u GH_TOKEN gh pr create -R swoole/swoole-src --base "$BASE" --head "$FORK_OWNER:$BRANCH" \
  --title "Update the embedded library to swoole/library $LIB_BRANCH ($SHORT)" --body-file <file>
```

If the list shows an open pull request that updates the embedded library, do not create a second one silently: tell the user about it and ask how to go on. The branch pushed in Step 8 stays.

## Step 10 — Restore and report

Return the checkout to where it was:

```bash
git -C "$SRC" switch "$ORIGINAL"
```

Report a short summary: the library commit embedded before and after, the target branch, the new branch and the fork it was pushed to, the commit created in `$SRC`, the URL of the pull request, and the result of the `Build Swoole` workflow for the library commit.
