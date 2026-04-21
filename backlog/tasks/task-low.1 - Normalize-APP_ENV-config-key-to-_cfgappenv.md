---
id: TASK-LOW.1
title: 'Normalize APP_ENV config key to $_cfg[''app''][''env'']'
status: To Do
assignee: []
created_date: '2026-04-21 05:44'
updated_date: '2026-04-21 06:15'
labels: []
dependencies: []
parent_task_id: TASK-LOW
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Audit 2026-04-20: wlmonitor is the only app reading $_cfg['target'] with default 'local'. Every other app uses $_cfg['app']['env'] with default 'dev'. This complicates Chrome Footer::render() stage normalization. Pick the canonical key shape (likely $_cfg['app']['env']) and align. Coordinate with mcp/deploy.py's config generator if it writes the key.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 wlmonitor reads the same config key as other apps
- [ ] #2 mcp config generator writes the canonical key for all apps
- [ ] #3 Footer STAGE rendering correct on all environments (DEV local, PROD akadbrain/w4y)
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
Config key rename so wlmonitor matches the rest of the ecosystem.

1. **mcp/generate.py**: check what key it writes for wlmonitor vs other apps. If it writes `$_cfg['target']`, change to `$_cfg['app']['env']` for wlmonitor (and align defaults: 'local'→'dev' if the ecosystem standard is 'dev').

2. **wlmonitor/inc/initialize.php** (or wherever config is read): change `$_cfg['target']` → `$_cfg['app']['env']`. Update the default from 'local' to 'dev'.

3. **wlmonitor/inc/html_footer.php** (and/or Chrome footer call site): the STAGE normalization that maps config value → `DEV`/`PROD`. Ensure it handles both old `'local'` and new `'dev'` gracefully during transition.

4. **Deploy configs:** regenerate `config.yaml` for all three targets (local, akadbrain, world4you) via `python3 mcp/generate.py --app wlmonitor --target <t>`. Verify the generated key shape matches.

5. **Verification:**
   - Local (`.test`): footer shows `…DEV`.
   - After deploy to akadbrain: footer shows `…PROD` (akadbrain is pre-prod → PROD per §13 since it's the live target for Jardyx; confirm against other apps).
   - After deploy to world4you: footer shows `…PROD`.

6. **Coordination:** this touches `mcp/generate.py` — check if other apps reference the same generator code path. If wlmonitor has a special case now, the rename may remove it.

Keep low-priority — cosmetic drift only, but doing it now avoids rework when chrome footer migrates (chrome TASK-MEDIUM.1 assumes `$_cfg['app']['env']`).
<!-- SECTION:PLAN:END -->
