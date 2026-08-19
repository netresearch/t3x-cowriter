# Execution Plans

Working directory for agent execution plans (multi-step changes, refactors, upgrades).

- `active/` — plans currently being executed
- `completed/` — finished plans kept for reference

Keep plans short: goal, ordered steps, verification commands. Delete or move to `completed/` when done. This directory is part of the agent-harness structure verified by `Build/Scripts/verify-harness.sh`.
