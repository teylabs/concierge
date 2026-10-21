# Membership and invitation scenarios

Each row is one scenario, the behaviour concierge promises, and the test that proves it (`file::test`). Rows marked **planned** are not built yet. Workspace mode runs with Spatie teams on; app-wide mode (`tests/AppMode`) runs with teams off.

## Invitations

| Scenario | Desired behaviour | Test |
| --- | --- | --- |
| Invite an email | One pending invitation per email and workspace; only a token hash is stored | `tests/Feature/InvitationLifecycleTest.php::stores only a hash of the token and scopes invitations to the workspace` |
| Invite app-wide | No workspace; one pending invitation per email | `tests/AppMode/InvitationTest.php::issues invitations with no workspace and one pending per email` |
| Invite an active or suspended member | Refused; a suspended member is reinstated instead | `tests/Feature/InvitationLifecycleTest.php::refuses to invite active or suspended members` |
| Accept with the right account | Joins with exactly the invited roles | `tests/Feature/InvitationLifecycleTest.php::lets an existing account accept the exact invitation with its roles` |
| Accept app-wide | Roles go to the account; idempotent | `tests/AppMode/InvitationTest.php::grants the roles to the account on acceptance and is idempotent` |
| Accept twice | Idempotent for the accepting user | `tests/Feature/InvitationLifecycleTest.php::is idempotent for retries by the accepting user` |
| Accept with the wrong or an unverified account | Refused; nothing written | `tests/Feature/InvitationLifecycleTest.php::rejects the wrong account and unverified accounts without writing anything` |
| Decline | There is no decline action: an unaccepted invitation expires or is revoked | `tests/Feature/InvitationLifecycleTest.php::rejects expired invitations without persisting the expiry` |
| Revoke | The link dies at once | `tests/Feature/InvitationLifecycleTest.php::kills revoked and rotated links` |
| Revoke app-wide | Idempotent; frees the pending email | `tests/AppMode/InvitationTest.php::revokes an app-wide invitation idempotently and frees its pending email` |
| Resend | New token and expiry; the old link dies | `tests/Feature/InvitationLifecycleTest.php::kills revoked and rotated links` |
| Resend cooldown | A second resend within the cooldown is refused | `tests/Feature/InvitationLifecycleTest.php::enforces a resend cooldown` |
| Resend app-wide | Re-captures grants under the current manager | `tests/AppMode/InvitationTest.php::resends under the current manager with a new token, expiry and grant snapshot` |
| Expiry | Expired links are refused; reissuing lazily expires the old one | `tests/Feature/InvitationLifecycleTest.php::lazily expires a stale pending invitation when reissuing` |
| Inviter lost authority | Acceptance refused | `tests/Feature/InvitationLifecycleTest.php::revalidates inviter authority at acceptance` |
| Grants changed since issue | Acceptance refused | `tests/Feature/InvitationLifecycleTest.php::rejects grants whose roles gained permissions or were recreated after issuance` |
| Workspace not accepting members | Acceptance refused | `tests/Feature/InvitationLifecycleTest.php::rejects acceptance when the workspace no longer accepts members` |
| Unknown token | Refused | `tests/Feature/InvitationLifecycleTest.php::rejects unknown tokens` |
| Delivery | Queued, encrypted, only with a host URL; stale deliveries dropped | `tests/Feature/DeliveryAndInspectionTest.php::drops stale deliveries after rotation or revocation` |
| Register through an invitation | The register link remembers the token | `tests/Fortify/RememberedInvitationTest.php::remembers the token from the register link and registers through the unchanged form` |
| Switch account: signed in as another email | The page says who the invitation is for and who is signed in, with Switch account and Cancel | planned |
| Switch account: sign in after switching | Sign-out keeps a signed, short-lived handoff; sign-in returns to the invitation | planned |
| Switch account: register after switching | Registration returns to the invitation | planned |
| Signed out with an existing account | Sign in, then return to the invitation | planned |
| Switch account: expired or revoked after the switch | The returned page shows expired or revoked | planned |
| Switch account: tampered or stale handoff | Ignored; no redirect | planned |
| Copy link | Rotates the token without email; the old link dies; inviters only | planned |
| Invitation message | An optional message of up to 500 characters on invite and resend, shown on rows and passed to delivery | planned |
| Change roles of a pending invitation | Without revoke and reissue | planned (part B) |
| Invite rate limiters | Named limiters for invite, inspect and accept | planned (part B) |
| Bulk invite | Skips emails with a pending invitation | planned (part B) |
| Prune expired invitations | A command deletes old expired invitations | planned (part B) |
| Email +alias policy | Opt-in alias folding; distinct by default | planned (part B) |
| SSO or social accept | Requires a verified provider email | planned (part B) |
| Mixed-case email | Matches end to end | planned (part B) |

