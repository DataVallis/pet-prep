# Shipped copy of docs/research/breed-facts (M5-R09b)

Read by `php artisan breeds:import-facts` (`BreedFactImportService::DEFAULT_DIR`). The
production image is built from `backend/` only, so the canonical files in
`docs/research/breed-facts/` are copied here **byte for byte**:

    cp docs/research/breed-facts/*.json backend/database/data/breed-facts/

Never edit the JSON here directly. `BreedFactImportTest` fails when the two folders differ.
Format and rules: `docs/research/breed-facts/README.md`.
