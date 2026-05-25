# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-05-25

### Added

- `ServerExportOperation` trait for Backpack CRUD controllers
- CSV formatter (native PHP, BOM UTF-8, semicolon separator)
- XLSX formatter (PhpSpreadsheet with styled headers)
- Markdown formatter (GFM table format)
- `ExportProgressTrackerInterface` for async job tracking
- `NullProgressTracker` as default (no-op) implementation
- Async export via Laravel queue jobs with chunked processing
- Sync export with direct file download for small datasets
- Automatic column resolution from CRUD definitions
- Support for all Backpack column types: text, closure, relationship, select, enum, select_from_array, custom_html, boolean, check, number, date, datetime, image, json, model_function
- ColVis integration: respects user column visibility choices
- Filter/search/sort preservation from DataTables state
- Configurable async threshold, chunk size, queue name, storage path
- Translations: English and French
- Publishable config, views, and translations
