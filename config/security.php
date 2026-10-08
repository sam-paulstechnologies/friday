<?php

return [
    // Empty or malformed provisioning grants no access. Workspace ownership is unrelated.
    'platform_admin_user_ids' => array_values(array_unique(array_map('intval', array_filter(
        array_map('trim', explode(',', (string) env('PLATFORM_ADMIN_USER_IDS', ''))),
        fn (string $id): bool => ctype_digit($id) && (int) $id > 0,
    )))),
];
