# Phase 1 global AI settings security

Branch: codex/jarvis-p2-ai-security. Base: 78527ef4a00ab8bd906f0514bbe86ff5a0b0aa87.

The manageGlobalAiSettings Gate denies by default. config/security.php accepts only explicit positive PLATFORM_ADMIN_USER_IDS; workspace ownership, registration and client-submitted roles do not grant platform privileges. Operators must provision designated IDs through an approved deployment. No credentials or administrator IDs were changed here.

GET settings.ai.edit and PATCH settings.ai.update (including test_connection) enforce the Gate in routes and controller. Updates and connection tests require recent password confirmation; absent, expired and future timestamps fail closed. Sidebar navigation hides unauthorized settings. The page links administrators to password confirmation before entering a key.

Keys remain encrypted at rest and hidden in serialized models. Validation, save and test responses clear old input. Provider response bodies/exceptions are never returned or logged by this controller. Whitelisted global changes produce a sanitized structured security log with actor, record, credential-replaced boolean and model names; the existing workspace-only audit_logs schema cannot correctly represent a global change without a schema extension. Central log retention remains an operator prerequisite.

Verification: synthetic .env.testing; SQLite :memory:; array mail/cache/session; no live providers. All connection tests use HTTP fakes and reject stray HTTP. Final command: php vendor/bin/phpunit --filter 'AiSettingsTest|GlobalAiSettingsAuthorizationTest|AuthenticationTest|PasswordConfirmationTest|RegistrationTest' --log-junit test-results/p2-final.xml.

Final results: 30 passed, 0 failed/errors/skipped, 120 assertions. New authorization suite has 15 cases; existing settings/authentication/password/registration contracts contribute 15. npm run build and Pint on all changed PHP passed; git diff --check passed.

Earlier expanded run: 29 passed / 1 error caused by the new fixture mistakenly requesting a nonexistent WorkspaceFactory. The fixture now creates its synthetic workspace explicitly; the complete rerun passed. No tests were suppressed.

Security/deployment review: no migration, provider call, credential provisioning, production operation or privilege inferred from enrollment. Requires review, integrated CI and explicit release approval. Configuration starts with zero administrators, deliberately blocking global settings until an operator designates them. Wider registration/verification and global-audit schema decisions remain outstanding.
