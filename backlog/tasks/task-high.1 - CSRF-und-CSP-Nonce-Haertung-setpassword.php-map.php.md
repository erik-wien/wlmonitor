---
id: TASK-HIGH.1
title: 'CSRF- und CSP-Nonce-Haertung: setpassword.php, map.php'
status: Done
assignee: []
created_date: '2026-07-12 11:04'
updated_date: '2026-07-12 15:57'
labels:
  - audit-2026-07-12
  - security
dependencies: []
parent_task_id: TASK-HIGH
ordinal: 2000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Audit 2026-07-12 (S3/S6, Bericht: /Users/erikr/Git/mcp/docs/2026-07-12-suite-konsistenz-audit.md). web/setpassword.php:26 verarbeitet einen state-changing POST OHNE csrf_verify() (nur Reset-Token-Schutz). web/map.php:16 hat ein Inline-<script> OHNE CSP-Nonce (Datei nirgends verlinkt, Google-Maps-Legacy).
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 setpassword.php ruft csrf_verify() vor der Passwort-Aenderung; Reset-Flow weiter funktionsfaehig
- [ ] #2 map.php: Nonce ergaenzt ODER Datei entfernt (falls verifiziert tot)
<!-- AC:END -->
