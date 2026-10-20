# Contributing

1. Fork and create a branch from `main`.
2. `composer install`
3. Make your change with tests.
4. Make sure these pass:

   ```bash
   composer test      # Pest
   composer analyse   # Larastan
   composer lint      # Pint (run `composer format` to fix)
   ```

5. Add an entry under **Unreleased** in `CHANGELOG.md` for user-visible changes.
6. Open a pull request.

Rules:

- Never call the real Razorpay API in `tests/Unit` or `tests/Feature`; use `Http::fake()` with fixtures in `tests/Fixtures/`.
- Money is integer paise. No floats.
- No new runtime dependencies without discussing in an issue first.