## Membership

| Scenario | Desired behaviour | Test |
| --- | --- | --- |
| Rank rules | Admins grant presenter, never admin or owner | `tests/Feature/MembershipManagementTest.php::lets admins grant presenter but never admin or owner` |
| Rank rules app-wide | The same, with teams off | `tests/AppMode/OwnershipTest.php::lets admins grant presenter but never admin or owner` |
| Protect owner, equals and self | Refused | `tests/Feature/MembershipManagementTest.php::protects the owner, equal ranks and the actor themselves` |
| Suspend and reinstate | Roles are kept | `tests/Feature/MembershipManagementTest.php::suspends and reinstates without losing roles` |
| Suspend and reinstate row actions | Offered by rank, with confirms | `tests/Feature/MembersIndexTest.php::swaps suspend for reinstate, shows removed members only when asked, and confirms the destructive actions` |
| Suspension blocks invitations | A suspended member cannot accept their way back | `tests/Feature/InvitationLifecycleTest.php::never lets an invitation bypass a suspension` |
| Remove | Ends one workspace only | `tests/Feature/MembershipManagementTest.php::removes membership in one workspace only` |
| Rejoin after removal | Through a fresh invitation | `tests/Feature/InvitationLifecycleTest.php::lets a removed member rejoin through a fresh invitation` |
| Leave | The member removes themselves | `tests/Feature/LeaveWorkspaceTest.php::lets a member leave: removed by themselves, roles and permissions gone, the user and other workspaces untouched` |
| Owner leaves | Refused until ownership is transferred | `tests/Feature/LeaveWorkspaceTest.php::refuses the owner: ownership is transferred first, and then they can leave` |
| Leave app-wide | Refused: a workspace action | `tests/AppMode/LeaveWorkspaceTest.php::is a workspace action: app-wide mode, with no workspaces and Spatie teams off, refuses it` |
| Transfer ownership | The old owner is demoted | `tests/Feature/OwnershipTest.php::transfers ownership and demotes the previous owner` |
| Transfer needs recent authentication | Refused without it | `tests/Feature/OwnershipTest.php::requires recent authentication` |
| Tenant isolation | Managers of one workspace cannot touch another | `tests/Feature/MembershipManagementTest.php::isolates tenants: managers of one workspace cannot touch another` |
| Last owner app-wide | Cannot be removed or demoted | `tests/AppMode/OwnershipTest.php::protects the last owner from removal and demotion` |
| Deactivate and restore | Sessions end; roles are kept | `tests/AppMode/DeactivationTest.php::restores a user with their roles and clears the deactivation record` |
| Deactivate in workspace mode | Memberships and roles untouched | `tests/Feature/UserDeactivationTest.php::deactivates and restores a user in workspace mode, leaving memberships and roles alone` |
| User deletion app-wide | Last-owner guard; soft delete keeps the account row | `tests/AppMode/DeletionTest.php::lets an owner with a co-owner delete their account, and refuses the last owner` |
| Workspace unavailable after remove, leave or suspend | The current workspace is repointed and the user lands on the no-workspace route | planned |
| A workspace that never existed for the user | 404 | planned |
| User deletion in workspace mode | Last-owner guard, membership cleanup, soft delete and restore | planned (part B) |
| Re-invite a deactivated user in workspace mode | Covered end to end | planned (part B) |
| Pending invitations when a workspace becomes unavailable | Covered end to end | planned (part B) |
