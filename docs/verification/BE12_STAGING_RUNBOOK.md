# BE-12 Isolated Staging Runbook

**Status: PREPARATION ONLY — NOT AUTHORIZED TO ACTIVATE YET**
**Feature:** `FEATURE_SHIFT_DID_NOT_WORK`
**Expected production default:** OFF
**Pinned backend candidate:** `54cb948c6519734faae1171a13e4ead6ddeb4df8`

Mahmoud authorized an isolated Staging experiment, but the target environment, change window, and executing/rollback operator are not yet identified. Do not activate Staging or send requests until the fields below are completed and re-authorized by the responsible operator. Production stays OFF.

## Change record required before starting

| Field | Value to record |
|---|---|
| Staging environment/project/region | `<exact identity>` |
| API base URL | `<HTTPS Staging URL>` |
| Tenant/branch and three disposable shift fixtures | `<IDs only; no PII; eligible non-future shifts>` |
| Deployed backend SHA | Must be `54cb948c6519734faae1171a13e4ead6ddeb4df8`; stop if different |
| Authorization/ticket reference | `<Mahmoud approval record>` |
| Window start/end and timezone | `<approved interval>` |
| Executing operator | `<name/role>` |
| Rollback owner and contact path | `<name/on-call channel>` |
| DB identity | `<confirmed non-production host/schema>` |
| Credential injection mechanism | `<approved secret manager; do not paste tokens/passwords>` |
| Rollback trigger | Any unexpected target/SHA, HTTP response, DB mutation, lock/latency issue, or uncertainty |

## Preconditions

- Verify this is the named Staging environment; verify production is not the target.
- Verify deployed backend SHA and build/runtime identity.
- Confirm the three fixtures belong only to an isolated staging tenant/branch and can be deleted by the approved cleanup path.
- Use only a valid eligible shift whose business date is today or earlier in the approved business timezone. Verify it has no submitted report, count, or other disqualifying evidence. Do not use a future-dated shift or rely on an unresolved P-01 future-date policy decision.
- Confirm effective `FEATURE_SHIFT_DID_NOT_WORK=false`, config-cache state, database identity, and rollback owner availability.
- Establish a baseline snapshot for each shift status, not-worked history, and idempotency rows.
- Inject an approved least-privilege test identity locally. Never expose bearer tokens, cookies, passwords, or secret environment output in logs.
- Agree the operator may set the Staging flag ON only for this time window, then immediately restore OFF. This does not authorize Production.

## Execution sequence — do not execute until preconditions are signed off

Use a distinct disposable shift and a fresh idempotency key in each phase so a replay cannot mask the flag state.

1. **OFF baseline:** with flag OFF, send an authenticated `POST /api/v1/branch-manager/shifts/{off_fixture_id}/mark-not-worked` with JSON `{"reason":"<approved bounded test reason>"}` and a fresh `Idempotency-Key`. Expected response: HTTP `404`, code `SHIFT_NOT_WORKED_FEATURE_DISABLED`. Verify status, history, and idempotency state match baseline.
2. **Enable Staging only:** operator sets `FEATURE_SHIFT_DID_NOT_WORK=true` using the approved Staging config mechanism. Perform the platform’s documented config-cache refresh/restart. Verify the effective value and target identity before continuing.
3. **ON request:** send the same route to the separate ON fixture with a new key. Expected response: HTTP `200` with successful result. Verify one no-work history/audit record and expected canceled shift state; preserve full before/after state.
4. **Rollback to OFF:** immediately restore Staging config to `false`, refresh/restart as required, and verify effective OFF. Do not proceed if this rollback cannot be confirmed.
5. **OFF after rollback:** send the route to the third, untouched fixture with another new key. Expected response again: HTTP `404`, code `SHIFT_NOT_WORKED_FEATURE_DISABLED`; verify no row/status/idempotency mutation for this fixture.
6. **Cleanup and evidence:** delete only the three disposable fixtures by the approved cleanup method. Save redacted request/response records, timestamps, correlation IDs, status codes/bodies, database snapshots/counts, effective flag values, config-cache actions, deployed SHA, operator and rollback-owner confirmation.

## Commands to execute from the authorized operator’s local session

No executable command is issued from this report because the environment and window are not yet named. After the change record is approved, use the operator’s protected HTTP client/secret injection and record the equivalent of:

```text
POST {STAGING_API_BASE}/api/v1/branch-manager/shifts/{fixture_id}/mark-not-worked
Authorization: Bearer <injected at runtime; redact from logs>
Idempotency-Key: <new UUID per phase>
Content-Type: application/json

{"reason":"<approved bounded test reason>"}
```

Capture HTTP status and response code/body with authorization headers removed. Do not put a real credential in shell history or this runbook.

## Stop and rollback

If any request or state differs from expectation, stop new requests, restore Staging to OFF immediately, apply the documented cache/restart action, verify effective OFF with an approved config read, notify the rollback owner, and preserve redacted evidence. Production remains OFF throughout.

## Evidence decision

Until all required change-record fields are populated and the sequence is run successfully, Staging HTTP proof and rollback are **BLOCKED — AUTHORIZATION / ENVIRONMENT**. A config file, PHPUnit test, or `.env.example` does not count as Staging runtime evidence.
