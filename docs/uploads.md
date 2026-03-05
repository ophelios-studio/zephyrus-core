# Uploads

Zephyrus provides two core upload primitives:

- `FileUpload`: immutable value object wrapping a single `$_FILES` entry
- `Uploader`: filesystem persistence service with path-safety checks

## Building `FileUpload`

```php
use Zephyrus\Upload\FileUpload;

$upload = FileUpload::fromPhpArray($_FILES['avatar']);
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

## Security behavior

- Rejects invalid upload error codes
- Rejects `..` traversal attempts in sub-directories or target filenames
- Uses `move_uploaded_file()` first, then `rename()` as a test-friendly fallback
- Enforces optional constraints:
  - extension allowlist
  - MIME type allowlist
  - max file size
