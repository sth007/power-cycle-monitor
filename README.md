# power-cycle-monitor

PHP project for:
- reading appliance power data from MySQL
- detecting power cycles
- storing detected cycles in separate tables
- building normalized 60-step cycle profiles
- showing calendar-based history
- visualizing cycle charts
- estimating remaining runtime based on historical patterns

## Features
- incremental history processing
- cycle detection with idle-gap logic
- normalized 60-section pattern profiles per closed cycle
- calendar overview
- daily cycle listing
- live view for running programs
- chart visualization
- remaining-time estimation for currently running cycles based on similar historical runs

## Setup
1. Copy `config/app.sample.php` to `config/app.php`
2. Enter database credentials
3. Import `sql/schema.sql`
4. Point the web server to `public/`

## Runtime prediction
- closed cycles are stored as normalized 60-point profiles in `cycle_patterns`
- running cycles are recognized from the carry state in `history_processing_state`
- the app compares the current partial profile with historical finished profiles of the same device
- matching uses curve shape, energy, peak power and elapsed runtime
- `public/live.php` shows status, profile type, estimated total duration, elapsed time, remaining minutes and confidence
