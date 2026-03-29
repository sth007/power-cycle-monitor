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
- foundation for pattern recognition and remaining-time prediction

## Setup
1. Copy `config/app.sample.php` to `config/app.php`
2. Enter database credentials
3. Import `sql/schema.sql`
4. Point the web server to `public/`
