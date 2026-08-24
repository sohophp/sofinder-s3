# SoFinder S3 adapter

This optional bundle adds an `s3` storage adapter to SoFinder through AWS SDK
for PHP v3.

```bash
composer require sohophp/sofinder-s3:^0.1@beta
```

Register `SoFinderS3Bundle` after `SoFinderBundle` in `config/bundles.php`:

```php
return [
    SohoPHP\SoFinder\SoFinderBundle::class => ['all' => true],
    SohoPHP\SoFinderS3\SoFinderS3Bundle::class => ['all' => true],
];
```

Then configure a resource:

```yaml
so_finder:
  resources:
    RemoteAssets:
      adapter: s3
      root: media
      delivery_mode: proxy
      public_url: ''
      options:
        bucket: '%env(SOFINDER_S3_BUCKET)%'
        region: '%env(SOFINDER_S3_REGION)%'
        endpoint: '%env(SOFINDER_S3_ENDPOINT)%'
        use_path_style_endpoint: false
```

Omit `endpoint` for AWS S3. Use `region: auto` plus the account endpoint for
Cloudflare R2. MinIO normally requires `use_path_style_endpoint: true`; HTTP is
rejected unless `allow_insecure_endpoint: true` is explicitly set for a trusted
development network. Set `root: /` to expose the bucket root.

The AWS default credential provider chain is used unless `access_key_id` and
`secret_access_key` are both supplied through environment-backed configuration.
Never commit credentials. Private resources should use proxy delivery. A public
resource may configure `public_url` as the CDN or bucket URL corresponding to
the configured root. Set `delivery_mode: public` to expose that URL in browser
entries, copied links and editor selections. The adapter appends the logical
object path, so a resource rooted at `component-images` should use a base such
as `https://cdn.example.com/component-images`. Keep `delivery_mode: proxy` and
`public_url: ''` for private objects that must remain behind SoFinder's
authenticated content endpoint.

S3 deletion is permanent from SoFinder's perspective. Enable bucket versioning
or provider lifecycle protection when recovery is required.

## Provider status

| Provider | Status | Notes |
| --- | --- | --- |
| AWS S3 | Supported | Omit the custom endpoint. |
| Cloudflare R2 | Supported | Use the account endpoint and `region: auto`. |
| MinIO | CI verified | Use path-style URLs; allow HTTP only on a trusted development network. |
| Backblaze B2 | Contract verified | Use its regional HTTPS endpoint; conditional Put Object is disabled automatically. |
| DigitalOcean Spaces, Wasabi | Compatible candidates | Use their S3 endpoint; treat as unverified until the contract suite passes in your environment. |

## Backblaze B2 smoke test

Backblaze B2 can be tested directly from this repository; Winstar is not
required. Use an existing non-production bucket and a manually created B2
application key. The key ID is the access key ID, and the application key is
the secret access key. Do not use the master application key or commit values
to this repository.

Copy the safe template once, fill `.env.local`, then run the single external
test. Symfony Dotenv loads this file automatically for the test process;
`.env.local` is ignored by Git and must never be committed:

```bash
cd packages/sofinder-s3
cp .env.example .env.local
# Edit .env.local with the B2 endpoint, region, test bucket and application key.
vendor/bin/phpunit --filter S3ProviderSmokeTest
```

The test does not create a bucket. It writes only beneath a random
`<SOFINDER_PROVIDER_PREFIX>/provider-smoke/<random>` prefix, checks Unicode
stream CRUD, listing, copy, move, usage, permanent deletion and adapter audit,
then performs best-effort cleanup. Set `SOFINDER_PROVIDER_PREFIX` to the same
prefix allowed by a prefix-restricted application key; leave it empty only for
a bucket-wide key. Keep `SOFINDER_PROVIDER_USE_PATH_STYLE_ENDPOINT` unset for
B2. If the application key is restricted to one bucket, enable B2's
list-all-bucket-names permission for SDK compatibility. B2 buckets are always
versioned, so final cleanup lists versions only inside the random prefix and
deletes them by version ID. The application key therefore needs
list/read/write/delete access; configure an appropriate lifecycle rule as a
fallback for retained versions.

Backblaze B2 does not implement `If-None-Match` for Put Object. The adapter
recognizes `*.backblazeb2.com` endpoints and omits that conditional header
after performing its normal existence check. AWS S3 and MinIO retain atomic
conditional creation. Other compatible providers can set
`options.conditional_writes: false` explicitly when they have the same API
limitation; disabling it introduces a small concurrent-create race window.
