# Changelog

Notable changes to Osoni are recorded here.

## Unreleased

### Added

- Read `.xlsx` workbooks with the native `ExcelReader`, select worksheets,
  handle headers, and convert workbook data through the existing `Data`
  writers.
- Generate parameterized SQL insert statements with
  `Data::insertStatements()`.
- Generate a migration from existing table structures using
  `php osoni make:migration:import <name>`. The importer supports SQLite,
  MySQL, PostgreSQL, and SQL Server; it excludes Osoni's migration ledger,
  copies no row data, and does not change the source database. Generated DDL
  is driver-specific and should be reviewed before applying to a fresh
  database.
