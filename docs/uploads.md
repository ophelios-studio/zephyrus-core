# Uploads

Zephyrus provides two core upload primitives:

- `FileUpload`: immutable value object wrapping a single `$_FILES` entry
- `Uploader`: filesystem persistence service with path-safety checks

## Building `FileUpload`

```php
use Zephyrus\Upload\FileUpload;

$upload = FileUpload::fromPhpArray($_FILES['avatar']);
```

For fields that may contain one-or-many files, use `listFromPhpArray()`.
It accepts single, `[]` multi, and nested `foo[bar][]` array shapes from PHP.

```php
/** @var list<FileUpload> $uploads */
$uploads = FileUpload::listFromPhpArray($_FILES['attachments']);
```

## Persisting uploads

```php
use Zephyrus\Upload\Uploader;

$uploader = new Uploader(
    destinationRoot: __DIR__ . '/../storage/uploads',
    allowedExtensions: ['jpg', 'png', 'webp'],
    allowedMimeTypes: ['image/jpeg', 'image/png', 'image/webp'],
    maxSizeBytes: 5 * 1024 * 1024,
);

$relativePath = $uploader->store($upload, 'avatars');
```

`store()` returns a path relative to the destination root (for example `avatars/a1b2c3...jpg`).

### Batch upload

`storeMany()` accepts a `list<FileUpload>` and returns paths in the same order.
Processing stops on the first failure and throws the same `UploadException` as `store()`.

```php
/** @var list<string> $paths */
$paths = $uploader->storeMany(
    FileUpload::listFromPhpArray($_FILES['photos']),
    'photos',
);
```

An empty list returns an empty array immediately.

## Request normalization

`Request::fromGlobals()` normalizes `$_FILES` entries into `FileUpload` objects:

- single file fields are exposed via `Request::file('field')`
- multi-file fields are exposed via `Request::filesOf('field')`
- malformed file entries are skipped instead of crashing request construction

## Security behavior

- Rejects invalid upload error codes
- Rejects `..` traversal attempts in sub-directories or target filenames
- Uses `move_uploaded_file()` first, then `rename()` as a test-friendly fallback
- Enforces optional constraints:
  - extension allowlist
  - MIME type allowlist
  - max file size
