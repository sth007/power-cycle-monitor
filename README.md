# power-cycle-monitor

PHP project for:
- reading appliance power data from MySQL
- detecting power cycles
- storing detected cycles in separate tables
- showing calendar-based history
- visualizing cycle charts
- estimating remaining runtime based on historical patterns

## Features
- incremental history processing
- cycle detection with idle-gap logic
- calendar overview
- daily cycle listing
- chart visualization
- remaining-time estimation for currently running cycles based on similar historical runs

## Setup
1. Copy `config/app.sample.php` to `config/app.php`
2. Enter database credentials
3. Import `sql/schema.sql`
4. Point the web server to `public/`

## Runtime prediction
- running cycles are recognized from the carry state in `history_processing_state`
- the app compares the current partial run with historical finished cycles of the same device
- it estimates total duration and remaining minutes once enough data and history are available
