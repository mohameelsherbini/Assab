# ASSAB ERP — S1-05 + S1-12 Implementation Authorization Record

Date: 2026-10-12

## Decision received from Mahmoud

**S1-05 + S1-12 IMPLEMENTATION AUTHORIZED**. This is an explicit, separate implementation authorization following ure approval. It authorizes local application code changes and local tests for the jointly implemented S1-05 security controls / S1-12 Backend lifecycle only.

## Immutable architecture reference

- Design Baseline: `ASSAB-S1-05-S1-12-ARCH-2026-10-12-v1`
- Backend repository: `mohameelsherbini/Assab`
- Foundation/base commit: `41f03a31929d29fdbb494ef906f8cbe80916c9ad`
- Local task branch: `task/s1-12-dashboard-auth`
- Source documents: `docs/sprint-01/s1-05-auth-security-blueprint-DRAFT.md` and `docs/sprint-01/s1-12-integration-contract-DRAFT.md`.
- The original baseline document's earlier wording saying implementation authorization is pending is historical. This **separate decision record supersedes that gate only**, without modifying the approved baseline's design requirements.

## Before work

Verify the local task branch name, HEAD/ancestry and Git status. Prior local report contained two untracked approved baseline documents, which means the working tree is not literally clean. Preserve and back up these files; do not delete, overwrite, stash, stage, or commit them simply to conceal status. Establish a genuinely clean pre-implementation tree or obtain an explicit exception before making code edits. Remote GitHub branch absence does not establish the local Windows branch state.

## Authorized scope

- Implement S1-05 security controls via S1-12 Backend in small testable slices using existing Laravel/Sanctum services.
- Admin-only session persistence; secure Login/2FA Remember Me; proper credential/session expiry; exact token abilities; atomic refresh / revocation; concurrency-safe User -> Session -> Token lock order; sessions compatibility; Admin-only enforcement behind compatibility/strict controls; local SQLite tests and actual MariaDB/InnoDB independent-connection concurrency evidence before acceptance.
- New family issuance must serialize with password change/reset, deactivation and membership eligibility invalidation; recheck live credential/version and eligibility under the User lock.
- FCM cleanup failure must not obstruct atomic session/token revocation.
- Fail-closed consumed refresh; 409 only for positively proven in-flight contention; ambiguous network/5xx is not automatic logout.
- D12 = SSO; D13 = Invitations; D14 = Impersonation. HOST-01 remains open and blocks S1-13 release approval, **not** authorized backend coding.
- Preserve legacy Admin in Compatibility Mode and Flutter/Cashier/BranchManager behavior. No global Sanctum expiration change.

## Excluded / separate gates

- No edit of Foundation/Main or Mahmoud's separate Business Rules/S1-01–S1-11 work; notify before changing shared files or going out of scope.
- No Flutter, Dashboard, financial logic, shared idempotency, or unrelated SSO/invitation/impersonation security remediation without separate approval.
- **No Commit, Push, PR, Merge, Rebase, Force Push.** Do not stage as a substitute for permission; no production strict activation or 15-minute user enforcement before S1-13, HOST-01, verified tests, independent audit and separate deployment authorization.
- S1-11 acceptance, technical acceptance of S1-12 and release approval are independent.

## Evidence and reporting

For each implementation slice: exact branch/HEAD, files touched, untracked baseline status, tests actually run with command/results, risks/open decisions, and readable diff or new-file contents. Independent code audit before requesting any Git-publishing authorization.

## Status at record creation

GitHub Foundation HEAD verified at `41f03a31929d29fdbb494ef906f8cbe80916c9ad`; the named task branch was not present on GitHub. **Local Windows branch and working-tree verification remains required. No implementation tests have been run by the author of this record.**
