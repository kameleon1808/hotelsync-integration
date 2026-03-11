# HotelSync Integration – Project Brief

## Project Overview
Integration service between HotelSync API and local BridgeOne system.
Built with PHP (procedural), MySQL, mysqli, cURL. No frameworks allowed.

## Tech Constraints
- PHP procedural only (no Laravel, Symfony, CodeIgniter)
- mysqli only (no PDO)
- cURL for HTTP requests
- No HTTP/DB abstraction frameworks

## API
- Base URL: https://app.otasync.me/api
- Docs: https://documenter.getpostman.com/view/41568417/2sAYX5MNgD
- Test token: 775580f2b13be0215b5aee08a17c7aa892ece321
- **Primary API reference: `postman/otasync-api.json`** – full exported Postman
  collection containing every endpoint with confirmed request bodies and field
  names. Always consult this file first when implementing new endpoints,
  mapping API fields, or debugging unexpected responses. The online Postman
  documenter is a JavaScript SPA and cannot be fetched programmatically.

## Project Structure
hotelsync-integration/
├── config/
│   └── config.php
├── src/
│   ├── db.php
│   ├── logger.php
│   ├── api.php
│   └── helpers.php
├── scripts/
│   ├── sync_catalog.php
│   ├── sync_reservations.php
│   ├── update_reservation.php
│   └── generate_invoice.php
├── public/
│   └── webhooks/
│       └── otasync.php
├── sql/
│   └── schema.sql
├── logs/
│   └── .gitkeep
├── Docs/
│   ├── architecture.md
│   ├── database-schema.md
│   ├── api-integration.md
│   └── testing/
│       ├── phase-0-test-plan.md
│       ├── phase-1-test-plan.md
│       ├── phase-2-test-plan.md
│       ├── phase-3-test-plan.md
│       ├── phase-4-test-plan.md
│       └── phase-5-test-plan.md
├── .gitignore
├── CLAUDE.md
└── README.md

## Coding Rules
- All code and comments must be in English
- All documentation goes in /Docs directory
- Every function must have a docblock comment
- Log every significant action via src/logger.php
- Never hardcode credentials – use config/config.php
- Every script must output clear success/error messages to CLI