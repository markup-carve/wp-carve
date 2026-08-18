# Git-backed publishing exploration

Git-backed publishing is promising, but it should not be wired directly into
post saves. Network failures, concurrent edits, credentials, and webhook replay
make that path unsafe. The recommended boundary is an optional adapter whose
operations are explicit, previewable, and restartable.

## Proposed model

- A mapping connects one post to a repository, branch, and validated relative
  `.crv` path.
- Pull presents a semantic AST diff before changing the post.
- Push creates a commit on a dedicated branch and opens or updates a pull
  request. It never commits to the base branch directly.
- The last synchronized blob SHA is stored with the mapping. Remote drift blocks
  push and opens a three-way comparison.
- Webhooks enqueue pulls. Signature checks, delivery replay protection,
  repository allowlisting, and capability checks are mandatory.

Prefer a narrowly scoped GitHub App installation token supplied by the host.
Never store a personal token in post meta, block attributes, or an export.

Start with read-only public-repository import: fetch on demand, show the semantic
diff, and update only after confirmation. Add pull-request publishing after the
read path has tests for traversal, redirects, rate limiting, stale SHAs, and
webhook replay. The existing import/export and migration services should remain
the canonical conversion layer.

