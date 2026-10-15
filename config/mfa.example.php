<?php
declare(strict_types=1);

// Copy to mfa.local.php and replace the placeholder with a base64-encoded,
// cryptographically random 32-byte key. Never commit the local file.
return ['encryption_key' => 'REPLACE_WITH_BASE64_32_BYTE_KEY'];
