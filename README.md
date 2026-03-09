# OHIP External References Exporter

This app uses the same Docker, Nginx, and PHP-FPM structure as the working OHIP app.

## What this app does

1. Calls `GET /rsv/v1/hotels/{hotelId}/reservations` using `limit=200` and `offset`
2. Collects the reservation id from `reservationIdList[]` where `type = Reservation`
3. Calls `GET /rsv/v1/hotels/{hotelId}/reservations/{reservationId}` for each reservation
4. Reads `externalReferences[]`
5. Exports the values for:
   - `ERP`
   - `IMPORTCNF`

## UI features

- Start export
- Live progress bar
- Processed / Total counter
- Success counter
- Failed counter
- Current reservation id
- Estimated time remaining
- Cancel button
- Download partial or complete CSV

## Output CSV

Columns:
- `reservationId`
- `ERP`
- `IMPORTCNF`

## Port

This package maps host port `8091` to the internal app port `8080`.

Open the app at:
- `http://YOUR_SERVER:8091/`

## Important

This package does **not** include the previous Company profile attach flow.
It does **not** use:
- `reservationProfiles`
- `attachedProfiles`
- `roomStay`
- company-link update payloads

It is only for exporting reservation external references.


## Discovery pagination safeguard

This build stops discovery when OHIP keeps returning full pages but no new reservation ids are added.
That prevents infinite discovery loops when the API repeats pages after a certain offset.

## Partial download behavior

- If export rows exist, Download CSV returns:
  - `external_references_completed.csv`
- If you cancel during discovery before export rows exist, Download CSV returns:
  - `discovered_reservation_ids.csv`
