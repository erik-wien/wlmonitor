---
id: TASK-LOW.2
title: 'Verify APP_BUILD=40 is manually bumped, not drifting'
status: Done
assignee: []
created_date: '2026-04-21 05:44'
updated_date: '2026-04-21 11:51'
labels: []
dependencies: []
parent_task_id: TASK-LOW
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Audit 2026-04-20: APP_BUILD=40 in wlmonitor stands out against APP_BUILD=1-3 in other apps. ui-rules §13 says build is integer bumped manually per release. Confirm history shows manual increments tied to meaningful releases; if any automation is bumping it, remove. If legitimately at 40, no action needed.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 git log confirms APP_BUILD is manually bumped
- [x] #2 Document policy in wlmonitor/CLAUDE.md if missing
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
Verification task — likely no code change needed.

1. Check git log for `APP_BUILD` bumps in `inc/initialize.php` (or wherever the constant lives):
   ```
   git log -p -- '**/initialize.php' | grep -E '^\+.*APP_BUILD' | head -50
   ```
   Expect to see discrete manual increments tied to commit messages about releases, not auto-bumps.

2. Check deploy scripts (`deploy.sh`, `mcp/deploy.py`, `mcp/generate.py`) for any code that mutates `APP_BUILD` — there should be none. If found, that's the automation to remove.

3. If APP_BUILD=40 is the result of 40 manual bumps over wlmonitor's history: no action. Add a one-liner to wlmonitor/CLAUDE.md under "Versioning" (or create the section):
   > APP_BUILD is manually incremented on meaningful releases. It is NOT a date or an automation counter. See ui-rules §13.

4. If automation IS bumping it: remove the automation (likely a stray line in deploy.sh), then leave APP_BUILD at whatever value it's at — don't reset.

**Verification:** git log of the constant shows human-authored commits with release-style messages. No CI or deploy script references it.
<!-- SECTION:PLAN:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Verified via git log: APP_BUILD has 20+ discrete manual increments across the project history, all authored in feature/fix/chore commits with non-sequential numbers (9→19, 25→27, 28→31, etc.) — consistent with human session-by-session bumping. No deploy script (deploy.sh, mcp/) touches APP_BUILD. Added versioning policy note to CLAUDE.md.
<!-- SECTION:FINAL_SUMMARY:END -->
