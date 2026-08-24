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
the configured root.

S3 deletion is permanent from SoFinder's perspective. Enable bucket versioning
or provider lifecycle protection when recovery is required.

## Provider status

| Provider | Status | Notes |
| --- | --- | --- |
| AWS S3 | Supported | Omit the custom endpoint. |
| Cloudflare R2 | Supported | Use the account endpoint and `region: auto`. |
| MinIO | CI verified | Use path-style URLs; allow HTTP only on a trusted development network. |
| DigitalOcean Spaces, Wasabi, Backblaze B2 | Compatible candidates | Use their S3 endpoint; treat as unverified until the contract suite passes in your environment. |
