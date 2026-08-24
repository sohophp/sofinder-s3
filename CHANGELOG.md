# Changelog

## Unreleased

- Add a dotenv-backed external provider smoke contract with prefix isolation
  and version-aware cleanup.
- Verify Backblaze B2 CRUD compatibility and automatically omit the unsupported
  `If-None-Match` Put Object header for `*.backblazeb2.com` endpoints.
- Allow other S3-compatible providers to disable conditional writes explicitly.

## 0.1.0-beta.1 - 2026-08-24

- Add the explicit `s3` SoFinder storage factory backed by AWS SDK for PHP v3.
- Support AWS S3, Cloudflare R2 and MinIO endpoint configuration, root-prefix
  isolation, cursor listings, stream I/O, virtual folders and public/CDN URLs.
- Add bounded directory copy and move with rollback-before-delete safety,
  permanent deletion, usage scans and adapter-specific security auditing.
- Add unit tests and a MinIO contract suite covering Unicode keys, more than
  1,000 objects, cursor pagination, recursive limits and rollback behavior.
